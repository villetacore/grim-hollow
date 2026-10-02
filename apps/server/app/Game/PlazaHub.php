<?php
declare(strict_types=1);
namespace App\Game;

use GrimHollow\Core\{Catalog,Plaza};
use Illuminate\Support\Facades\DB;

/**
 * The live town square inside the long-lived world process. Heroes connected over the world
 * WebSocket walk, talk and emote in memory: no SQL per step, the answer leaves in the same
 * event-loop turn and everyone sees the move on the next 100 ms push.
 *
 * MySQL stays the meeting point with the HTTP square (old clients, tests): sync() writes the
 * live heroes to plaza_presence and reads the HTTP-only heroes and chat bubbles back, once
 * per SYNC_MS for the whole town instead of once per request per hero.
 */
final class PlazaHub
{
    /** Sustained walking limit; the client walks every STEP_MS (110 ms) and never reaches it. */
    private const STEP_MS = 90;
    /** Network jitter may deliver a few queued steps together; this much of them is honest. */
    private const BURST_MS = 450;
    private const SYNC_MS = 500;
    private const TALK_RANGE = 2;
    private const DIRS = ['north'=>[0,-1],'south'=>[0,1],'west'=>[-1,0],'east'=>[1,0]];

    /** character id => live hero: profile, position and pacing. */
    private array $heroes = [];
    /** Heroes on the HTTP square, as last read from plaza_presence. */
    private array $others = [];
    /** character id => chat line floating over the hero. */
    private array $bubbles = [];
    private int $syncedAt = 0;

    public function count(): int
    {
        return count($this->heroes);
    }

    public function has(string $id): bool
    {
        return isset($this->heroes[$id]);
    }

    /** Puts a hero on the square (at the stored cell, or the spawn after a long absence). */
    public function join(string $id, int $now): void
    {
        $c = DB::table('characters')->where('id', $id)->first(['id', 'name', 'class_id', 'origin', 'xp', 'active_expedition']);
        if (! $c || $c->active_expedition) {
            throw new \RuntimeException('in_expedition');
        }
        $p = DB::table('plaza_presence')->where('character_id', $id)->first();
        [$x, $y] = Plaza::SPAWN;
        $facing = 'south';
        if ($p && $p->seen_at >= $now - Plaza::PRESENCE_MS) {
            [$x, $y, $facing] = [(int) $p->x, (int) $p->y, $p->facing];
        }
        $old = $this->heroes[$id] ?? null;
        $this->heroes[$id] = ['id'=>$id, 'name'=>$c->name, 'class_id'=>$c->class_id, 'origin'=>$c->origin ?? 'human',
            'level'=>Catalog::progression((int) $c->xp)['level'], 'x'=>$old['x'] ?? $x, 'y'=>$old['y'] ?? $y, 'facing'=>$old['facing'] ?? $facing,
            'emote'=>null, 'emote_until'=>0, 'moved_at'=>0, 'vt'=>0, 'turns'=>[], 'dirty'=>true, 'written_at'=>0];
        unset($this->others[$id]);
        $this->write([$id], $now);
    }

    /** Takes a hero off the live square; the HTTP square keeps the last cell for a while. */
    public function leave(string $id, int $now): void
    {
        if (! isset($this->heroes[$id])) {
            return;
        }
        try {
            $this->write([$id], $now);
        } catch (\Throwable $e) {
            report($e);
        }
        unset($this->heroes[$id]);
    }

