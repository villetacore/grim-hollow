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
            $c->send(json_encode($world->snapshot($t->expedition_id, $t->character_id)));

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
    } catch (Throwable $e) {
        $c->send(json_encode(['v' => 1, 'type' => 'error', 'code' => 'request_rejected']));
        if (! ($clients[$c->id]['authenticated'] ?? false)) {
            $c->close();
        }
        report($e);
    }
};
$worker->onWorkerStart = function () use ($world, $worker, &$clients) {
    Timer::add(0.1, function () use ($world, $worker, &$clients) {
        try {
            $world->tick();
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
                if(time()-($s['checked_at']??0)>=5){
                    if(!DB::table('game_sessions')->where('token_hash',$s['session_hash'])->where('expires_at','>',now())->exists()){$c->close();continue;}
                    $clients[$c->id]['checked_at']=time();
                }
                try {
                    $view = $world->snapshot($s['expedition_id'], $s['character_id'], false);
                } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
                    $c->close();
                    continue;
                }
                if (($s['revision'] ?? null) !== $view['revision']) {
                    $c->send(json_encode($view));
                    $clients[$c->id]['revision'] = $view['revision'];
                }
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
