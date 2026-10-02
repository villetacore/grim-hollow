<?php

declare(strict_types=1);

use App\Game\PlazaHub;
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
$worker = new Worker('websocket://0.0.0.0:'.((int) (getenv('WORLD_PORT') ?: 8081)));
$worker->count = 1;
$worker->name = 'grim-hollow-world';
$world = new World;
$plaza = new PlazaHub;
$clients = [];

/**
 * Fields of the other party members a client that announced the "lean" capability receives:
 * everything it draws and targets. Their bags, cooldown tables and loot stay on the server.
 */
const LEAN_MEMBER = ['id'=>1, 'name'=>1, 'class_id'=>1, 'origin'=>1, 'mentor'=>1, 'level'=>1, 'x'=>1, 'y'=>1, 'facing'=>1,
    'hp'=>1, 'max_hp'=>1, 'mana'=>1, 'max_mana'=>1, 'outcome'=>1, 'shield_until'=>1, 'poison_until'=>1, 'might_until'=>1, 'reviving'=>1];

$worker->onConnect = function ($c) use (&$clients) {
    $clients[$c->id] = ['connected' => time(), 'authenticated' => false, 'last_seen' => time(), 'rate_time' => time(), 'rate' => 0];
};
$worker->onClose = function ($c) use (&$clients, $plaza) {
    $s = $clients[$c->id] ?? null;
    unset($clients[$c->id]);
    if (($s['mode'] ?? '') !== 'plaza') {
        return;
    }
    foreach ($clients as $other) {
        if (($other['mode'] ?? '') === 'plaza' && $other['character_id'] === $s['character_id']) {
            return;
        }
    }
    $plaza->leave($s['character_id'], World::milliseconds());
};
$worker->onBufferFull = function ($c) {
    $c->close();
};
$worker->onMessage = function ($c, $raw) use (&$clients, $world, $plaza, $worker) {
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
        if (++$session['rate'] > 25) {
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
            $mode = $t->expedition_id === null ? 'plaza' : 'expedition';
            foreach ($clients as $otherId => $other) {
                if ($otherId !== $c->id && ($other['character_id'] ?? null) === $t->character_id && ($other['mode'] ?? null) === $mode) {
                    $worker->connections[$otherId]?->close();
                }
            }
            $caps = array_values(array_filter((array) ($m['caps'] ?? []), 'is_string'));
            $session += ['mode' => $mode, 'character_id' => $t->character_id, 'expedition_id' => $t->expedition_id,
                'session_hash' => $t->session_hash, 'caps' => array_flip($caps)];
            if ($mode === 'plaza') {
                $now = World::milliseconds();
                $plaza->join($t->character_id, $now);
                $session['authenticated'] = true;
                $c->send(json_encode(['v' => 1, 'type' => 'welcome', 'mode' => 'plaza', 'tick_rate' => 10]));
                $frame = $plaza->frame($now);
                $c->send(json_encode($plaza->view($t->character_id, $frame, $now, true), JSON_UNESCAPED_UNICODE));
                $session['view_hash'] = $frame['hash'];
                $session['sent_at'] = microtime(true);

                return;
            }
            $session['authenticated'] = true;
            $c->send(json_encode(['v' => 1, 'type' => 'welcome', 'mode' => 'expedition', 'tick_rate' => 10]));
            push($c, $session, $world->snapshot($t->expedition_id, $t->character_id));

            return;
        }
        if (($m['type'] ?? '') === 'ping') {
            // Presence is written for every live stream at once by the 5-second check.
            $c->send('{"v":1,"type":"pong"}');

            return;
        }
        if ($session['mode'] === 'plaza') {
            if (($m['type'] ?? '') !== 'plaza' || ! $plaza->has($session['character_id'])) {
                throw new RuntimeException('invalid_command');
            }
            foreach (['action', 'direction', 'target'] as $key) {
                if (isset($m[$key]) && ! is_string($m[$key])) {
                    throw new RuntimeException('invalid_payload');
                }
            }
            $c->send(json_encode($plaza->act($session['character_id'], $m, World::milliseconds()), JSON_UNESCAPED_UNICODE));

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
        $code = $e instanceof RuntimeException && $e->getMessage() === 'in_expedition' ? 'in_expedition' : 'request_rejected';
        $c->send(json_encode(['v' => 1, 'type' => 'error', 'code' => $code]));
        if (! ($clients[$c->id]['authenticated'] ?? false)) {
            $c->close();
        }
        report($e);
    }
};

