<?php

declare(strict_types=1);

namespace GrimHollow\Core;

use DomainException;

final class Game
{
    public const CONTENT_VERSION = 'campaign-003';

    public static function create(int $seed, array $players, string $biome='mines'): array
    {
        if (!isset(Catalog::biomes()[$biome])) throw new DomainException('unknown_biome');
        $state = ['version' => 1, 'content_version' => self::CONTENT_VERSION, 'seed' => $seed,
            'tick' => 0, 'floor' => 1, 'biome'=>$biome, 'shrine_charges'=>1, 'status' => 'active', 'players' => [], 'log' => [], 'rng' => $seed];
        foreach ($players as $id => $name) {
            $profile=is_array($name)?array_replace(Catalog::profile($name['name'],'guardian',0,[],[]),$name):Catalog::profile($name,'guardian',0,[],[]);
            $name=$profile['name'];
            $state['players'][$id] = ['id' => $id, 'name' => $name, 'x' => 5, 'y' => 5, 'hp' => 100,
                'max_hp' => 100, 'gold' => 0, 'xp' => 0, 'potions' => 3, 'kills' => 0,
                'ready_at' => 0, 'shield_until' => 0, 'outcome' => null];
            $state['players'][$id]=array_replace($state['players'][$id],$profile);
            $state['players'][$id]['hp']=$profile['max_hp'];
            $state['players'][$id]['mana']=$profile['max_mana'];
            $state['players'][$id]['spell_ready']=[];
            $state['players'][$id]['loot']=[];
        }

        return self::floor($state);
    }

    private static function floor(array $s): array
    {
        $dungeon = Dungeon::generate($s['seed']+Catalog::biomes()[$s['biome']??'mines']['chapter']*104729, $s['floor']);
        $s['map'] = $dungeon['map'];
        $s['exit'] = $dungeon['exit'];
        $rng = new Random($dungeon['rng']);
        $s['enemies'] = [];
        $archetypes = ['scavenger', 'archer', 'spitter', 'brute'];
        $cells = [];
        for ($y = 1; $y < 31; $y++) {
            for ($x = 1; $x < 31; $x++) {
                if (Dungeon::walkable($s['map'], $x, $y) && abs($x - 5) + abs($y - 5) > 8 && [$x, $y] !== $s['exit']) {
                    $cells[] = [$x, $y];
                }
            }
        }
        for ($i = 0; $i < min(count($cells), 4 + $s['floor'] * 2); $i++) {
            $index = $rng->next(0, count($cells) - 1);
            [$x,$y] = $cells[$index];
            array_splice($cells, $index, 1);
            $type = $archetypes[$i % 4];
            $hp = ($type === 'brute' ? 35 : 20) + 5 * $s['floor'] + Catalog::biomes()[$s['biome']??'mines']['enemy_hp'];
            $s['enemies']['e'.$i] = ['id' => 'e'.$i, 'type' => $type, 'x' => $x, 'y' => $y, 'hp' => $hp, 'ready_at' => 0];
        }
        if ($s['floor'] === 3) {
            [$x,$y] = $s['exit'];
            $s['enemies']['boss'] = ['id' => 'boss', 'type' => 'warden', 'x' => $x, 'y' => $y, 'hp' => 120+Catalog::biomes()[$s['biome']??'mines']['enemy_hp']*3, 'ready_at' => 0];
        }
        $offset = 0;
        foreach ($s['players'] as &$p) {
            if ($p['outcome'] === null) {
                $p['x'] = 5 + $offset++;
                $p['y'] = 5;
            }
        }
        unset($p);
        $s['rng'] = $rng->state;
        $s['log'][] = 'Этаж '.$s['floor'].': '.Catalog::biomes()[$s['biome']??'mines']['name'];

        return $s;
    }

