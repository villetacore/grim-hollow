<?php

declare(strict_types=1);

namespace App\Game;

use DomainException;
use GrimHollow\Core\Game;
use GrimHollow\Core\Canonical;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class World
{
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
            abort_unless(isset(\GrimHollow\Core\Catalog::biomes()[$biome]) && $c->campaign>=\GrimHollow\Core\Catalog::biomes()[$biome]['chapter'],409,'biome_locked');
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
            abort_unless($c->campaign>=\GrimHollow\Core\Catalog::biomes()[$state['biome']??'mines']['chapter'],409,'biome_locked');
            $names = array_map(static fn ($p) => array_intersect_key($p,array_flip(['name','class_id','level','max_hp','max_mana','damage_bonus','armor','power','equipment','potions'])), $state['players']);
            $characters=new Characters; $characters->starter($c);
            $names[$c->id] = $characters->profile($c);
            $state = Game::create($state['seed'], $names,$state['biome']??'mines');
            DB::table('expeditions')->where('id', $e->id)->update(['state' => json_encode($state)]);
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
        $state = json_decode($e->state, true, 512, JSON_THROW_ON_ERROR);

        return ['v' => 1, 'type' => 'snapshot', 'instance_id' => $id, 'revision' => (string) $e->revision,
            'lobby' => $e->status === 'lobby', 'join_code' => $e->join_code, 'host_id' => $e->host_id, 'world' => Game::view($state, $character)];
    }

    public function command(string $id, string $character, string $commandId, array $payload): array
    {
        try { \GrimHollow\Core\Command::validate($payload); } catch(DomainException $error) { abort(422,$error->getMessage()); }
        return DB::transaction(function () use ($id, $character, $commandId, $payload) {
            $e = $this->locked($id, $character);
            ksort($payload);
            $hash = hash('sha256', json_encode($payload));
            $receipt = DB::table('command_receipts')->where('expedition_id', $id)->where('character_id', $character)->where('command_id', $commandId)->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->payload_hash, $hash), 409, 'command_id_reused');

                return json_decode($receipt->result, true);
            }
            abort_unless($e->status === 'active', 409, 'not_active');
            $state = json_decode($e->state, true, 512, JSON_THROW_ON_ERROR);
            try {
                $state = Game::command($state, $character, $payload);
                $result = ['status' => 'executed'];
            } catch (DomainException $error) {
                $result = ['status' => 'rejected', 'reason' => $error->getMessage()];
            }
            $result += ['v' => 1, 'type' => 'command_result', 'command_id' => $commandId, 'tick' => (string) $state['tick']];
            $this->persist($e, $state, 'command', ['character_id' => $character, 'command' => $payload, 'result' => $result]);
            DB::table('command_receipts')->insert(['expedition_id' => $id, 'character_id' => $character, 'command_id' => $commandId,
                'payload_hash' => $hash, 'result' => json_encode($result)]);

            return $result;
        }, 3);
    }

    public function tick(): int
    {
        $count = 0;
        $time = self::milliseconds();
        $ids = DB::table('expeditions')->where('status', 'active')->where('next_tick_at', '<=', $time)->pluck('id');
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id, $time) {
                $e = DB::table('expeditions')->where('id', $id)->lockForUpdate()->first();
                if ($e->status !== 'active' || $e->next_tick_at > $time) {
                    return 0;
                }
                $s = json_decode($e->state, true, 512, JSON_THROW_ON_ERROR);
                // Server downtime pauses the disconnect grace period as well as combat.
                if ($e->next_tick_at > 0 && $time - $e->next_tick_at > 1000) {
                    $downtime = $time - $e->next_tick_at;
                    foreach (DB::table('expedition_members')->where('expedition_id', $id)->get() as $member) {
                        DB::table('expedition_members')->where('expedition_id', $id)->where('character_id', $member->character_id)
                            ->update(['last_seen' => min($time, $member->last_seen + $downtime)]);
                    }
                }
                $absent = DB::table('expedition_members')->where('expedition_id', $id)->where('last_seen', '<', $time - 120000)->pluck('character_id');
                foreach ($absent as $pid) {
                    if ($s['players'][$pid]['outcome'] === null) {
                        $s['players'][$pid]['outcome'] = 'abandoned';
                    }
                }
                $s = Game::tick($s);
                $this->persist($e, $s, 'tick', ['absent'=>$absent->all()]);
                // No offline catch-up damage after a server outage.
                DB::table('expeditions')->where('id', $id)->update(['next_tick_at' => $time + 100]);

                return 1;
            }, 3);
        }

        return $count;
    }

    private function persist(object $e, array $s, string $kind, array $payload): void
    {
        $json = Canonical::json($s);
        DB::table('expeditions')->where('id', $e->id)->update(['state' => $json, 'status' => $s['status'],
            'revision' => $e->revision + 1, 'updated_at' => now()]);
        DB::table('game_events')->insert(['expedition_id' => $e->id, 'revision' => $e->revision + 1, 'kind' => $kind,
            'payload' => json_encode($payload), 'state_hash' => hash('sha256', $json), 'created_at' => now()]);
        // The entire current snapshot and economic settlement share a transaction.
        foreach ($s['players'] as $id => $p) {
            if ($p['outcome'] === null || DB::table('settlements')->where('expedition_id', $e->id)->where('character_id', $id)->exists()) {
                continue;
            }
            $c = DB::table('characters')->where('id', $id)->lockForUpdate()->first();
            $gold = $p['outcome'] === 'extracted' ? $p['gold'] : 0;
            $xp = $p['outcome'] === 'extracted' ? $p['xp'] : intdiv($p['xp'],4);
            $chapter=\GrimHollow\Core\Catalog::biomes()[$s['biome']??'mines']['chapter'];
            $won=$p['outcome']==='extracted' && $s['floor']===3 && isset($s['enemies']['boss']) && $s['enemies']['boss']['hp']===0;
            if ($won && $c->campaign===$chapter) { $gold+=50*($chapter+1);$xp+=40*($chapter+1); }
            DB::table('settlements')->insert(['expedition_id' => $e->id, 'character_id' => $id, 'outcome' => $p['outcome'],
                'gold' => $gold, 'xp' => $xp, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('characters')->where('id',$id)->update(['gold' => $c->gold + $gold, 'xp' => $c->xp + $xp, 'active_expedition' => null]);
            if ($won) DB::table('characters')->where('id',$id)->update(['campaign'=>max($c->campaign,$chapter+1)]);
            Town::ledger($id,'expedition',$gold,0,$e->id);
            if ($p['outcome']==='extracted') foreach ($p['loot']??[] as $key) (new Characters)->grant($id,$key);
        }
    }
}