    /**
     * One client message. Returns the reply: result ok|blocked|cooldown|too_far|..., the
     * authoritative cell, and for a talk the keeper's words.
     */
    public function act(string $id, array $m, int $now): array
    {
        $h = &$this->heroes[$id];
        $reply = ['v'=>1, 'type'=>'plaza_result', 'seq'=>(int) ($m['seq'] ?? 0), 'action'=>(string) ($m['action'] ?? '')];
        switch ($m['action'] ?? '') {
            case 'move':
                $dir = self::DIRS[$m['direction'] ?? ''] ?? null;
                if ($dir === null) {
                    $reply['result'] = 'invalid';
                    break;
                }
                // Leaky bucket: honest pace never fills it, bunched packets fit in the burst.
                if ($h['vt'] - $now > self::BURST_MS) {
                    $reply['result'] = 'cooldown';
                    break;
                }
                $h['facing'] = $m['direction'];
                $x = $h['x'] + $dir[0];
                $y = $h['y'] + $dir[1];
                if (! Plaza::walkable($x, $y)) {
                    $reply['result'] = 'blocked';
                } else {
                    $h['x'] = $x;
                    $h['y'] = $y;
                    $h['moved_at'] = $now;
                    $h['vt'] = max($h['vt'], $now) + self::STEP_MS;
                    $h['emote'] = null;
                    $reply['result'] = 'ok';
                }
                $h['dirty'] = true;
                break;
            case 'emote':
                if (! isset(Plaza::EMOTES[$m['target'] ?? ''])) {
                    $reply['result'] = 'invalid';
                    break;
                }
                $h['emote'] = $m['target'];
                $h['emote_until'] = $now + 5000;
                $h['dirty'] = true;
                $reply['result'] = 'ok';
                break;
            case 'talk':
                $reply += $this->talk($h, (string) ($m['target'] ?? ''), $now);
                break;
            default:
                $reply['result'] = 'invalid';
        }
        $reply += ['x'=>$h['x'], 'y'=>$h['y'], 'facing'=>$h['facing']];

        return $reply;
    }

    /** Keepers answer with the next of their lines and name the service they open. */
    private function talk(array &$h, string $target, int $now): array
    {
        $npc = Plaza::npc($target);
        if (! $npc) {
            foreach (Plaza::walkersAt($now) as $w) {
                if ($w['id'] === $target) {
                    $npc = $w;
                    break;
                }
            }
        }
        if (! $npc) {
            return ['result'=>'npc_not_found'];
        }
        if (abs($npc['x'] - $h['x']) + abs($npc['y'] - $h['y']) > self::TALK_RANGE) {
            return ['result'=>'too_far'];
        }
        $turn = $h['turns'][$target] = ($h['turns'][$target] ?? -1) + 1;
        if ($npc['service'] === '') {
            $line = Plaza::walkerLine($npc['kind'], $turn);
        } elseif ($npc['service'] === 'dummy') {
            $line = (new Square)->dummy(DB::table('characters')->where('id', $h['id'])->first());
        } else {
            $line = Plaza::line($npc, $now, $h['id'], $turn);
        }

        return ['result'=>'ok', 'talk'=>['npc'=>$npc['id'], 'name'=>$npc['name'], 'service'=>$npc['service'], 'line'=>$line]];
    }

    /**
     * Every SYNC_MS: stores live heroes for the HTTP square, reads the HTTP-only heroes and
     * the chat bubbles, and returns live heroes who have meanwhile set out on an expedition.
     */
    public function sync(int $now): array
    {
        if (! $this->heroes || $now - $this->syncedAt < self::SYNC_MS) {
            return [];
        }
        $this->syncedAt = $now;
        // Positions are refreshed while moving; idle heroes only need to stay inside the window.
        $this->write(array_keys(array_filter($this->heroes, fn ($h) => $h['dirty'] || $now - $h['written_at'] > Plaza::PRESENCE_MS / 3)), $now);
        $gone = DB::table('characters')->whereIn('id', array_keys($this->heroes))->whereNotNull('active_expedition')->pluck('id')->all();
        foreach ($gone as $id) {
            unset($this->heroes[$id]);
        }
        $this->others = [];
        $rows = DB::table('plaza_presence as p')->join('characters as c', 'c.id', '=', 'p.character_id')
            ->where('p.seen_at', '>', $now - Plaza::PRESENCE_MS)->whereNull('c.active_expedition')
            ->orderBy('p.character_id')->limit(250)
            ->get(['c.id', 'c.name', 'c.class_id', 'c.origin', 'c.xp', 'p.x', 'p.y', 'p.facing', 'p.emote', 'p.emote_until']);
        foreach ($rows as $r) {
            if (! isset($this->heroes[$r->id])) {
                $this->others[$r->id] = ['id'=>$r->id, 'name'=>$r->name, 'class_id'=>$r->class_id, 'origin'=>$r->origin ?? 'human',
                    'level'=>Catalog::progression((int) $r->xp)['level'], 'x'=>(int) $r->x, 'y'=>(int) $r->y, 'facing'=>$r->facing,
                    'emote'=>$r->emote, 'emote_until'=>(int) $r->emote_until];
            }
        }
        $this->bubbles = DB::table('chat_messages')->where('channel', 'global')->where('created_at', '>=', now()->subSeconds(8))
            ->orderBy('id')->limit(200)->pluck('body', 'character_id')->map(fn ($b) => mb_substr($b, 0, 60))->all();

        return $gone;
    }