    public static function command(array $s, string $playerId, array $command): array
    {
        if ($s['status'] !== 'active' || ! isset($s['players'][$playerId])) {
            throw new DomainException('not_active');
        }
        $p = &$s['players'][$playerId];
        if ($p['outcome'] !== null || $p['hp'] <= 0) {
            throw new DomainException('not_alive');
        }
        if ($p['ready_at'] > $s['tick']) {
            throw new DomainException('cooldown');
        }
        $action = $command['action'] ?? '';
        if ($action === 'move') {
            $dirs = ['north' => [0, -1], 'south' => [0, 1], 'west' => [-1, 0], 'east' => [1, 0]];
            if (! isset($dirs[$command['direction'] ?? ''])) {
                throw new DomainException('invalid_direction');
            }
            [$dx,$dy] = $dirs[$command['direction']];
            $x = $p['x'] + $dx;
            $y = $p['y'] + $dy;
            if (! self::free($s, $x, $y, $playerId)) {
                throw new DomainException('blocked');
            }
            $p['x'] = $x;
            $p['y'] = $y;
            $p['ready_at'] = $s['tick'] + 3;
        } elseif ($action === 'attack' || $action === 'bash') {
            $id = $command['target_id'] ?? '';
            if (! isset($s['enemies'][$id]) || $s['enemies'][$id]['hp'] <= 0) {
                throw new DomainException('invalid_target');
            }
            $e = &$s['enemies'][$id];
            $range=($action==='attack' && ($p['class_id']??'')==='ranger')?5:1;
            if (abs($p['x'] - $e['x']) + abs($p['y'] - $e['y']) > $range || !self::visible($s['map'],$p['x'],$p['y'],$e['x'],$e['y'])) {
                throw new DomainException('out_of_range');
            }
            $rng = new Random($s['rng']);
            $damage = $rng->next(10, 16)+($p['damage_bonus']??0);
            $s['rng'] = $rng->state;
            $e['hp'] = max(0, $e['hp'] - $damage);
            $p['ready_at'] = $s['tick'] + ($action === 'bash' ? 12 : 6);
            if ($action === 'bash') {
                $e['ready_at'] = max($e['ready_at'], $s['tick'] + 10);
            }
            $s['log'][] = $p['name'].' hit '.$e['type'].' for '.$damage;
            if ($e['hp'] === 0) {
                self::reward($p,$s['floor'],$e['type'],$s['biome']??'mines');
            }
            unset($e);
        } elseif ($action==='revive') {
            $id=$command['target_id']??'';$target=$s['players'][$id]??null;
            if (!$target || $target['outcome']!==null || $target['hp']>0 || ($target['downed_until']??0)<=$s['tick']) throw new DomainException('cannot_revive');
            if (abs($p['x']-$target['x'])+abs($p['y']-$target['y'])>1) throw new DomainException('out_of_range');
            $p['reviving']=['target'=>$id,'complete_at'=>$s['tick']+30];$p['ready_at']=$s['tick']+30;
            $s['log'][]=$p['name'].' оказывает помощь союзнику.';
        } elseif ($action === 'cast') {
            $key=$command['spell_id']??'';
            $spell=Catalog::spells()[$key]??null;
            if (!$spell || ($p['level']??1)<$spell['level']) throw new DomainException('spell_locked');
            if (($p['spell_ready'][$key]??0)>$s['tick']) throw new DomainException('spell_cooldown');
            if (($p['mana']??60)<$spell['mana']) throw new DomainException('not_enough_mana');
            if ($key==='mend') {
                $id=(($p['class_id']??'')==='warden')?($command['target_id']??$playerId):$playerId;
                $target=$s['players'][$id]??null;
                if (!$target || $target['outcome']!==null || $target['hp']<=0 || $target['hp']===$target['max_hp']) throw new DomainException('cannot_heal');
                if (abs($p['x']-$target['x'])+abs($p['y']-$target['y'])>3 || !self::visible($s['map'],$p['x'],$p['y'],$target['x'],$target['y'])) throw new DomainException('out_of_range');
                $s['players'][$id]['hp']=min($target['max_hp'],$target['hp']+$spell['heal']+($p['power']??0));
            } else {
                $targets=[];
                foreach ($s['enemies'] as $eid=>$enemy) {
                    if ($enemy['hp']>0 && ($key==='nova' || $eid===($command['target_id']??'')) &&
                        abs($p['x']-$enemy['x'])+abs($p['y']-$enemy['y'])<=$spell['range'] &&
                        self::visible($s['map'],$p['x'],$p['y'],$enemy['x'],$enemy['y'])) $targets[]=$eid;
                }
                if (!$targets) throw new DomainException('no_spell_target');
                foreach ($targets as $eid) {
                    $enemy=&$s['enemies'][$eid];
                    $enemy['hp']=max(0,$enemy['hp']-$spell['damage']-($p['power']??0));
                    if ($key==='frost') $enemy['ready_at']=max($enemy['ready_at'],$s['tick']+20);
                    if ($enemy['hp']===0) self::reward($p,$s['floor'],$enemy['type'],$s['biome']??'mines');
                    unset($enemy);
                }
            }
            $p['mana']=($p['mana']??60)-$spell['mana'];
            $p['spell_ready'][$key]=$s['tick']+$spell['cooldown']; $p['ready_at']=$s['tick']+5;
            $s['log'][]=$p['name'].' casts '.$spell['name'];
        } elseif ($action === 'guard') {
            $p['shield_until'] = $s['tick'] + 20;
            $p['ready_at'] = $s['tick'] + 10;
        } elseif ($action === 'potion') {
            if ($p['potions'] <= 0 || $p['hp'] === $p['max_hp']) {
                throw new DomainException('cannot_heal');
            }
            $p['potions']--;
            $p['hp'] = min($p['max_hp'], $p['hp'] + 40);
            $p['ready_at'] = $s['tick'] + 10;
        } elseif ($action === 'extract' || $action === 'descend') {
            if (abs($p['x'] - $s['exit'][0]) + abs($p['y'] - $s['exit'][1]) > 1) {
                throw new DomainException('exit_required');
            }
            if ($s['floor'] === 3 && ($s['enemies']['boss']['hp'] ?? 0) > 0) {
                throw new DomainException('boss_alive');
            }
            if ($action === 'extract') {
                $p['outcome'] = 'extracted';
            } else {
                if ($s['floor'] >= 3) {
                    throw new DomainException('last_floor');
                }
                foreach ($s['players'] as $member) {
                    if ($member['outcome'] === null && $member['hp']>0 && abs($member['x'] - $s['exit'][0]) + abs($member['y'] - $s['exit'][1]) > 2) {
                        throw new DomainException('party_not_ready');
                    }
                }
                unset($p);
                if (($s['shrine_charges']??0)>0) foreach ($s['players'] as &$member) {
                    if ($member['outcome']===null && $member['hp']===0) {
                        $member['hp']=intdiv($member['max_hp'],2);unset($member['downed_until']);$s['shrine_charges']--;break;
                    }
                }
                unset($member);
                $s['floor']++;
                $s = self::floor($s);
            }
        } else {
            throw new DomainException('unknown_action');
        }
        unset($p);

        return self::finish($s);
    }