/**
 * Sends a snapshot only when what the player sees has changed. The client extrapolates the tick
 * from its own clock, so an unchanged view is refreshed at most once per second. Clients with
 * the "lean" capability get the explored map only when it changed and slim party members.
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
    if (isset($session['caps']['lean'])) {
        $map = md5(implode('', $view['world']['map']));
        if ($map === ($session['map_hash'] ?? null)) {
            unset($view['world']['map']);
        }
        $session['map_hash'] = $map;
        foreach ($view['world']['players'] as $i => $p) {
            if ($p['id'] !== $session['character_id']) {
                $view['world']['players'][$i] = array_intersect_key($p, LEAN_MEMBER);
            }
        }
    }
    $c->send(json_encode($view, JSON_UNESCAPED_UNICODE));
    $session['view_hash'] = $hash;
    $session['sent_at'] = $now;
}

$worker->onWorkerStart = function () use ($world, $plaza, $worker, &$clients) {
    $checkedAt = 0;
    Timer::add(0.1, function () use ($world, $plaza, $worker, &$clients, &$checkedAt) {
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
        // Session, membership and presence for every stream at once, every 5 seconds.
        try {
            if ($live && time() - $checkedAt >= 5) {
                $checkedAt = time();
                $hashes = array_map(fn ($c) => $clients[$c->id]['session_hash'], $live);
                $valid = DB::table('game_sessions')->whereIn('token_hash', array_unique($hashes))->where('expires_at', '>', now())->pluck('token_hash')->flip();
                $streams = array_filter($live, fn ($c) => $clients[$c->id]['mode'] === 'expedition');
                $members = $streams ? DB::table('expedition_members')->whereIn('character_id', array_unique(array_map(fn ($c) => $clients[$c->id]['character_id'], $streams)))
                    ->get()->map(fn ($m) => $m->expedition_id.':'.$m->character_id)->flip() : collect();
                $seen = [];
                foreach ($live as $id => $c) {
                    $s = $clients[$id];
                    $member = $s['mode'] === 'plaza' || isset($members[$s['expedition_id'].':'.$s['character_id']]);
                    if (! isset($valid[$s['session_hash']]) || ! $member) {
                        $c->close();
                        unset($live[$id]);
                    } elseif ($s['mode'] === 'expedition') {
                        $seen[$s['expedition_id']][] = $s['character_id'];
                    }
                }
                $time = World::milliseconds();
                foreach ($seen as $expedition => $characters) {
                    DB::table('expedition_members')->where('expedition_id', $expedition)->whereIn('character_id', $characters)->update(['last_seen' => $time]);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
        try {
            $world->tick();
            $streams = array_filter($live, fn ($c) => $clients[$c->id]['mode'] === 'expedition');
            $rows = $world->refresh(array_map(fn ($c) => $clients[$c->id]['expedition_id'], $streams));
            foreach ($streams as $id => $c) {
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
                if (($clients[$c->id]['mode'] ?? '') === 'expedition') {
                    $c->send('{"v":1,"type":"server_paused"}');
                }
            }
        }
        // The town square: everyone who changed anything is seen by everyone on this push.
        try {
            if ($plaza->count()) {
                $now = World::milliseconds();
                $gone = array_flip($plaza->sync($now));
                $frame = $plaza->frame($now);
                $wall = microtime(true);
                foreach ($live as $id => $c) {
                    $s = &$clients[$id];
                    if ($s['mode'] !== 'plaza') {
                        unset($s);
                        continue;
                    }
                    if (isset($gone[$s['character_id']]) || ! $plaza->has($s['character_id'])) {
                        $c->send('{"v":1,"type":"error","code":"in_expedition"}');
                        $c->close();
                    } elseif ($frame['hash'] !== ($s['view_hash'] ?? null) || $wall - ($s['sent_at'] ?? 0) >= 1.0) {
                        $c->send(json_encode($plaza->view($s['character_id'], $frame, $now), JSON_UNESCAPED_UNICODE));
                        $s['view_hash'] = $frame['hash'];
                        $s['sent_at'] = $wall;
                    }
                    unset($s);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    });
};
Worker::runAll();
