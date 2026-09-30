<?php

declare(strict_types=1);

use App\Game\World;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Worker::$pidFile = storage_path('framework/world.pid');
Worker::$logFile = storage_path('logs/world.log');
Worker::$stdoutFile = storage_path('logs/world-stdout.log');

TcpConnection::$defaultMaxPackageSize = 4096;
TcpConnection::$defaultMaxSendBufferSize = 1048576;
$worker = new Worker('websocket://0.0.0.0:8081');
$worker->count = 1;
$worker->name = 'grim-hollow-world';
$world = new World;
$clients = [];
$worker->onConnect = function ($c) use (&$clients) {
    $clients[$c->id] = ['connected' => time(), 'authenticated' => false, 'last_seen' => time(), 'rate_time' => time(), 'rate' => 0];
};
$worker->onClose = function ($c) use (&$clients) {
    unset($clients[$c->id]);
};
$worker->onBufferFull = function ($c) {
    $c->close();
};
$worker->onMessage = function ($c, $raw) use (&$clients, $world, $worker) {
    try {
        $m = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($m) || ($m['v'] ?? null) !== 1) {
            throw new RuntimeException('unsupported_protocol');
        }
        $session = &$clients[$c->id];
        if ($session['rate_time'] !== time()) {
            $session['rate_time'] = time();
            $session['rate'] = 0;
        }
        if (++$session['rate'] > 20) {
            throw new RuntimeException('rate_limited');
        }
        $session['last_seen'] = time();
        if (! $session['authenticated']) {
            if (($m['type'] ?? '') !== 'hello' || ! is_string($m['ticket'] ?? null)) {
                throw new RuntimeException('hello_required');
            }
            $t = DB::transaction(function () use ($m) {
                $hash = hash('sha256', $m['ticket']);
                $t = DB::table('world_tickets')->where('hash', $hash)->lockForUpdate()->first();
                if (! $t || $t->expires_at <= now()->toDateTimeString()) {
                    throw new RuntimeException('invalid_ticket');
                }
                if (!$t->session_hash || !DB::table('game_sessions')->where('token_hash',$t->session_hash)->where('expires_at','>',now())->exists()) throw new RuntimeException('session_expired');
                DB::table('world_tickets')->where('hash', $hash)->delete();

                return $t;
            });
            foreach ($clients as $otherId => $other) {
                if ($otherId !== $c->id && ($other['character_id'] ?? null) === $t->character_id) {
                    $worker->connections[$otherId]?->close();
                }
            }
            $session += ['character_id' => $t->character_id, 'expedition_id' => $t->expedition_id,'session_hash'=>$t->session_hash,'checked_at'=>time()];
            $session['authenticated'] = true;
            $c->send(json_encode(['v' => 1, 'type' => 'welcome', 'tick_rate' => 10]));
            push($c, $session, $world->snapshot($t->expedition_id, $t->character_id));

            return;
        }
        if (($m['type'] ?? '') === 'ping') {
            $world->snapshot($session['expedition_id'], $session['character_id']);
            $c->send('{"v":1,"type":"pong"}');

            return;
        }
        if (($m['type'] ?? '') !== 'command' || ! Str::isUuid($m['command_id'] ?? '') || ! is_array($m['payload'] ?? null)) {
            throw new RuntimeException('invalid_command');
        }
        if (! is_string($m['payload']['action'] ?? null)) {
            throw new RuntimeException('invalid_action');
        }
        foreach (['direction', 'target_id', 'spell_id'] as $key) {
            if (isset($m['payload'][$key]) && ! is_string($m['payload'][$key])) {
                throw new RuntimeException('invalid_payload');
            }
        }
        $c->send(json_encode($world->command($session['expedition_id'], $session['character_id'], $m['command_id'], $m['payload'])));
        // Push the post-command state now, from memory, instead of waiting for the next tick.
        try {
            $row = $world->cached($session['expedition_id']);
            if ($row) {
                push($c, $session, $world->view($row, $session['expedition_id'], $session['character_id']));
            }
        } catch (Throwable $e) {
            report($e);
        }
    } catch (Throwable $e) {
        $c->send(json_encode(['v' => 1, 'type' => 'error', 'code' => 'request_rejected']));
        if (! ($clients[$c->id]['authenticated'] ?? false)) {
            $c->close();
        }
        report($e);
    }
};

/**
 * Sends a snapshot only when what the player sees has changed. The client extrapolates the tick
 * from its own clock, so an unchanged view is refreshed at most once per second.
 */
function push(TcpConnection $c, array &$session, array $view): void
{
    $world = $view['world'];
    unset($world['tick']);
    $hash = md5(json_encode([$world, $view['lobby'], $view['join_code']]));
    $now = microtime(true);
    if ($hash === ($session['view_hash'] ?? null) && $now - ($session['sent_at'] ?? 0) < 1.0) {
        return;
    }
    $c->send(json_encode($view, JSON_UNESCAPED_UNICODE));
    $session['view_hash'] = $hash;
    $session['sent_at'] = $now;
}

$worker->onWorkerStart = function () use ($world, $worker, &$clients) {
    $checkedAt = 0;
    Timer::add(0.1, function () use ($world, $worker, &$clients, &$checkedAt) {
        try {
            $world->tick();
            $live = [];
            foreach ($worker->connections as $c) {
                $s = $clients[$c->id] ?? null;
                if (! $s) {
                    continue;
                }
                if (! $s['authenticated']) {
                    if (time() - $s['connected'] > 5) {
                        $c->close();
                    }

                    continue;
                }
                if (time() - $s['last_seen'] > 30) {
                    $c->close();

                    continue;
                }
                $live[$c->id] = $c;
            }
            // Session and membership checks for every stream at once, every 5 seconds.
            if ($live && time() - $checkedAt >= 5) {
                $checkedAt = time();
                $hashes = array_map(fn ($c) => $clients[$c->id]['session_hash'], $live);
                $valid = DB::table('game_sessions')->whereIn('token_hash', array_unique($hashes))->where('expires_at', '>', now())->pluck('token_hash')->flip();
                $members = DB::table('expedition_members')->whereIn('character_id', array_unique(array_map(fn ($c) => $clients[$c->id]['character_id'], $live)))
                    ->get()->map(fn ($m) => $m->expedition_id.':'.$m->character_id)->flip();
                foreach ($live as $id => $c) {
                    $s = $clients[$id];
                    if (! isset($valid[$s['session_hash']]) || ! isset($members[$s['expedition_id'].':'.$s['character_id']])) {
                        $c->close();
                        unset($live[$id]);
                    }
                }
            }
            $rows = $world->refresh(array_map(fn ($c) => $clients[$c->id]['expedition_id'], $live));
            foreach ($live as $id => $c) {
                $s = &$clients[$id];
                $row = $rows[$s['expedition_id']] ?? null;
                if (! $row || ! isset($row['state']['players'][$s['character_id']])) {
                    unset($s);
                    $c->close();
                    continue;
                }
                push($c, $s, $world->view($row, $s['expedition_id'], $s['character_id']));
                unset($s);
            }
        } catch (Throwable $e) {
            report($e);
            foreach ($worker->connections as $c) {
                $c->send('{"v":1,"type":"server_paused"}');
            }
        }
    });
};
Worker::runAll();