    /** One upsert for the given live heroes. */
    private function write(array $ids, int $now): void
    {
        $rows = [];
        foreach ($ids as $id) {
            $h = &$this->heroes[$id];
            $rows[] = ['character_id'=>$id, 'x'=>$h['x'], 'y'=>$h['y'], 'facing'=>$h['facing'], 'moved_at'=>$h['moved_at'], 'seen_at'=>$now,
                'emote'=>$h['emote_until'] > $now ? $h['emote'] : null, 'emote_until'=>$h['emote_until']];
            $h['dirty'] = false;
            $h['written_at'] = $now;
            unset($h);
        }
        if ($rows) {
            DB::table('plaza_presence')->upsert($rows, ['character_id'], ['x', 'y', 'facing', 'moved_at', 'seen_at', 'emote', 'emote_until']);
        }
    }

    private function entry(array $h, int $now): array
    {
        return ['id'=>$h['id'], 'name'=>$h['name'], 'class_id'=>$h['class_id'], 'origin'=>$h['origin'], 'level'=>$h['level'],
            'x'=>$h['x'], 'y'=>$h['y'], 'facing'=>$h['facing'], 'emote'=>$h['emote_until'] > $now ? $h['emote'] : null,
            'bubble'=>$this->bubbles[$h['id']] ?? null];
    }

    /**
     * Everyone on the square this moment, shared by all views of one push, and its hash:
     * a view is only resent when this changes (or once a second as a keep-alive).
     */
    public function frame(int $now): array
    {
        $everyone = [];
        foreach ($this->heroes as $id => $h) {
            $everyone[$id] = $this->entry($h, $now);
        }
        foreach ($this->others as $id => $h) {
            $everyone[$id] = $this->entry($h, $now);
        }
        $walkers = Plaza::walkersAt($now);

        return ['everyone'=>$everyone, 'walkers'=>$walkers, 'hash'=>md5(serialize([$everyone, $walkers]))];
    }

    /**
     * A hero's view. Live views carry the walkers only: the keepers never move, and the client
     * keeps them from the full view it got on joining.
     */
    public function view(string $id, array $frame, int $now, bool $full = false): array
    {
        $players = $frame['everyone'];
        $self = $players[$id] ?? $this->entry($this->heroes[$id], $now);
        unset($players[$id]);
        $view = ['v'=>1, 'type'=>'plaza', 'now'=>(string) $now, 'step_ms'=>Plaza::STEP_MS, 'online'=>count($players) + 1,
            'self'=>$self, 'players'=>array_values($players), 'walkers'=>$frame['walkers']];
        if ($full) {
            $view += ['npcs'=>array_merge(Plaza::keepers(), $frame['walkers']), 'width'=>Plaza::WIDTH, 'height'=>Plaza::HEIGHT,
                'map'=>Plaza::map(), 'buildings'=>Plaza::buildings(), 'emotes'=>Plaza::EMOTES];
        }

        return $view;
    }
}
