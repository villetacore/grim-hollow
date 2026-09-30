<?php

declare(strict_types=1);

namespace App\Game;

use DomainException;
use GrimHollow\Core\Catalog;
use GrimHollow\Core\Game;
use GrimHollow\Core\Canonical;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class World
{
    /**
     * Last committed row per expedition, kept by the long-lived world process so that streaming
     * snapshots do not re-read and re-decode MySQL rows on every tick.
     * id => ['revision','status','join_code','host_id','state']
     */
    private array $rows = [];

    /** "expedition:character" pairs already settled; avoids a settlements lookup per tick. */
    private array $settled = [];

    public static function milliseconds(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public function character(int $user, string $id): object
    {
        $c = DB::table('characters')->where('id', $id)->where('user_id', $user)->first();
        abort_unless($c, 404, 'character_not_found');

        return $c;
    }

    public function create(object $character,string $biome='mines'): object
    {
        return DB::transaction(function () use ($character,$biome) {
            $c = DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();
            if ($c->active_expedition) {
                return DB::table('expeditions')->where('id', $c->active_expedition)->first();
            }
            $id = (string) Str::ulid();
            abort_unless(isset(Catalog::biomes()[$biome]) && $c->campaign>=Catalog::biomes()[$biome]['chapter'],409,'biome_locked');
            $characters=new Characters; $characters->starter($c);
            $state = Game::create(random_int(1, 2000000000), [$c->id => $characters->profile($c)],$biome);
            DB::table('expeditions')->insert(['id' => $id, 'host_id' => $c->id, 'join_code' => strtoupper(Str::random(10)),
                'status' => 'lobby', 'state' => json_encode($state), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('expedition_members')->insert(['expedition_id' => $id, 'character_id' => $c->id, 'last_seen' => self::milliseconds()]);
            DB::table('characters')->where('id', $c->id)->update(['active_expedition' => $id]);

            return DB::table('expeditions')->where('id', $id)->first();
        }, 3);
    }

    public function join(object $c, string $code): object
    {
        return DB::transaction(function () use ($c, $code) {
            $e = DB::table('expeditions')->where('join_code', strtoupper($code))->lockForUpdate()->first();
            abort_unless($e, 404, 'lobby_not_found');
            $c = DB::table('characters')->where('id', $c->id)->lockForUpdate()->first();
            if ($c->active_expedition === $e->id) {
                return $e;
            }
            abort_if($c->active_expedition, 409, 'already_in_expedition');
            abort_unless($e->status === 'lobby', 409, 'already_started');
            abort_if(DB::table('settlements')->where('expedition_id',$e->id)->where('character_id',$c->id)->exists(),409,'already_left');
            $state = json_decode($e->state, true, 512, JSON_THROW_ON_ERROR);
            abort_if(count($state['players']) >= 4, 409, 'party_full');
            abort_unless($c->campaign>=Catalog::biomes()[$state['biome']??'mines']['chapter'],409,'biome_locked');
            $names = array_map(static fn ($p) => array_intersect_key($p,array_flip(['name','class_id','level','max_hp','max_mana','damage_bonus','armor','power','equipment','potions'])), $state['players']);
            $characters=new Characters; $characters->starter($c);
            $names[$c->id] = $characters->profile($c);
            $state = Game::create($state['seed'], $names,$state['biome']??'mines');
            // Bump the revision so open world streams notice the new member.
            DB::table('expeditions')->where('id', $e->id)->update(['state' => json_encode($state), 'revision' => $e->revision + 1]);
            DB::table('expedition_members')->insert(['expedition_id' => $e->id, 'character_id' => $c->id, 'last_seen' => self::milliseconds()]);
            DB::table('characters')->where('id', $c->id)->update(['active_expedition' => $e->id]);

            return DB::table('expeditions')->where('id', $e->id)->first();
        }, 3);
    }

    public function start(string $id, string $character): void
    {
        DB::transaction(function () use ($id, $character) {
            $e = $this->locked($id, $character);
            abort_unless($e->host_id === $character, 403, 'host_only');
            if ($e->status !== 'lobby') {
                return;
            }
            $s=json_decode($e->state,true);
            foreach ($s['players'] as $pid=>$player) {
                $c=DB::table('characters')->where('id',$pid)->lockForUpdate()->first();
                DB::table('characters')->where('id',$pid)->update(['supplies'=>max(0,$c->supplies-max(0,($player['potions']??3)-3))]);
            }
            DB::table('expeditions')->where('id', $id)->update(['status' => 'active', 'genesis'=>$e->state,'genesis_revision'=>$e->revision,'next_tick_at' => self::milliseconds() + 100]);
        }, 3);
    }

    private function locked(string $id, string $character): object
    {
        $e = DB::table('expeditions')->where('id', $id)->lockForUpdate()->first();
        abort_unless($e && DB::table('expedition_members')->where('expedition_id', $id)->where('character_id', $character)->exists(), 404, 'expedition_not_found');

        return $e;
    }

    public function leave(string $id, string $character): void
    {
        DB::transaction(function () use ($id, $character) {
            $e=$this->locked($id,$character);
            $s=json_decode($e->state,true,512,JSON_THROW_ON_ERROR);
            if (($s['players'][$character]['outcome'] ?? null)!==null) return;
            $cancelLobby=$e->status==='lobby' && $e->host_id===$character;
            foreach ($s['players'] as $pid=>&$p) {
                if ($p['outcome']===null && ($cancelLobby || $pid===$character)) $p['outcome']='abandoned';
            }
            unset($p);
            if (!array_filter($s['players'],static fn($p)=>$p['outcome']===null)) $s['status']='completed';
            $this->persist($e,$s,'leave',['character_id'=>$character,'cancel_lobby'=>$cancelLobby]);
            if ($e->status==='lobby' && !$cancelLobby) {
                unset($s['players'][$character]);
                DB::table('expeditions')->where('id',$id)->update(['state'=>Canonical::json($s),'status'=>'lobby']);
                DB::table('game_events')->where('expedition_id',$id)->where('revision',$e->revision+1)
                    ->update(['state_hash'=>hash('sha256',Canonical::json($s))]);
                DB::table('expedition_members')->where('expedition_id',$id)->where('character_id',$character)->delete();
            }
        },3);
    }

    public function snapshot(string $id, string $character, bool $touch = true): array
    {
        $e = DB::table('expeditions')->where('id', $id)->first();
        abort_unless($e && DB::table('expedition_members')->where('expedition_id', $id)->where('character_id', $character)->exists(), 404, 'expedition_not_found');
        if ($touch) {
            DB::table('expedition_members')->where('expedition_id', $id)->where('character_id', $character)->update(['last_seen' => self::milliseconds()]);
        }

        return $this->view($this->remember($e), $id, $character);
    }

    /** Builds a player's snapshot from a cached row; membership must already be established. */
    public function view(array $row, string $id, string $character): array
    {
        abort_unless(isset($row['state']['players'][$character]), 404, 'expedition_not_found');

        return ['v' => 1, 'type' => 'snapshot', 'instance_id' => $id, 'revision' => $row['revision'],
            'lobby' => $row['status'] === 'lobby', 'join_code' => $row['join_code'], 'host_id' => $row['host_id'],
            'world' => Game::view($row['state'], $character)];
    }

    public function cached(string $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    /**
     * Brings cached rows for the streamed expeditions up to date with one revision query, and
     * reloads only those changed elsewhere (HTTP commands, joins, starts). Rows not requested
     * are dropped from the cache.
     */
    public function refresh(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $this->rows = array_intersect_key($this->rows, array_flip($ids));
        if (! $ids) {
            return [];
        }
        $revisions = DB::table('expeditions')->whereIn('id', $ids)->pluck('revision', 'id');
        $stale = [];
        foreach ($ids as $id) {
            if (! isset($revisions[$id])) {
                unset($this->rows[$id]);
            } elseif (($this->rows[$id]['revision'] ?? null) !== (string) $revisions[$id]) {
                $stale[] = $id;
            }
        }
        if ($stale) {
            foreach (DB::table('expeditions')->whereIn('id', $stale)->get() as $e) {
                $this->remember($e);
            }
        }

        return $this->rows;
    }

    private function remember(object $e, ?array $state = null): array
    {
        return $this->rows[$e->id] = ['revision' => (string) $e->revision, 'status' => $e->status, 'join_code' => $e->join_code,
            'host_id' => $e->host_id, 'state' => $state ?? json_decode($e->state, true, 512, JSON_THROW_ON_ERROR)];
    }

    public function command(string $id, string $character, string $commandId, array $payload): array
    {
        try { \GrimHollow\Core\Command::validate($payload); } catch(DomainException $error) { abort(422,$error->getMessage()); }
        [$result, $row] = DB::transaction(function () use ($id, $character, $commandId, $payload) {
            $e = $this->locked($id, $character);
            ksort($payload);
            $hash = hash('sha256', json_encode($payload));
            $receipt = DB::table('command_receipts')->where('expedition_id', $id)->where('character_id', $character)->where('command_id', $commandId)->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->payload_hash, $hash), 409, 'command_id_reused');

                return [json_decode($receipt->result, true), null];
            }
            abort_unless($e->status === 'active', 409, 'not_active');
            $state = json_decode($e->state, true, 512, JSON_THROW_ON_ERROR);
            try {
                [$state, $status] = Game::submit($state, $character, $payload);
                $result = ['status' => $status];
            } catch (DomainException $error) {
                $result = ['status' => 'rejected', 'reason' => $error->getMessage()];
            }
            $result += ['v' => 1, 'type' => 'command_result', 'command_id' => $commandId, 'tick' => (string) $state['tick']];
            $this->persist($e, $state, 'command', ['character_id' => $character, 'command' => $payload, 'result' => $result]);
            DB::table('command_receipts')->insert(['expedition_id' => $id, 'character_id' => $character, 'command_id' => $commandId,
                'payload_hash' => $hash, 'result' => json_encode($result)]);

            return [$result, ['revision' => (string) ($e->revision + 1), 'status' => $state['status'], 'join_code' => $e->join_code,
                'host_id' => $e->host_id, 'state' => $state]];
        }, 3);
        if ($row) {
            $this->rows[$id] = $row;
        }

        return $result;
    }

    /**
     * Advances every due expedition inside one transaction: one commit (and one fsync) per
     * 100 ms step instead of one per expedition. A failing expedition rolls back to its own
     * savepoint and does not stall the others.
     */
    public function tick(): int
    {
        $time = self::milliseconds();
        $ids = DB::table('expeditions')->where('status', 'active')->where('next_tick_at', '<=', $time)->pluck('id')->all();
        if (! $ids) {
            return 0;
        }
        $done = DB::transaction(function () use ($ids, $time) {
            $done = [];
            $rows = DB::table('expeditions')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $members = DB::table('expedition_members')->whereIn('expedition_id', $ids)->get()->groupBy('expedition_id');
            foreach ($rows as $e) {
                if ($e->status !== 'active' || $e->next_tick_at > $time) {
                    continue;
                }
                try {
                    $done[$e->id] = DB::transaction(fn () => $this->advance($e, $members[$e->id] ?? collect(), $time));
                } catch (Throwable $error) {
                    report($error);
                }
            }

            return $done;
        }, 3);
        foreach ($done as $id => $row) {
            $this->rows[$id] = $row;
        }
        if (count($this->settled) > 50000) {
            $this->settled = [];
        }

        return count($done);
    }

    private function advance(object $e, Collection $members, int $time): array
    {
        $s = json_decode($e->state, true, 512, JSON_THROW_ON_ERROR);
        // Server downtime pauses the disconnect grace period as well as combat.
        if ($e->next_tick_at > 0 && $time - $e->next_tick_at > 1000) {
            $downtime = $time - $e->next_tick_at;
            foreach ($members as $member) {
                $member->last_seen = min($time, $member->last_seen + $downtime);
                DB::table('expedition_members')->where('expedition_id', $e->id)->where('character_id', $member->character_id)
                    ->update(['last_seen' => $member->last_seen]);
            }
        }
        $absent = $members->filter(fn ($m) => $m->last_seen < $time - 120000)->pluck('character_id')->values();
        foreach ($absent as $pid) {
            if (isset($s['players'][$pid]) && $s['players'][$pid]['outcome'] === null) {
                $s['players'][$pid]['outcome'] = 'abandoned';
            }
        }
        $s = Game::tick($s);
        // No offline catch-up damage after a server outage.
        $this->persist($e, $s, 'tick', ['absent' => $absent->all()], ['next_tick_at' => $time + 100]);

        return ['revision' => (string) ($e->revision + 1), 'status' => $s['status'], 'join_code' => $e->join_code, 'host_id' => $e->host_id, 'state' => $s];
    }

    private function persist(object $e, array $s, string $kind, array $payload, array $extra = []): void
    {
        $json = Canonical::json($s);
        DB::table('expeditions')->where('id', $e->id)->update(['state' => $json, 'status' => $s['status'],
            'revision' => $e->revision + 1, 'updated_at' => now()] + $extra);
        DB::table('game_events')->insert(['expedition_id' => $e->id, 'revision' => $e->revision + 1, 'kind' => $kind,
            'payload' => json_encode($payload), 'state_hash' => hash('sha256', $json), 'created_at' => now()]);
        // The entire current snapshot and economic settlement share a transaction.
        foreach ($s['players'] as $id => $p) {
            if ($p['outcome'] === null || isset($this->settled[$e->id.':'.$id])) {
                continue;
            }
            if (DB::table('settlements')->where('expedition_id', $e->id)->where('character_id', $id)->exists()) {
                $this->settled[$e->id.':'.$id] = true;
                continue;
            }
            $c = DB::table('characters')->where('id', $id)->lockForUpdate()->first();
            $extracted = $p['outcome'] === 'extracted';
            $gold = $extracted ? $p['gold'] : 0;
            $xp = $extracted ? $p['xp'] : intdiv($p['xp'],4);
            $essence = $extracted ? (int) ($p['essence'] ?? 0) : 0;
            $area = Catalog::biomes()[$s['biome']??'mines'];
            $chapter = $area['chapter'];
            $won=$extracted && $s['floor']===Game::lastFloor($s) && isset($s['enemies']['boss']) && $s['enemies']['boss']['hp']===0;
            if ($won && $c->campaign===$chapter) { $gold+=50*($chapter+1);$xp+=40*($chapter+1); }
            DB::table('settlements')->insert(['expedition_id' => $e->id, 'character_id' => $id, 'outcome' => $p['outcome'],
                'gold' => $gold, 'xp' => $xp, 'created_at' => now(), 'updated_at' => now()]);
            $update = ['gold' => $c->gold + $gold, 'xp' => $c->xp + $xp, 'essence' => $c->essence + $essence, 'active_expedition' => null];
            // Bounty progress counts only for heroes who made it back to report.
            $bounty = $c->bounty ? json_decode($c->bounty, true) : null;
            if ($extracted && $bounty && isset($p['slain'][$bounty['type']])) {
                $bounty['have'] = min($bounty['need'], $bounty['have'] + $p['slain'][$bounty['type']]);
                $update['bounty'] = json_encode($bounty, JSON_UNESCAPED_UNICODE);
            }
            if ($won) $update['campaign'] = max($c->campaign, $chapter + 1);
            DB::table('characters')->where('id',$id)->update($update);
            Town::ledger($id,'expedition',$gold,0,$e->id);
            if ($extracted) foreach ($p['loot']??[] as $key) (new Characters)->grant($id,$key);
            $this->settled[$e->id.':'.$id] = true;
        }
    }
}