    public static function tick(array $s): array
    {
        if ($s['status'] !== 'active') {
            return $s;
        }
        $s['tick']++;
        if ($s['tick']%10===0) foreach ($s['players'] as &$p) {
            $p['mana']=min($p['max_mana']??60,($p['mana']??60)+3);
        }
        unset($p);
        ksort($s['players'], SORT_STRING);
        ksort($s['enemies'], SORT_STRING);
        foreach (array_keys($s['enemies']) as $id) {
            $e = $s['enemies'][$id];
            if ($e['hp'] <= 0 || $e['ready_at'] > $s['tick']) {
                continue;
            }
            $target = null;
            $distance = PHP_INT_MAX;
            foreach ($s['players'] as $pid => $p) {
                if ($p['outcome'] !== null || $p['hp']<=0) {
                    continue;
                }
                $d = abs($p['x'] - $e['x']) + abs($p['y'] - $e['y']);
                if ($d < $distance) {
                    $distance = $d;
                    $target = $pid;
                }
            }
            if ($target === null || $distance > 9) {
                continue;
            }
            $p = &$s['players'][$target];
            $range = in_array($e['type'], ['archer', 'spitter'], true) ? 4 : 1;
            if ($distance <= $range && self::visible($s['map'], $e['x'], $e['y'], $p['x'], $p['y'])) {
                $damage = ($e['type'] === 'warden' ? 12 : ($e['type'] === 'brute' ? 8 : 4));
                $damage=max(1,$damage-($p['armor']??0));
                if ($p['shield_until'] > $s['tick']) {
                    $damage = intdiv($damage, 2);
                }
                $p['hp'] = max(0, $p['hp'] - $damage);
                unset($p['reviving']);
                $s['log'][] = $e['type'].' hit '.$p['name'].' for '.$damage;
                if ($p['hp'] === 0) {
                    $p['downed_until']=$s['tick']+200;
                }
                $s['enemies'][$id]['ready_at'] = $s['tick'] + 15;
            } else {
                $candidates = [[$e['x'] + 1, $e['y']], [$e['x'] - 1, $e['y']], [$e['x'], $e['y'] + 1], [$e['x'], $e['y'] - 1]];
                usort($candidates, static fn ($a, $b) => (abs($a[0] - $p['x']) + abs($a[1] - $p['y'])) <=> (abs($b[0] - $p['x']) + abs($b[1] - $p['y'])));
                foreach ($candidates as [$x,$y]) {
                    if (self::free($s, $x, $y)) {
                        $s['enemies'][$id]['x'] = $x;
                        $s['enemies'][$id]['y'] = $y;
                        break;
                    }
                }
                $s['enemies'][$id]['ready_at'] = $s['tick'] + 6;
            }
            unset($p);
        }
        if ($s['tick'] >= 36000) {
            foreach ($s['players'] as &$p) {
                if ($p['outcome'] === null) {
                    $p['outcome'] = 'defeated';
                }
            }
        }
        unset($p);

        foreach ($s['players'] as &$p) {
            $channel=$p['reviving']??null;
            if ($channel && $channel['complete_at']<=$s['tick']) {
                $target=&$s['players'][$channel['target']];
                if ($p['outcome']===null && $p['hp']>0 && $target['outcome']===null && $target['hp']===0 &&
                    ($target['downed_until']??0)>=$s['tick'] && abs($p['x']-$target['x'])+abs($p['y']-$target['y'])<=1) {
                    $target['hp']=max(1,intdiv($target['max_hp'],3));unset($target['downed_until']);
                    $s['log'][]=$target['name'].' снова в строю.';
                }
                unset($target,$p['reviving']);
            }
        }
        unset($p);

        return self::finish($s);
    }

