<?php

declare(strict_types=1);

namespace GrimHollow\Core;

use DomainException;

final class Game
{
    public const CONTENT_VERSION = 'campaign-004';

    /** A command that arrives this many ticks before the hero is ready is buffered, not rejected. */
    public const QUEUE_WINDOW = 3;

    private const MOVE_COOLDOWN = 2;

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
            $state['players'][$id]['essence']=0;
            $state['players'][$id]['slain']=[];
        }

        return self::floor($state);
    }

    public static function lastFloor(array $s): int
    {
        return self::biome($s)['floors'];
    }

    private static function biome(array $s): array
    {
        return Catalog::biomes()[$s['biome'] ?? 'mines'];
    }

    private static function floor(array $s): array
    {
        $biome = self::biome($s);
        $dungeon = Dungeon::generate($s['seed']+$biome['chapter']*104729, $s['floor']);
        $s['map'] = $dungeon['map'];
        $s['exit'] = $dungeon['exit'];
        $rng = new Random($dungeon['rng']);
        $s['enemies'] = [];
        $cells = [];
        for ($y = 1; $y < 31; $y++) {
            for ($x = 1; $x < 31; $x++) {
                if (Dungeon::walkable($s['map'], $x, $y) && abs($x - 5) + abs($y - 5) > 8 && [$x, $y] !== $s['exit']) {
                    $cells[] = [$x, $y];
                }
            }
        }
        $roster = $biome['roster'];
        for ($i = 0; $i < min(count($cells), 4 + $s['floor'] * 2); $i++) {
            $index = $rng->next(0, count($cells) - 1);
            [$x,$y] = $cells[$index];
            array_splice($cells, $index, 1);
            $type = $roster[$rng->next(0, count($roster) - 1)];
            $elite = $rng->next(1, 100) <= 6 + $s['floor'] * 2;
            $s['enemies']['e'.$i] = self::spawn($s, 'e'.$i, $type, $x, $y, $elite);
        }
        if ($s['floor'] === self::lastFloor($s)) {
            [$x,$y] = $s['exit'];
            $s['enemies']['boss'] = self::spawn($s, 'boss', $biome['boss_type'], $x, $y, false);
        }
        // Furniture: chests and a spring on every floor, a fury altar deeper down, more traps as you descend.
        $plan = array_merge(array_fill(0, $s['floor'] >= 2 ? 3 : 2, 'chest'), ['fountain'], $s['floor'] >= 2 ? ['shrine'] : [],
            array_fill(0, 2 + $s['floor'], 'trap'));
        $s['objects'] = [];
        foreach ($plan as $n => $type) {
            if (! $cells) break;
            $index = $rng->next(0, count($cells) - 1);
            [$x,$y] = $cells[$index];
            array_splice($cells, $index, 1);
            $s['objects']['o'.$n] = ['id' => 'o'.$n, 'type' => $type, 'x' => $x, 'y' => $y, 'used' => false];
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
        $s['log'][] = 'Этаж '.$s['floor'].' из '.self::lastFloor($s).': '.$biome['name'];

        return $s;
    }

    private static function spawn(array $s, string $id, string $type, int $x, int $y, bool $elite, bool $summoned = false): array
    {
        $def = Catalog::enemy($type);
        $biome = self::biome($s);
        $boss = $def['boss'] ?? false;
        $hp = $def['hp'] + ($boss ? $biome['enemy_hp'] * 3 : 5 * $s['floor'] + $biome['enemy_hp']);
        if ($elite) $hp = intdiv($hp * 8, 5);
        $e = ['id' => $id, 'type' => $type, 'name' => ($elite ? 'Матёрый ' : '').$def['name'], 'x' => $x, 'y' => $y,
            'hp' => $hp, 'max_hp' => $hp, 'ready_at' => 0];
        if ($elite) $e['elite'] = true;
        if ($boss) {
            $e['boss'] = true;
            $e['slam_at'] = $s['tick'] + 60;
            $e['summon_at'] = $s['tick'] + 100;
        }
        if ($summoned) {
            $e['summoned'] = true;
            $e['ready_at'] = $s['tick'] + 10;
        }

        return $e;
    }

    /**
     * Network entry point: executes the command, or buffers it when it arrives just before the
     * hero's cooldown ends (the tick then executes it). Returns [state, 'executed'|'queued'].
     */
    public static function submit(array $s, string $playerId, array $command): array
    {
        $p = $s['players'][$playerId] ?? null;
        if ($s['status'] === 'active' && $p && $p['outcome'] === null && $p['hp'] > 0 &&
            $p['ready_at'] > $s['tick'] && $p['ready_at'] - $s['tick'] <= self::QUEUE_WINDOW) {
            $s['players'][$playerId]['queued'] = $command;

            return [$s, 'queued'];
        }

        return [self::command($s, $playerId, $command), 'executed'];
    }

    public static function command(array $s, string $playerId, array $command): array
    {
        if ($s['status'] !== 'active' || ! isset($s['players'][$playerId])) {
            throw new DomainException('not_active');
        }
        $s = self::order($s);
        $p = &$s['players'][$playerId];
        if ($p['outcome'] !== null || $p['hp'] <= 0) {
            throw new DomainException('not_alive');
        }
        if ($p['ready_at'] > $s['tick']) {
            throw new DomainException('cooldown');
        }
        unset($p['queued']);
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
            $p['ready_at'] = $s['tick'] + self::MOVE_COOLDOWN;
            foreach ($s['objects'] ?? [] as $oid => $o) {
                if ($o['type'] === 'trap' && ! $o['used'] && $o['x'] === $x && $o['y'] === $y) {
                    $s['objects'][$oid]['used'] = true;
                    self::hurt($s, $playerId, 6 + 3 * $s['floor'] + self::biome($s)['enemy_damage'], 'Ловушка', false);
                }
            }
        } elseif ($action === 'attack' || $action === 'bash') {
            $id = $command['target_id'] ?? '';
            if (! isset($s['enemies'][$id]) || $s['enemies'][$id]['hp'] <= 0) {
                throw new DomainException('invalid_target');
            }
            $e = $s['enemies'][$id];
            $range=($action==='attack' && ($p['class_id']??'')==='ranger')?5:1;
            if (abs($p['x'] - $e['x']) + abs($p['y'] - $e['y']) > $range || !self::visible($s['map'],$p['x'],$p['y'],$e['x'],$e['y'])) {
                throw new DomainException('out_of_range');
            }
            $rng = new Random($s['rng']);
            $damage = $rng->next(10, 16)+($p['damage_bonus']??0)+(($p['might_until']??0)>$s['tick']?5:0);
            $s['rng'] = $rng->state;
            $p['ready_at'] = $s['tick'] + ($action === 'bash' ? 12 : 6);
            if ($action === 'bash') {
                $s['enemies'][$id]['ready_at'] = max($e['ready_at'], $s['tick'] + 10);
            }
            self::strike($s, $playerId, $id, $damage, true);
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
            $power = $p['power'] ?? 0;
            if ($spell['kind'] === 'heal') {
                $id=(($p['class_id']??'')==='warden')?($command['target_id']??$playerId):$playerId;
                $target=$s['players'][$id]??null;
                if (!$target || $target['outcome']!==null || $target['hp']<=0 || $target['hp']===$target['max_hp']) throw new DomainException('cannot_heal');
                if (abs($p['x']-$target['x'])+abs($p['y']-$target['y'])>3 || !self::visible($s['map'],$p['x'],$p['y'],$target['x'],$target['y'])) throw new DomainException('out_of_range');
                $s['players'][$id]['hp']=min($target['max_hp'],$target['hp']+$spell['heal']+$power);
            } elseif ($spell['kind'] === 'barrier') {
                foreach ($s['players'] as $id => $member) {
                    if ($member['outcome'] === null && $member['hp'] > 0 && abs($p['x'] - $member['x']) + abs($p['y'] - $member['y']) <= $spell['range'] &&
                        self::visible($s['map'], $p['x'], $p['y'], $member['x'], $member['y'])) {
                        $s['players'][$id]['shield_until'] = max($member['shield_until'] ?? 0, $s['tick'] + $spell['duration']);
                    }
                }
            } else {
                $near = [];
                foreach ($s['enemies'] as $eid => $enemy) {
                    $d = abs($p['x'] - $enemy['x']) + abs($p['y'] - $enemy['y']);
                    if ($enemy['hp'] > 0 && $d <= $spell['range'] && self::visible($s['map'], $p['x'], $p['y'], $enemy['x'], $enemy['y'])) {
                        $near[$eid] = $d;
                    }
                }
                $targets = match ($spell['kind']) {
                    'nova' => array_keys($near),
                    'chain' => self::nearest($near, 3),
                    default => isset($near[$command['target_id'] ?? '']) ? [$command['target_id']] : [],
                };
                if (!$targets) throw new DomainException('no_spell_target');
                $center = $s['enemies'][$targets[0]];
                foreach ($targets as $eid) {
                    self::strike($s, $playerId, $eid, $spell['damage'] + $power, false);
                    if ($s['enemies'][$eid]['hp'] > 0) {
                        if (isset($spell['freeze'])) $s['enemies'][$eid]['ready_at'] = max($s['enemies'][$eid]['ready_at'], $s['tick'] + $spell['freeze']);
                        if (isset($spell['poison'])) {
                            $s['enemies'][$eid]['poison_until'] = $s['tick'] + 60;
                            $s['enemies'][$eid]['poison_dmg'] = $spell['poison'] + intdiv($power, 3);
                            $s['enemies'][$eid]['poisoner'] = $playerId;
                        }
                    }
                }
                if ($spell['kind'] === 'meteor') {
                    foreach ($s['enemies'] as $eid => $enemy) {
                        if ($eid !== $targets[0] && $enemy['hp'] > 0 && abs($enemy['x'] - $center['x']) + abs($enemy['y'] - $center['y']) <= 1) {
                            self::strike($s, $playerId, $eid, intdiv($spell['damage'] + $power, 2), false);
                        }
                    }
                }
            }
            $p['mana']=($p['mana']??60)-$spell['mana'];
            $p['spell_ready'][$key]=$s['tick']+$spell['cooldown']; $p['ready_at']=$s['tick']+5;
            $s['log'][]=$p['name'].': '.$spell['name'];
        } elseif ($action === 'guard') {
            $p['shield_until'] = $s['tick'] + 20;
            $p['ready_at'] = $s['tick'] + 10;
        } elseif ($action === 'potion') {
            if ($p['potions'] <= 0 || $p['hp'] === $p['max_hp']) {
                throw new DomainException('cannot_heal');
            }
            $p['potions']--;
            $p['hp'] = min($p['max_hp'], $p['hp'] + 40);
            unset($p['poison_until']);
            $p['ready_at'] = $s['tick'] + 10;
        } elseif ($action === 'interact') {
            $found = null;
            foreach ($s['objects'] ?? [] as $oid => $o) {
                if (! $o['used'] && $o['type'] !== 'trap' && abs($p['x'] - $o['x']) + abs($p['y'] - $o['y']) <= 1) {
                    $found = $oid;
                    break;
                }
            }
            if ($found === null) throw new DomainException('no_object');
            $s['objects'][$found]['used'] = true;
            $type = $s['objects'][$found]['type'];
            $biome = self::biome($s);
            if ($type === 'chest') {
                $rng = new Random($s['rng']);
                $gold = $rng->next(6, 12) * $s['floor'] * $biome['reward'];
                $p['gold'] += $gold;
                $p['potions'] = min(9, $p['potions'] + 1);
                $text = $p['name'].' открывает сундук: '.$gold.' крон и зелье';
                if ($rng->next(1, 100) <= 40) {
                    $pool = $biome['loot'][$s['floor'] === 1 ? 0 : 1];
                    $item = $pool[$rng->next(0, count($pool) - 1)];
                    $p['loot'][] = $item;
                    $text .= ', '.Catalog::items()[$item]['name'];
                }
                $s['rng'] = $rng->state;
                $s['log'][] = $text.'.';
            } elseif ($type === 'fountain') {
                $p['hp'] = min($p['max_hp'], $p['hp'] + intdiv($p['max_hp'], 2));
                $p['mana'] = $p['max_mana'] ?? 60;
                unset($p['poison_until']);
                $s['log'][] = $p['name'].' пьёт из родника.';
            } else {
                $p['might_until'] = $s['tick'] + 300;
                $s['log'][] = $p['name'].' принимает ярость алтаря: +5 к урону.';
            }
            $p['ready_at'] = $s['tick'] + 4;
        } elseif ($action === 'extract' || $action === 'descend') {
            if (abs($p['x'] - $s['exit'][0]) + abs($p['y'] - $s['exit'][1]) > 1) {
                throw new DomainException('exit_required');
            }
            $last = self::lastFloor($s);
            if ($s['floor'] === $last && ($s['enemies']['boss']['hp'] ?? 0) > 0) {
                throw new DomainException('boss_alive');
            }
            if ($action === 'extract') {
                $p['outcome'] = 'extracted';
            } else {
                if ($s['floor'] >= $last) {
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
                foreach ($s['players'] as &$member) unset($member['queued'], $member['reviving']);
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

    /** @return list<string> the $count closest enemy ids, ties broken by id. */
    private static function nearest(array $distances, int $count): array
    {
        uksort($distances, static fn ($a, $b) => [$distances[$a], $a] <=> [$distances[$b], $b]);

        return array_slice(array_keys($distances), 0, $count);
    }

    /** Damage an enemy on behalf of a player; physical blows are reduced by the enemy's armour. */
    private static function strike(array &$s, string $playerId, string $enemyId, int $damage, bool $physical): void
    {
        $e = $s['enemies'][$enemyId];
        if ($physical) {
            $damage = max(1, $damage - Catalog::enemy($e['type'])['armor']);
        }
        $e['hp'] = max(0, $e['hp'] - $damage);
        $s['enemies'][$enemyId]['hp'] = $e['hp'];
        $s['log'][] = ($s['players'][$playerId]['name'] ?? 'Яд').' → '.($e['name'] ?? Catalog::enemy($e['type'])['name']).': '.$damage;
        if ($e['hp'] === 0) {
            self::slay($s, $playerId, $e);
        }
    }

    private static function slay(array &$s, string $playerId, array $e): void
    {
        $def = Catalog::enemy($e['type']);
        if (isset($s['players'][$playerId])) {
            self::reward($s['players'][$playerId], $s['floor'], $e, $s['biome'] ?? 'mines');
        }
        if (in_array('explode', $def['specials'] ?? [], true)) {
            $s['log'][] = ($e['name'] ?? $def['name']).' взрывается!';
            foreach ($s['players'] as $pid => $member) {
                if (abs($member['x'] - $e['x']) + abs($member['y'] - $e['y']) <= 1) {
                    self::hurt($s, $pid, 10 + 2 * self::biome($s)['enemy_damage'], 'Взрыв', false);
                }
            }
        }
        if ($e['boss'] ?? false) {
            $s['log'][] = $def['name'].' повержен! Огонь можно забрать у выхода.';
        }
    }

    private static function hurt(array &$s, string $playerId, int $damage, string $source, bool $armor): void
    {
        $p = &$s['players'][$playerId];
        if ($p['outcome'] !== null || $p['hp'] <= 0) {
            return;
        }
        if ($armor) {
            $damage = max(1, $damage - ($p['armor'] ?? 0));
        }
        if (($p['shield_until'] ?? 0) > $s['tick']) {
            $damage = intdiv($damage, 2);
        }
        $p['hp'] = max(0, $p['hp'] - $damage);
        unset($p['reviving']);
        $s['log'][] = $source.' → '.$p['name'].': '.$damage;
        if ($p['hp'] === 0) {
            $p['downed_until'] = $s['tick'] + 200;
            unset($p['queued']);
        }
        unset($p);
    }

    public static function tick(array $s): array
    {
        if ($s['status'] !== 'active') {
            return $s;
        }
        $s['tick']++;
        $biome = self::biome($s);
        if ($s['tick']%10===0) {
            foreach ($s['players'] as &$p) {
                $p['mana']=min($p['max_mana']??60,($p['mana']??60)+3);
                // Poison and burns never finish a hero off on their own.
                if ($p['outcome'] === null && $p['hp'] > 1 && ($p['poison_until'] ?? 0) > $s['tick']) {
                    $p['hp'] = max(1, $p['hp'] - ($p['poison_dmg'] ?? 2));
                }
            }
            unset($p);
            foreach (array_keys($s['enemies']) as $id) {
                $e = $s['enemies'][$id];
                if ($e['hp'] > 0 && ($e['poison_until'] ?? 0) > $s['tick']) {
                    self::strike($s, $e['poisoner'] ?? '', $id, $e['poison_dmg'] ?? 3, false);
                }
            }
        }
        $s = self::order($s);
        // Buffered commands run as soon as their hero is ready.
        foreach (array_keys($s['players']) as $pid) {
            $queued = $s['players'][$pid]['queued'] ?? null;
            if ($queued === null || $s['players'][$pid]['ready_at'] > $s['tick']) {
                continue;
            }
            unset($s['players'][$pid]['queued']);
            try {
                $s = self::command($s, $pid, $queued);
            } catch (DomainException) {
                // The world moved on (target died, path blocked): the buffered command lapses.
            }
            if ($s['status'] !== 'active') {
                return $s;
            }
        }
        foreach (array_keys($s['enemies']) as $id) {
            $e = $s['enemies'][$id];
            if ($e['hp'] <= 0 || $e['ready_at'] > $s['tick']) {
                continue;
            }
            $def = Catalog::enemy($e['type']);
            $specials = $def['specials'] ?? [];
            $boss = $e['boss'] ?? false;
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
            if ($target === null || $distance > ($boss ? 12 : 9)) {
                continue;
            }
            if (in_array('heal', $specials, true)) {
                foreach ($s['enemies'] as $aid => $ally) {
                    if ($aid !== $id && $ally['hp'] > 0 && $ally['hp'] < ($ally['max_hp'] ?? $ally['hp']) &&
                        abs($ally['x'] - $e['x']) + abs($ally['y'] - $e['y']) <= 4) {
                        $s['enemies'][$aid]['hp'] = min($ally['max_hp'], $ally['hp'] + 10 + 2 * $biome['enemy_damage']);
                        $s['enemies'][$id]['ready_at'] = $s['tick'] + $def['attack'];
                        $s['log'][] = ($e['name'] ?? $def['name']).' лечит союзника.';
                        continue 2;
                    }
                }
            }
            if ($boss && in_array('summon', $specials, true) && $s['tick'] >= ($e['summon_at'] ?? 0)) {
                $s['enemies'][$id]['summon_at'] = $s['tick'] + 100;
                $alive = count(array_filter($s['enemies'], static fn ($m) => ($m['summoned'] ?? false) && $m['hp'] > 0));
                if ($alive < 3) {
                    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                        if (self::free($s, $e['x'] + $dx, $e['y'] + $dy)) {
                            $sid = 's'.$s['tick'];
                            $s['enemies'][$sid] = self::spawn($s, $sid, $def['summon'], $e['x'] + $dx, $e['y'] + $dy, false, true);
                            $s['enemies'][$id]['ready_at'] = $s['tick'] + 10;
                            $s['log'][] = $def['name'].' призывает подмогу!';
                            continue 2;
                        }
                    }
                }
            }
            $damage = $def['damage'] + $biome['enemy_damage'] + (($e['elite'] ?? false) ? 2 : 0);
            if ($boss && in_array('slam', $specials, true) && $s['tick'] >= ($e['slam_at'] ?? 0) && $distance <= 2) {
                $s['log'][] = $def['name'].' обрушивает сокрушительный удар!';
                foreach ($s['players'] as $pid => $p) {
                    if (abs($p['x'] - $e['x']) + abs($p['y'] - $e['y']) <= 2) {
                        self::hurt($s, $pid, intdiv($damage * 3, 4), $def['name'], true);
                    }
                }
                $s['enemies'][$id]['slam_at'] = $s['tick'] + 60;
                $s['enemies'][$id]['ready_at'] = $s['tick'] + 12;
                continue;
            }
            $p = $s['players'][$target];
            if ($distance <= $def['range'] && self::visible($s['map'], $e['x'], $e['y'], $p['x'], $p['y'])) {
                self::hurt($s, $target, $damage, $e['name'] ?? $def['name'], true);
                $victim = &$s['players'][$target];
                if ($victim['hp'] > 0) {
                    if (in_array('poison', $specials, true)) {
                        $victim['poison_until'] = $s['tick'] + 50;
                        $victim['poison_dmg'] = 2 + intdiv($biome['enemy_damage'], 2);
                    }
                    if (in_array('chill', $specials, true)) {
                        $victim['ready_at'] = max($victim['ready_at'], $s['tick'] + 6);
                    }
                    if (in_array('drain', $specials, true)) {
                        $victim['mana'] = max(0, ($victim['mana'] ?? 0) - 10);
                    }
                }
                unset($victim);
                $s['enemies'][$id]['ready_at'] = $s['tick'] + $def['attack'];
            } elseif ($def['step'] > 0) {
                $candidates = [[$e['x'] + 1, $e['y']], [$e['x'] - 1, $e['y']], [$e['x'], $e['y'] + 1], [$e['x'], $e['y'] - 1]];
                usort($candidates, static fn ($a, $b) => (abs($a[0] - $p['x']) + abs($a[1] - $p['y'])) <=> (abs($b[0] - $p['x']) + abs($b[1] - $p['y'])));
                foreach ($candidates as [$x,$y]) {
                    if (self::free($s, $x, $y)) {
                        $s['enemies'][$id]['x'] = $x;
                        $s['enemies'][$id]['y'] = $y;
                        break;
                    }
                }
                $s['enemies'][$id]['ready_at'] = $s['tick'] + $def['step'];
            } else {
                $s['enemies'][$id]['ready_at'] = $s['tick'] + 5;
            }
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

    /** Collections in key order, so live (decoded) and replayed (in-memory) states iterate alike. */
    private static function order(array $s): array
    {
        ksort($s['players'], SORT_STRING);
        ksort($s['enemies'], SORT_STRING);
        if (isset($s['objects'])) ksort($s['objects'], SORT_STRING);

        return $s;
    }

    private static function finish(array $s): array
    {
        $s = self::order($s);
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

    private static function reward(array &$p, int $floor, array $e, string $biome='mines'): void
    {
        $area = Catalog::biomes()[$biome];
        $scale = $area['reward'];
        $def = Catalog::enemy($e['type']);
        if ($e['summoned'] ?? false) {
            $p['xp'] += 2 * $scale;

            return;
        }
        $boss = $def['boss'] ?? false;
        $elite = $e['elite'] ?? false;
        $bonus = $elite ? 2 : 1;
        $p['gold'] += 5 * $floor * $scale * $bonus;
        $p['xp'] += $def['xp'] * $scale * $bonus;
        $p['kills']++;
        $p['slain'][$e['type']] = ($p['slain'][$e['type']] ?? 0) + 1;
        if ($boss) {
            $p['essence'] = ($p['essence'] ?? 0) + 2 + $area['chapter'];
        } elseif ($elite) {
            $p['essence'] = ($p['essence'] ?? 0) + 1;
        }
        // Every second kill and every elite yield a deterministic item; the boss guarantees its unique.
        if ($boss || $elite || $p['kills'] % 2 === 0) {
            $pool = $area['loot'][$floor === 1 ? 0 : 1];
            $p['loot'][] = $boss ? $area['unique'] : $pool[(intdiv($p['kills'], 2) - 1 + ($elite ? 1 : 0) + count($pool)) % count($pool)];
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
        // Each line of sight is traced once; wall cells re-use their neighbours' results.
        $seen = [];
        $sees = static function (int $x, int $y) use (&$seen, $s, $p): bool {
            return $seen[$y * 64 + $x + 65] ??= self::visible($s['map'], $p['x'], $p['y'], $x, $y);
        };
        for ($y = max(0, $p['y'] - 8); $y <= min(31, $p['y'] + 8); $y++) {
            for ($x = max(0, $p['x'] - 8); $x <= min(31, $p['x'] + 8); $x++) {
                // Reveal adjacent walls, but never objects behind them.
                if (abs($x - $p['x']) + abs($y - $p['y']) <= 8) {
                    if ($sees($x, $y)) {
                        $map[$y][$x] = $s['map'][$y][$x];
                    } elseif ($s['map'][$y][$x] === '#') {
                        foreach ([[0, 1], [0, -1], [1, 0], [-1, 0]] as [$dx,$dy]) {
                            if ($sees($x + $dx, $y + $dy)) {
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
        $objects = [];
        foreach ($s['objects'] ?? [] as $o) {
            if ($map[$o['y']][$o['x']] === '.') {
                $objects[] = $o;
            }
        }
        $exit = $map[$s['exit'][1]][$s['exit'][0]] === '.' ? $s['exit'] : null;
        $biome = self::biome($s);

        return ['tick' => (string) $s['tick'], 'floor' => $s['floor'], 'last_floor' => self::lastFloor($s), 'biome'=>$s['biome']??'mines',
            'biome_name'=>$biome['name'], 'boss_name' => $biome['boss'], 'status' => $s['status'],
            'map' => $map, 'exit' => $exit, 'self' => $p, 'players' => $players, 'enemies' => $enemies, 'objects' => $objects, 'log' => $s['log']];
    }
}