    private static function finish(array $s): array
    {
        if (!array_filter($s['players'],static fn($p)=>$p['outcome']===null && $p['hp']>0)) {
            foreach($s['players'] as &$p) if($p['outcome']===null) $p['outcome']='defeated';
            unset($p);
        }
        $s['log'] = array_slice($s['log'], -12);
        if (! array_filter($s['players'], static fn ($p) => $p['outcome'] === null)) {
            $s['status'] = 'completed';
        }

        return $s;
    }

    private static function reward(array &$p,int $floor,string $type,string $biome='mines'): void
    {
        $scale=Catalog::biomes()[$biome]['reward'];
        $p['gold']+=5*$floor*$scale; $p['xp']+=($type==='warden'?80:10)*$scale; $p['kills']++;
        // Every second kill yields a deterministic item; the boss guarantees a shield.
        if ($type==='warden' || $p['kills']%2===0) {
            $pool=$floor===1?['ember_ring','iron_sword','leather']:['steel_sword','chainmail','ash_staff'];
            $key=$type==='warden'?'warden_shield':$pool[(intdiv($p['kills'],2)-1)%count($pool)];
            $p['loot'][]=$key;
        }
    }

    private static function free(array $s, int $x, int $y, ?string $except = null): bool
    {
        if (! Dungeon::walkable($s['map'], $x, $y)) {
            return false;
        }
        foreach ($s['enemies'] as $e) {
            if ($e['hp'] > 0 && $e['x'] === $x && $e['y'] === $y) {
                return false;
            }
        }
        foreach ($s['players'] as $id => $p) {
            if ($id !== $except && $p['outcome'] === null && $p['x'] === $x && $p['y'] === $y) {
                return false;
            }
        }

        return true;
    }

    private static function visible(array $map, int $x, int $y, int $tx, int $ty): bool
    {
        $dx = abs($tx - $x);
        $sx = $x < $tx ? 1 : -1;
        $dy = -abs($ty - $y);
        $sy = $y < $ty ? 1 : -1;
        $err = $dx + $dy;
        while (true) {
            if (! Dungeon::walkable($map, $x, $y)) {
                return false;
            }
            if ($x === $tx && $y === $ty) {
                return true;
            }
            $e = 2 * $err;
            if ($e >= $dy) {
                $err += $dy;
                $x += $sx;
            } if ($e <= $dx) {
                $err += $dx;
                $y += $sy;
            }
        }
    }

    public static function view(array $s, string $playerId): array
    {
        $p = $s['players'][$playerId];
        $map = array_fill(0, 32, str_repeat(' ', 32));
        for ($y = max(0, $p['y'] - 8); $y <= min(31, $p['y'] + 8); $y++) {
            for ($x = max(0, $p['x'] - 8); $x <= min(31, $p['x'] + 8); $x++) {
                // Reveal adjacent walls, but never objects behind them.
                if (abs($x - $p['x']) + abs($y - $p['y']) <= 8) {
                    if (self::visible($s['map'], $p['x'], $p['y'], $x, $y)) {
                        $map[$y][$x] = $s['map'][$y][$x];
                    } elseif ($s['map'][$y][$x] === '#') {
                        foreach ([[0, 1], [0, -1], [1, 0], [-1, 0]] as [$dx,$dy]) {
                            if (self::visible($s['map'], $p['x'], $p['y'], $x + $dx, $y + $dy)) {
                                $map[$y][$x] = '#';
                                break;
                            }
                        }
                    }
                }
            }
        }
        $enemies = [];
        foreach ($s['enemies'] as $e) {
            if ($e['hp'] > 0 && $map[$e['y']][$e['x']] === '.') {
                $enemies[] = $e;
            }
        }
        $players = [];
        foreach ($s['players'] as $id => $member) {
            if ($id === $playerId || $map[$member['y']][$member['x']] === '.') {
                $players[] = $member;
            }
        }
        $exit = $map[$s['exit'][1]][$s['exit'][0]] === '.' ? $s['exit'] : null;

        return ['tick' => (string) $s['tick'], 'floor' => $s['floor'], 'biome'=>$s['biome']??'mines','biome_name'=>Catalog::biomes()[$s['biome']??'mines']['name'], 'status' => $s['status'],
            'map' => $map, 'exit' => $exit, 'self' => $p, 'players' => $players, 'enemies' => $enemies, 'log' => $s['log']];
    }
}
