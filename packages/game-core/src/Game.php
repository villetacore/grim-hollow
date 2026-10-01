<?php

declare(strict_types=1);

namespace GrimHollow\Core;

use DomainException;

final class Game
{
    public const CONTENT_VERSION = 'campaign-006';

    /** A command that arrives this many ticks before the hero is ready is buffered, not rejected. */
    public const QUEUE_WINDOW = 3;

    /** Duels last at most three minutes; then both duelists draw. */
    public const DUEL_TICKS = 1800;

    /** Largest party for an expedition. Monsters grow tougher and more numerous with every member. */
    public const MAX_PARTY = 8;

    /** Party members' start cells inside the first room (the first four match 0.5 layouts). */
    public const SPAWNS = [[5, 5], [6, 5], [7, 5], [8, 5], [5, 6], [6, 6], [7, 6], [8, 6]];

    /** Profile fields a lobby keeps for each member when the party is rebuilt on join. */
    public const PROFILE_KEYS = ['name', 'class_id', 'origin', 'mentor', 'level', 'max_hp', 'max_mana', 'damage_bonus', 'armor', 'power',
        'crit', 'leech', 'thorns', 'regen', 'greed', 'traits', 'schools', 'sets', 'elixir', 'equipment', 'potions'];

    private const MOVE_COOLDOWN = 2;

    /** Per-skill cooldowns (ticks) on top of the shared action cooldown. */
    private const SKILL_COOLDOWNS = ['bash' => 40, 'guard' => 60, 'potion' => 30];

    private const DIRS = ['north' => [0, -1], 'south' => [0, 1], 'west' => [-1, 0], 'east' => [1, 0]];

    public static function create(int $seed, array $players, string $biome='mines', string $mode='expedition'): array
    {
        if (!isset(Catalog::biomes()[$biome])) throw new DomainException('unknown_biome');
        if (!in_array($mode, ['expedition', 'duel'], true)) throw new DomainException('unknown_mode');
        if (count($players) > self::MAX_PARTY) throw new DomainException('party_full');
        $state = ['version' => 1, 'content_version' => self::CONTENT_VERSION, 'seed' => $seed, 'mode' => $mode,
            'tick' => 0, 'floor' => 1, 'biome'=>$biome, 'shrine_charges'=>1, 'bosses_slain'=>0, 'status' => 'active', 'players' => [], 'log' => [], 'rng' => $seed];
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
            $state['players'][$id]['reagents']=[];
            $state['players'][$id]['slain']=[];
            $state['players'][$id]['facing']='east';
        }
        // Difficulty follows the party: the rounded average hero level is the depth-1 monster level.
        $levels = array_map(static fn ($p) => (int) ($p['level'] ?? 1), $state['players']);
        $state['party_level'] = $levels ? max(1, (int) round(array_sum($levels) / count($levels))) : 1;

        return $mode === 'duel' ? self::arena($state) : self::floor($state);
    }

    /** Next floor that ends with a boss; every area repeats its cycle forever. */
    public static function bossFloor(array $s): int
    {
        $floors = self::biome($s)['floors'];

        return intdiv($s['floor'] + $floors - 1, $floors) * $floors;
    }

    /** Monster level of the current floor. */
    public static function depthLevel(array $s): int
    {
        return ($s['party_level'] ?? 1) + $s['floor'] - 1;
    }

    /** Whether a hero has a combat trait from class, mentor or origin (older snapshots fall back to the class). */
    public static function has(array $p, string $trait): bool
    {
        return in_array($trait, $p['traits'] ?? [], true) || in_array($trait, Catalog::classes()[$p['class_id'] ?? '']['traits'] ?? [], true);
    }

    private static function biome(array $s): array
    {
        return Catalog::biomes()[$s['biome'] ?? 'mines'];
    }

    private static function bossType(array $s): string
    {
        $biome = self::biome($s);
        $cycle = intdiv($s['floor'] - 1, $biome['floors']);

        return $cycle === 0 ? $biome['boss_type'] : Catalog::BOSSES[($biome['chapter'] + $cycle) % count(Catalog::BOSSES)];
    }

    /** Combined numeric effect of the current floor's omens. */
    private static function omen(array $s, string $key): float
    {
        $sum = 0;
        foreach ($s['omens'] ?? [] as $omen) $sum += Catalog::omens()[$omen][$key] ?? 0;

        return $sum;
    }

    /** Fighters in the party; monsters scale with it. */
    private static function partySize(array $s): int
    {
        return max(1, count($s['players']));
    }

    /** A pillared hall for two duelists. */
    private static function arena(array $s): array
    {
        $map = array_fill(0, 32, str_repeat('#', 32));
        for ($y = 6; $y <= 17; $y++) {
            for ($x = 4; $x <= 27; $x++) {
                $map[$y][$x] = '.';
            }
        }
        foreach ([[10, 9], [10, 14], [21, 9], [21, 14], [15, 11], [16, 12]] as [$x, $y]) {
            $map[$y][$x] = '#';
        }
        $s['map'] = $map;
        $s['exit'] = [0, 0];
        $s['enemies'] = [];
        $s['objects'] = [];
        $n = 0;
        foreach ($s['players'] as &$p) {
            [$p['x'], $p['y']] = $n++ % 2 === 0 ? [6, 11 + intdiv($n, 3)] : [25, 12 - intdiv($n, 3)];
            $p['facing'] = $p['x'] < 16 ? 'east' : 'west';
            $p['ready_at'] = 30;
        }
        unset($p);
        $s['log'][] = 'Дуэль! Бой начнётся через 3 секунды.';

        return $s;
    }

    private static function floor(array $s): array
    {
        $biome = self::biome($s);
        $level = self::depthLevel($s);
        $dungeon = Dungeon::generate($s['seed']+$biome['chapter']*104729, $s['floor']);
        $s['map'] = $dungeon['map'];
        $s['exit'] = $dungeon['exit'];
        $s['floor_tick'] = $s['tick'];
        $rng = new Random($dungeon['rng']);
        $s['enemies'] = [];
        // Omens from the second floor on: one often, a second one deeper down.
        $s['omens'] = [];
        if ($s['floor'] >= 2) {
            $keys = array_keys(Catalog::omens());
            if ($rng->next(1, 100) <= 40) $s['omens'][] = $keys[$rng->next(0, count($keys) - 1)];
            if ($level >= 15 && $rng->next(1, 100) <= 25) $s['omens'][] = $keys[$rng->next(0, count($keys) - 1)];
            $s['omens'] = array_values(array_unique($s['omens']));
        }
        $cells = [];
        for ($y = 1; $y < 31; $y++) {
            for ($x = 1; $x < 31; $x++) {
                if (Dungeon::walkable($s['map'], $x, $y) && abs($x - 5) + abs($y - 5) > 8 && [$x, $y] !== $s['exit']) {
                    $cells[] = [$x, $y];
                }
            }
        }
        // Past the first boss the area's roster mixes with creatures of the following areas.
        $cycle = intdiv($s['floor'] - 1, $biome['floors']);
        $roster = $biome['roster'];
        $areas = array_values(Catalog::biomes());
        for ($c = 1; $c <= min($cycle, count($areas) - 1); $c++) {
            $roster = array_merge($roster, $areas[($biome['chapter'] + $c) % count($areas)]['roster']);
        }
        $roster = array_values(array_unique($roster));
        $healers = array_values(array_filter($roster, static fn ($t) => in_array('heal', Catalog::enemy($t)['specials'] ?? [], true)));
        $extra = self::partySize($s) - 1;
        $budget = (int) round(min(24 + 2 * $extra, 5 + $s['floor'] + intdiv($level, 3) + 2 * $extra) * (1 + self::omen($s, 'budget')));
        $chimeraChance = $level >= 8 ? (int) min(60, intdiv($level, 2) * (1 + self::omen($s, 'chimera'))) : 0;
        $n = 0;
        while ($n < $budget && $cells) {
            // Packs: lone hunter, pair, flock of one kind, mixed squad or a coven guarded by a healer.
            $roll = $rng->next(1, 100);
            $first = $roster[$rng->next(0, count($roster) - 1)];
            if ($roll <= 40) $pack = [$first];
            elseif ($roll <= 60) $pack = [$first, $first];
            elseif ($roll <= 75) $pack = array_fill(0, $rng->next(3, 4), $first);
            elseif ($roll <= 90) $pack = [$first, $roster[$rng->next(0, count($roster) - 1)], $roster[$rng->next(0, count($roster) - 1)]];
            else $pack = [$healers ? $healers[$rng->next(0, count($healers) - 1)] : $first, $first, $roster[$rng->next(0, count($roster) - 1)]];
            $index = $rng->next(0, count($cells) - 1);
            [$ax, $ay] = $cells[$index];
            foreach ($pack as $member => $type) {
                if ($n >= $budget || ! $cells) break;
                $best = null;
                foreach ($cells as $i => [$x, $y]) {
                    $d = abs($x - $ax) + abs($y - $ay);
                    if ($d <= 2 && ($best === null || $d < $best[0])) $best = [$d, $i];
                }
                if ($best === null) break;
                [$x, $y] = $cells[$best[1]];
                array_splice($cells, $best[1], 1);
                $affixes = self::rollAffixes($rng, $level);
                if ($roll > 90 && $member === 0 && ! $healers) $affixes = array_values(array_unique(array_merge($affixes, ['shaman'])));
                $elite = $rng->next(1, 100) <= 6 + min(20, $s['floor'] * 2) + (int) self::omen($s, 'elite');
                // Deeper down two species may fuse into a chimera.
                $fused = null;
                if ($chimeraChance > 0 && $rng->next(1, 100) <= $chimeraChance) {
                    $other = $roster[$rng->next(0, count($roster) - 1)];
                    if ($other !== $type) $fused = $other;
                }
                $s['enemies']['e'.$n] = self::spawn($s, 'e'.$n, $type, $x, $y, $elite, false, $affixes, $fused);
                $n++;
            }
        }
        if ($s['floor'] === self::bossFloor($s)) {
            [$x,$y] = $s['exit'];
            $s['enemies']['boss'] = self::spawn($s, 'boss', self::bossType($s), $x, $y, false, false, $cycle > 0 ? self::rollAffixes($rng, $level) : []);
        }
        // Furniture: chests and a spring on every floor, a fury altar deeper down, more traps as you descend.
        $plan = array_merge(array_fill(0, ($s['floor'] >= 2 ? 3 : 2) + (int) self::omen($s, 'chests'), 'chest'),
            array_fill(0, 1 + (int) self::omen($s, 'fountains'), 'fountain'),
            array_fill(0, ($s['floor'] >= 2 ? 1 : 0) + (int) self::omen($s, 'shrines'), 'shrine'),
            array_fill(0, min(8, 2 + $s['floor']) * (int) max(1, self::omen($s, 'traps')), 'trap'));
        $s['objects'] = [];
        foreach ($plan as $o => $type) {
            if (! $cells) break;
            $index = $rng->next(0, count($cells) - 1);
            [$x,$y] = $cells[$index];
            array_splice($cells, $index, 1);
            $s['objects']['o'.$o] = ['id' => 'o'.$o, 'type' => $type, 'x' => $x, 'y' => $y, 'used' => false];
        }
        $offset = 0;
        foreach ($s['players'] as &$p) {
            if ($p['outcome'] === null) {
                [$p['x'], $p['y']] = self::SPAWNS[$offset++ % count(self::SPAWNS)];
            }
        }
        unset($p);
        $s['rng'] = $rng->state;
        $text = 'Глубина '.$s['floor'].' ('.$biome['name'].'), уровень врагов '.$level.'. Босс на глубине '.self::bossFloor($s).'.';
        foreach ($s['omens'] as $omen) $text .= ' Знамение: '.Catalog::omens()[$omen]['name'].'.';
        $s['log'][] = $text;

        return $s;
    }

    /** 0–3 monster modifiers; deeper floors roll more of them. */
    private static function rollAffixes(Random $rng, int $level): array
    {
        $count = 0;
        if ($level >= 3 && $rng->next(1, 100) <= min(60, 10 + 3 * $level)) $count++;
        if ($level >= 10 && $rng->next(1, 100) <= 30) $count++;
        if ($level >= 25 && $rng->next(1, 100) <= 20) $count++;
        $keys = array_keys(Catalog::enemyAffixes());
        $chosen = [];
        while (count($chosen) < $count) {
            $chosen[$keys[$rng->next(0, count($keys) - 1)]] = true;
        }

        return array_keys($chosen);
    }

    /** Species definition of a creature, fused with its second half for a chimera. */
    private static function definition(array $e): array
    {
        $def = Catalog::enemy($e['type']);

        return isset($e['fused']) ? Catalog::chimera($e['type'], $e['fused']) + $def : $def;
    }

    private static function spawn(array $s, string $id, string $type, int $x, int $y, bool $elite, bool $summoned = false, array $affixes = [], ?string $fused = null): array
    {
        $def = $fused ? Catalog::chimera($type, $fused) + Catalog::enemy($type) : Catalog::enemy($type);
        $biome = self::biome($s);
        $boss = $def['boss'] ?? false;
        $level = self::depthLevel($s);
        $hp = ($def['hp'] + ($boss ? $biome['enemy_hp'] * 3 : 5 * $level + $biome['enemy_hp'])) * ($boss ? 1 + 0.1 * ($level - 1) : 1 + 0.06 * ($level - 1));
        // Every extra party member adds toughness: a bigger party fights longer battles, not trivial ones.
        $hp *= 1 + ($boss ? 0.25 : 0.12) * (self::partySize($s) - 1);
        $hp *= 1 + self::omen($s, 'hp');
        $damage = $def['damage'] * (1 + 0.07 * ($level - 1)) * (1 + self::omen($s, 'damage'));
        $armor = $def['armor'] + intdiv($level, 8) + (int) self::omen($s, 'armor');
        $range = $def['range'];
        $attack = $def['attack'];
        $step = $def['step'];
        $specials = $def['specials'] ?? [];
        $names = [];
        foreach ($affixes as $key) {
            $mod = Catalog::enemyAffixes()[$key];
            $names[] = $mod['name'];
            $hp *= 1 + ($mod['hp'] ?? 0);
            $damage *= 1 + ($mod['damage'] ?? 0);
            if (isset($mod['armor'])) $armor += $mod['armor'] + intdiv($level, 5);
            if (isset($mod['range'])) $range = max($range, $mod['range']);
            if (isset($mod['step']) && $step > 0) $step = max(2, $step + $mod['step']);
            if (isset($mod['attack'])) $attack = max(5, $attack + $mod['attack']);
            $specials = array_values(array_unique(array_merge($specials, $mod['specials'] ?? [])));
        }
        $hp = max(1, (int) round($hp));
        if ($elite) $hp = intdiv($hp * 8, 5);
        $e = ['id' => $id, 'type' => $type, 'name' => ($elite ? 'Матёрый ' : '').($fused ? 'Химера ' : '').$def['name'].($names ? ': '.implode(', ', $names) : ''),
            'x' => $x, 'y' => $y, 'hp' => $hp, 'max_hp' => $hp, 'ready_at' => 0, 'level' => $level,
            'damage' => (int) round($damage) + $biome['enemy_damage'] + ($elite ? 2 : 0), 'armor' => $armor, 'range' => $range,
            'attack' => $attack, 'step' => $step, 'specials' => $specials];
        if ($fused) $e['fused'] = $fused;
        if ($affixes) $e['affixes'] = array_values($affixes);
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

    /** Instance stat with a catalog fallback for creatures stored before 0.5. */
    private static function stat(array $s, array $e, string $key): mixed
    {
        if (array_key_exists($key, $e)) {
            return $e[$key];
        }
        $def = self::definition($e);
        if ($key === 'damage') {
            return $def['damage'] + self::biome($s)['enemy_damage'] + (($e['elite'] ?? false) ? 2 : 0);
        }

        return $def[$key] ?? ($key === 'specials' ? [] : 0);
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

    /** Everything this player may hit: living monsters, and the other duelists in a duel. */
    private static function hostiles(array $s, string $playerId): array
    {
        $list = [];
        foreach ($s['enemies'] as $id => $e) {
            if ($e['hp'] > 0) $list[$id] = [$e['x'], $e['y']];
        }
        if (($s['mode'] ?? '') === 'duel') {
            foreach ($s['players'] as $id => $p) {
                if ($id !== $playerId && $p['outcome'] === null && $p['hp'] > 0) $list[$id] = [$p['x'], $p['y']];
            }
        }

        return $list;
    }

    private static function skillReady(array $p, string $key, int $tick): void
    {
        if (($p['spell_ready'][$key] ?? 0) > $tick) {
            throw new DomainException('skill_cooldown');
        }
    }

    private static function direction(array $p, array $command): array
    {
        $direction = $command['direction'] ?? ($p['facing'] ?? 'east');
        if (! isset(self::DIRS[$direction])) throw new DomainException('invalid_direction');

        return self::DIRS[$direction];
    }

    /** Magic power behind a hero's spells; the arcane surge trait makes it count half again. */
    private static function spellPower(array $p): int
    {
        $power = $p['power'] ?? 0;

        return self::has($p, 'arcane_surge') ? intdiv($power * 3, 2) : $power;
    }

    /** Poisons a monster on behalf of a player. */
    private static function poisonEnemy(array &$s, string $enemyId, string $playerId, int $damage, int $ticks): void
    {
        if (! isset($s['enemies'][$enemyId]) || $s['enemies'][$enemyId]['hp'] <= 0) return;
        $e = &$s['enemies'][$enemyId];
        $active = ($e['poison_until'] ?? 0) > $s['tick'];
        $e['poison_dmg'] = $active ? max($e['poison_dmg'] ?? 0, $damage) : $damage;
        $e['poison_until'] = max($e['poison_until'] ?? 0, $s['tick'] + $ticks);
        $e['poisoner'] = $playerId;
        unset($e);
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
        $duel = ($s['mode'] ?? '') === 'duel';
        if ($duel && in_array($action, ['interact', 'extract', 'descend', 'revive'], true)) {
            throw new DomainException('not_in_duel');
        }
        if ($action === 'move') {
            if (! isset(self::DIRS[$command['direction'] ?? ''])) {
                throw new DomainException('invalid_direction');
            }
            [$dx,$dy] = self::DIRS[$command['direction']];
            $p['facing'] = $command['direction'];
            $x = $p['x'] + $dx;
            $y = $p['y'] + $dy;
            if (! self::free($s, $x, $y, $playerId)) {
                throw new DomainException('blocked');
            }
            $p['x'] = $x;
            $p['y'] = $y;
            $p['ready_at'] = $s['tick'] + self::MOVE_COOLDOWN;
            self::springTraps($s, $playerId);
        } elseif ($action === 'attack' || $action === 'bash') {
            $id = $command['target_id'] ?? '';
            $hostile = self::hostiles($s, $playerId)[$id] ?? null;
            if ($hostile === null) {
                throw new DomainException('invalid_target');
            }
            if ($action === 'bash') self::skillReady($p, 'bash', $s['tick']);
            $range=($action==='attack' && self::has($p,'ranged'))?5:1;
            if (abs($p['x'] - $hostile[0]) + abs($p['y'] - $hostile[1]) > $range || !self::visible($s['map'],$p['x'],$p['y'],$hostile[0],$hostile[1])) {
                throw new DomainException('out_of_range');
            }
            $rng = new Random($s['rng']);
            $damage = $rng->next(10, 16)+($p['damage_bonus']??0)+(($p['might_until']??0)>$s['tick']?5:0);
            if (self::has($p, 'rage') && $p['hp'] * 2 < $p['max_hp']) {
                $damage = intdiv($damage * 3, 2);
            }
            $backstab = self::has($p, 'backstab');
            $crit = ($p['crit'] ?? 0) + ($backstab ? 10 : 0);
            if ($crit > 0 && $rng->next(1, 100) <= $crit) {
                $damage *= $backstab ? 3 : 2;
                $s['log'][] = $p['name'].': критический удар!';
            }
            $s['rng'] = $rng->state;
            $p['ready_at'] = $s['tick'] + ($action === 'bash' ? 12 : 6);
            if ($action === 'bash') {
                $p['spell_ready']['bash'] = $s['tick'] + self::SKILL_COOLDOWNS['bash'];
                if (isset($s['enemies'][$id])) $s['enemies'][$id]['ready_at'] = max($s['enemies'][$id]['ready_at'], $s['tick'] + 10);
                else $s['players'][$id]['ready_at'] = max($s['players'][$id]['ready_at'], $s['tick'] + 8);
            }
            $venom = self::has($p, 'venom_touch');
            $level = $p['level'] ?? 1;
            self::leech($s, $playerId, self::hit($s, $playerId, $id, $damage, true));
            if ($venom) {
                self::poisonEnemy($s, $id, $playerId, 3 + intdiv($level, 5), 40);
            }
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
            $cost = Catalog::spellCost($spell, $p['schools'] ?? Catalog::build($p['class_id'] ?? 'guardian')['schools']);
            if (($p['mana']??60)<$cost) throw new DomainException('not_enough_mana');
            $power = self::spellPower($p);
            $kind = $spell['kind'];
            if ($kind === 'heal') {
                $id=self::has($p,'ally_heal')?($command['target_id']??$playerId):$playerId;
                $target=$s['players'][$id]??null;
                if ($duel && $id !== $playerId) throw new DomainException('cannot_heal');
                if (!$target || $target['outcome']!==null || $target['hp']<=0 || $target['hp']===$target['max_hp']) throw new DomainException('cannot_heal');
                if (abs($p['x']-$target['x'])+abs($p['y']-$target['y'])>3 || !self::visible($s['map'],$p['x'],$p['y'],$target['x'],$target['y'])) throw new DomainException('out_of_range');
                $s['players'][$id]['hp']=min($target['max_hp'],$target['hp']+$spell['heal']+$power);
            } elseif ($kind === 'sanctuary') {
                $healed = 0;
                foreach ($s['players'] as $id => $member) {
                    if (($id === $playerId || ! $duel) && $member['outcome'] === null && $member['hp'] > 0 && $member['hp'] < $member['max_hp'] &&
                        abs($p['x'] - $member['x']) + abs($p['y'] - $member['y']) <= $spell['range'] &&
                        self::visible($s['map'], $p['x'], $p['y'], $member['x'], $member['y'])) {
                        $s['players'][$id]['hp'] = min($member['max_hp'], $member['hp'] + $spell['heal'] + intdiv($power, 2));
                        $healed++;
                    }
                }
                if ($healed === 0) throw new DomainException('cannot_heal');
            } elseif ($kind === 'rage') {
                $p['might_until'] = max($p['might_until'] ?? 0, $s['tick'] + $spell['duration']);
            } elseif ($kind === 'barrier') {
                foreach ($s['players'] as $id => $member) {
                    if (($id === $playerId || ! $duel) && $member['outcome'] === null && $member['hp'] > 0 &&
                        abs($p['x'] - $member['x']) + abs($p['y'] - $member['y']) <= $spell['range'] &&
                        self::visible($s['map'], $p['x'], $p['y'], $member['x'], $member['y'])) {
                        $s['players'][$id]['shield_until'] = max($member['shield_until'] ?? 0, $s['tick'] + $spell['duration']);
                    }
                }
            } elseif ($kind === 'blink') {
                [$dx, $dy] = self::direction($p, $command);
                $moved = 0;
                while ($moved < $spell['range'] && self::free($s, $p['x'] + $dx, $p['y'] + $dy, $playerId)) {
                    $p['x'] += $dx;
                    $p['y'] += $dy;
                    $moved++;
                }
                if ($moved === 0) throw new DomainException('blocked');
                if (isset($command['direction'])) $p['facing'] = $command['direction'];
            } elseif ($kind === 'wave') {
                [$dx, $dy] = self::direction($p, $command);
                $line = [];
                for ($i = 1, $x = $p['x'] + $dx, $y = $p['y'] + $dy; $i <= $spell['range'] && Dungeon::walkable($s['map'], $x, $y); $i++, $x += $dx, $y += $dy) {
                    $line[$x.','.$y] = true;
                }
                foreach (self::hostiles($s, $playerId) as $tid => [$x, $y]) {
                    if (isset($line[$x.','.$y])) self::hit($s, $playerId, $tid, $spell['damage'] + $power, false);
                }
                if (isset($command['direction'])) $p['facing'] = $command['direction'];
            } else {
                $near = [];
                foreach (self::hostiles($s, $playerId) as $tid => [$x, $y]) {
                    $d = abs($p['x'] - $x) + abs($p['y'] - $y);
                    if ($d <= $spell['range'] && self::visible($s['map'], $p['x'], $p['y'], $x, $y)) {
                        $near[$tid] = $d;
                    }
                }
                $targets = match ($kind) {
                    'nova', 'whirl', 'root' => array_keys($near),
                    'chain' => self::nearest($near, 3, $command['target_id'] ?? null),
                    default => isset($near[$command['target_id'] ?? '']) ? [$command['target_id']] : [],
                };
                if (!$targets) throw new DomainException('no_spell_target');
                $center = self::hostiles($s, $playerId)[$targets[0]];
                foreach ($targets as $tid) {
                    if ($kind === 'whirl') {
                        self::leech($s, $playerId, self::hit($s, $playerId, $tid, $spell['damage'] + ($p['damage_bonus'] ?? 0), true));
                        continue;
                    }
                    $dealt = self::hit($s, $playerId, $tid, $spell['damage'] + $power, false);
                    if ($kind === 'drain') {
                        $p['hp'] = min($p['max_hp'], $p['hp'] + $dealt);
                    }
                    if (isset($s['enemies'][$tid]) && $s['enemies'][$tid]['hp'] > 0) {
                        if (isset($spell['freeze'])) $s['enemies'][$tid]['ready_at'] = max($s['enemies'][$tid]['ready_at'], $s['tick'] + $spell['freeze']);
                        if (isset($spell['poison'])) {
                            $s['enemies'][$tid]['poison_until'] = $s['tick'] + 60;
                            $s['enemies'][$tid]['poison_dmg'] = $spell['poison'] + intdiv($power, 3);
                            $s['enemies'][$tid]['poisoner'] = $playerId;
                        }
                    } elseif (isset($s['players'][$tid]) && $s['players'][$tid]['hp'] > 0) {
                        if (isset($spell['freeze'])) $s['players'][$tid]['ready_at'] = max($s['players'][$tid]['ready_at'], $s['tick'] + intdiv($spell['freeze'], 2));
                        if (isset($spell['poison']) && ! self::has($s['players'][$tid], 'unliving')) {
                            $s['players'][$tid]['poison_until'] = $s['tick'] + 40;
                            $s['players'][$tid]['poison_dmg'] = 2 + intdiv($power, 4);
                        }
                    }
                }
                if ($kind === 'meteor') {
                    foreach (self::hostiles($s, $playerId) as $tid => [$x, $y]) {
                        if ($tid !== $targets[0] && abs($x - $center[0]) + abs($y - $center[1]) <= 1) {
                            self::hit($s, $playerId, $tid, intdiv($spell['damage'] + $power, 2), false);
                        }
                    }
                }
            }
            $p['mana']=($p['mana']??60)-$cost;
            $p['spell_ready'][$key]=$s['tick']+$spell['cooldown']; $p['ready_at']=$s['tick']+5;
            $s['log'][]=$p['name'].': '.$spell['name'];
        } elseif ($action === 'guard') {
            self::skillReady($p, 'guard', $s['tick']);
            $p['shield_until'] = $s['tick'] + 20;
            $p['spell_ready']['guard'] = $s['tick'] + self::SKILL_COOLDOWNS['guard'];
            $p['ready_at'] = $s['tick'] + 10;
        } elseif ($action === 'potion') {
            if ($p['potions'] <= 0 || $p['hp'] === $p['max_hp']) {
                throw new DomainException('cannot_heal');
            }
            self::skillReady($p, 'potion', $s['tick']);
            $p['potions']--;
            $p['hp'] = min($p['max_hp'], $p['hp'] + 40 + intdiv($p['max_hp'], 5));
            unset($p['poison_until']);
            $p['spell_ready']['potion'] = $s['tick'] + self::SKILL_COOLDOWNS['potion'];
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
                $gold = $rng->next(6, 12) * self::depthLevel($s) * $biome['reward'];
                $p['gold'] += $gold;
                $p['potions'] = min(9, $p['potions'] + 1);
                $text = $p['name'].' открывает сундук: '.$gold.' крон и зелье';
                if ($rng->next(1, 100) <= 40) {
                    $item = Catalog::generate($rng, max(1, intdiv(self::depthLevel($s) + 2, 3)), $rng->next(1, 100) <= 30, $p['class_id'] ?? 'guardian');
                    $p['loot'][] = $item;
                    $text .= ', '.Catalog::item($item)['name'];
                }
                if ($rng->next(1, 100) <= 50) {
                    $reagent = Catalog::reagentOf($biome['roster'][$rng->next(0, count($biome['roster']) - 1)]);
                    $p['reagents'][$reagent] = ($p['reagents'][$reagent] ?? 0) + 1;
                    $text .= ', '.mb_strtolower(Catalog::reagents()[$reagent]['name']);
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
            if ($s['floor'] === self::bossFloor($s) && ($s['enemies']['boss']['hp'] ?? 0) > 0) {
                throw new DomainException('boss_alive');
            }
            if ($action === 'extract') {
                $p['outcome'] = 'extracted';
            } else {
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

    private static function springTraps(array &$s, string $playerId): void
    {
        $p = $s['players'][$playerId];
        foreach ($s['objects'] ?? [] as $oid => $o) {
            if ($o['type'] === 'trap' && ! $o['used'] && $o['x'] === $p['x'] && $o['y'] === $p['y']) {
                $s['objects'][$oid]['used'] = true;
                self::hurt($s, $playerId, 6 + 3 * self::depthLevel($s) + self::biome($s)['enemy_damage'], 'Ловушка', false);
            }
        }
    }

    private static function leech(array &$s, string $playerId, int $dealt): void
    {
        $leech = $s['players'][$playerId]['leech'] ?? 0;
        if ($leech > 0 && $dealt > 0 && $s['players'][$playerId]['hp'] > 0) {
            $s['players'][$playerId]['hp'] = min($s['players'][$playerId]['max_hp'], $s['players'][$playerId]['hp'] + max(1, intdiv($dealt * $leech, 100)));
        }
    }

    /** @return list<string> the $count closest ids (the chosen target first), ties broken by id. */
    private static function nearest(array $distances, int $count, ?string $first = null): array
    {
        uksort($distances, static fn ($a, $b) => [$a !== $first, $distances[$a], $a] <=> [$b !== $first, $distances[$b], $b]);

        return array_slice(array_keys($distances), 0, $count);
    }

    /** Damage a monster, or in a duel the opposing hero; returns the damage dealt. */
    private static function hit(array &$s, string $playerId, string $targetId, int $damage, bool $physical): int
    {
        if (isset($s['enemies'][$targetId])) {
            return self::strike($s, $playerId, $targetId, $damage, $physical);
        }
        $before = $s['players'][$targetId]['hp'];
        // Duels run at reduced damage so that a fight lasts more than two blows.
        self::hurt($s, $targetId, intdiv($damage * 3, 5), $s['players'][$playerId]['name'], $physical, $s['players'][$playerId]['level'] ?? 1);

        return $before - $s['players'][$targetId]['hp'];
    }

    /** Damage an enemy on behalf of a player; physical blows are reduced by the enemy's armour. */
    private static function strike(array &$s, string $playerId, string $enemyId, int $damage, bool $physical): int
    {
        $e = $s['enemies'][$enemyId];
        $specials = self::stat($s, $e, 'specials');
        $name = $e['name'] ?? self::definition($e)['name'];
        if ($physical && in_array('evade', $specials, true)) {
            $rng = new Random($s['rng']);
            $miss = $rng->next(1, 100) <= 25;
            $s['rng'] = $rng->state;
            if ($miss) {
                $s['log'][] = $name.' уклоняется.';

                return 0;
            }
        }
        if ($physical) {
            $damage = max(1, $damage - self::stat($s, $e, 'armor'));
        } elseif (in_array('warded', $specials, true)) {
            $damage = max(1, intdiv($damage + 1, 2));
        }
        $dealt = min($e['hp'], $damage);
        $e['hp'] = max(0, $e['hp'] - $damage);
        $s['enemies'][$enemyId]['hp'] = $e['hp'];
        $s['log'][] = ($s['players'][$playerId]['name'] ?? 'Яд').' → '.$name.': '.$damage;
        if ($physical && $dealt > 0 && in_array('thorns', $specials, true) && isset($s['players'][$playerId])) {
            self::hurt($s, $playerId, max(1, intdiv($dealt, 5)), 'Шипы', false);
        }
        if ($e['hp'] === 0) {
            self::slay($s, $playerId, $e);
        }

        return $dealt;
    }

    private static function slay(array &$s, string $playerId, array $e): void
    {
        $def = self::definition($e);
        $specials = self::stat($s, $e, 'specials');
        if (isset($s['players'][$playerId])) {
            self::reward($s, $playerId, $e);
        }
        if (in_array('explode', $specials, true)) {
            $s['log'][] = ($e['name'] ?? $def['name']).' взрывается!';
            foreach ($s['players'] as $pid => $member) {
                if (abs($member['x'] - $e['x']) + abs($member['y'] - $e['y']) <= 1) {
                    self::hurt($s, $pid, 10 + 2 * self::biome($s)['enemy_damage'] + intdiv($e['level'] ?? 1, 2), 'Взрыв', false);
                }
            }
        }
        // A splitting creature breaks into two lesser copies that cannot split again.
        if (in_array('split', $specials, true) && ! ($e['shard'] ?? false)) {
            $made = 0;
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                if ($made >= 2 || ! self::free($s, $e['x'] + $dx, $e['y'] + $dy)) continue;
                $id = $e['id'].'-'.$made;
                $hp = max(1, intdiv($e['max_hp'] ?? 30, 3));
                $s['enemies'][$id] = ['id' => $id, 'type' => $e['type'], 'name' => 'Осколок: '.$def['name'], 'x' => $e['x'] + $dx, 'y' => $e['y'] + $dy,
                    'hp' => $hp, 'max_hp' => $hp, 'ready_at' => $s['tick'] + 10, 'level' => $e['level'] ?? 1,
                    'damage' => max(1, intdiv(self::stat($s, $e, 'damage'), 2)), 'armor' => 0, 'range' => self::stat($s, $e, 'range'),
                    'attack' => self::stat($s, $e, 'attack'), 'step' => self::stat($s, $e, 'step'),
                    'specials' => array_values(array_diff($specials, ['split', 'explode'])), 'summoned' => true, 'shard' => true];
                $made++;
            }
            if ($made) $s['log'][] = ($e['name'] ?? $def['name']).' распадается надвое!';
        }
        if ($e['boss'] ?? false) {
            $s['bosses_slain'] = ($s['bosses_slain'] ?? 0) + 1;
            $s['shrine_charges'] = ($s['shrine_charges'] ?? 0) + 1;
            $s['log'][] = $def['name'].' повержен! Можно спускаться глубже или вернуться с добычей.';
        }
    }

    /** Armour absorbs a share of each blow that shrinks against stronger foes; never below 1. */
    private static function hurt(array &$s, string $playerId, int $damage, string $source, bool $armor, int $level = 1): void
    {
        $p = &$s['players'][$playerId];
        if ($p['outcome'] !== null || $p['hp'] <= 0) {
            return;
        }
        if ($armor && ($p['armor'] ?? 0) > 0) {
            $k = 20 + 2 * $level;
            $damage = max(1, (int) round($damage * $k / ($k + 3 * $p['armor'])));
        }
        if (($p['shield_until'] ?? 0) > $s['tick']) {
            // A guardian's bulwark turns a raised shield into a near wall.
            $damage = self::has($p, 'bulwark') ? intdiv($damage, 4) : intdiv($damage, 2);
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
                if ($p['outcome'] === null && $p['hp'] > 0 && ($p['regen'] ?? 0) > 0) {
                    $p['hp'] = min($p['max_hp'], $p['hp'] + $p['regen']);
                }
                // Poison and burns never finish a hero off on their own.
                if ($p['outcome'] === null && $p['hp'] > 1 && ($p['poison_until'] ?? 0) > $s['tick']) {
                    $p['hp'] = max(1, $p['hp'] - ($p['poison_dmg'] ?? 2));
                }
            }
            unset($p);
            foreach (array_keys($s['enemies']) as $id) {
                $e = $s['enemies'][$id];
                if ($e['hp'] > 0 && in_array('regen', self::stat($s, $e, 'specials'), true)) {
                    $s['enemies'][$id]['hp'] = min($e['max_hp'] ?? $e['hp'], $e['hp'] + max(1, intdiv($e['max_hp'] ?? $e['hp'], 40)));
                }
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
            $e = $s['enemies'][$id] ?? null;
            if ($e === null || $e['hp'] <= 0 || $e['ready_at'] > $s['tick']) {
                continue;
            }
            $def = self::definition($e);
            $specials = self::stat($s, $e, 'specials');
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
            $attack = self::stat($s, $e, 'attack');
            $level = $e['level'] ?? 1;
            if (in_array('heal', $specials, true)) {
                foreach ($s['enemies'] as $aid => $ally) {
                    if ($aid !== $id && $ally['hp'] > 0 && $ally['hp'] < ($ally['max_hp'] ?? $ally['hp']) &&
                        abs($ally['x'] - $e['x']) + abs($ally['y'] - $e['y']) <= 4) {
                        $s['enemies'][$aid]['hp'] = min($ally['max_hp'], $ally['hp'] + 10 + 2 * $biome['enemy_damage'] + $level);
                        $s['enemies'][$id]['ready_at'] = $s['tick'] + $attack;
                        $s['log'][] = ($e['name'] ?? $def['name']).' лечит союзника.';
                        continue 2;
                    }
                }
            }
            if ($boss && in_array('summon', $specials, true) && $s['tick'] >= ($e['summon_at'] ?? 0)) {
                $s['enemies'][$id]['summon_at'] = $s['tick'] + 100;
                $alive = count(array_filter($s['enemies'], static fn ($m) => ($m['summoned'] ?? false) && $m['hp'] > 0));
                if ($alive < 3 + intdiv(self::partySize($s) - 1, 2)) {
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
            $damage = self::stat($s, $e, 'damage');
            if (in_array('berserk', $specials, true) && $e['hp'] * 2 < ($e['max_hp'] ?? $e['hp'])) {
                $damage = intdiv($damage * 3, 2);
            }
            // A warlord nearby drives its pack on.
            foreach ($s['enemies'] as $aid => $ally) {
                if ($aid !== $id && $ally['hp'] > 0 && in_array('warlord', self::stat($s, $ally, 'specials'), true) &&
                    abs($ally['x'] - $e['x']) + abs($ally['y'] - $e['y']) <= 4) {
                    $damage = intdiv($damage * 5, 4);
                    break;
                }
            }
            if ($boss && in_array('slam', $specials, true) && $s['tick'] >= ($e['slam_at'] ?? 0) && $distance <= 2) {
                $s['log'][] = $def['name'].' обрушивает сокрушительный удар!';
                foreach ($s['players'] as $pid => $p) {
                    if (abs($p['x'] - $e['x']) + abs($p['y'] - $e['y']) <= 2) {
                        self::hurt($s, $pid, intdiv($damage * 3, 4), $def['name'], true, $level);
                    }
                }
                $s['enemies'][$id]['slam_at'] = $s['tick'] + 60;
                $s['enemies'][$id]['ready_at'] = $s['tick'] + 12;
                continue;
            }
            $p = $s['players'][$target];
            if ($distance <= self::stat($s, $e, 'range') && self::visible($s['map'], $e['x'], $e['y'], $p['x'], $p['y'])) {
                $before = $p['hp'];
                self::hurt($s, $target, $damage, $e['name'] ?? $def['name'], true, $level);
                $victim = &$s['players'][$target];
                if (in_array('leech', $specials, true)) {
                    $s['enemies'][$id]['hp'] = min($e['max_hp'] ?? $e['hp'], $e['hp'] + intdiv($before - $victim['hp'], 2));
                }
                if ($victim['hp'] > 0) {
                    $immune = self::has($victim, 'unliving');
                    if (in_array('poison', $specials, true) && ! $immune) {
                        $victim['poison_until'] = $s['tick'] + 50;
                        $victim['poison_dmg'] = 2 + intdiv($biome['enemy_damage'], 2) + intdiv($level, 4);
                    }
                    if (in_array('burn', $specials, true) && ! $immune) {
                        $victim['poison_until'] = max($victim['poison_until'] ?? 0, $s['tick'] + 30);
                        $victim['poison_dmg'] = max($victim['poison_dmg'] ?? 0, 4 + intdiv($biome['enemy_damage'], 2) + intdiv($level, 3));
                    }
                    if (in_array('chill', $specials, true)) {
                        $victim['ready_at'] = max($victim['ready_at'], $s['tick'] + 6);
                    }
                    if (in_array('drain', $specials, true)) {
                        $victim['mana'] = max(0, ($victim['mana'] ?? 0) - 10);
                    }
                }
                $thorns = $victim['thorns'] ?? 0;
                unset($victim);
                $s['enemies'][$id]['ready_at'] = $s['tick'] + $attack;
                // Thorns answer every blow taken in melee.
                if ($thorns > 0 && $distance <= 1 && $s['enemies'][$id]['hp'] > 0) {
                    self::strike($s, $target, $id, $thorns, false);
                }
            } elseif (self::stat($s, $e, 'step') > 0) {
                $steps = in_array('blink', $specials, true) ? 2 : 1;
                for ($n = 0; $n < $steps; $n++) {
                    $here = $s['enemies'][$id];
                    if (abs($here['x'] - $p['x']) + abs($here['y'] - $p['y']) <= 1) break;
                    $candidates = [[$here['x'] + 1, $here['y']], [$here['x'] - 1, $here['y']], [$here['x'], $here['y'] + 1], [$here['x'], $here['y'] - 1]];
                    usort($candidates, static fn ($a, $b) => (abs($a[0] - $p['x']) + abs($a[1] - $p['y'])) <=> (abs($b[0] - $p['x']) + abs($b[1] - $p['y'])));
                    foreach ($candidates as [$x,$y]) {
                        if (self::free($s, $x, $y)) {
                            $s['enemies'][$id]['x'] = $x;
                            $s['enemies'][$id]['y'] = $y;
                            break;
                        }
                    }
                }
                $s['enemies'][$id]['ready_at'] = $s['tick'] + self::stat($s, $e, 'step');
            } else {
                $s['enemies'][$id]['ready_at'] = $s['tick'] + 5;
            }
        }
        $duel = ($s['mode'] ?? '') === 'duel';
        if ($duel ? $s['tick'] >= self::DUEL_TICKS : $s['tick'] >= ($s['floor_tick'] ?? 0) + 36000) {
            foreach ($s['players'] as &$p) {
                if ($p['outcome'] === null) {
                    $p['outcome'] = $duel ? 'draw' : 'defeated';
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

    /** A fallen duelist loses at once; the last one standing wins. */
    public static function resolveDuel(array $s): array
    {
        if (($s['mode'] ?? '') !== 'duel' || count($s['players']) < 2) {
            return $s;
        }
        foreach ($s['players'] as &$p) {
            if ($p['outcome'] === null && $p['hp'] <= 0) {
                $p['outcome'] = 'defeated';
            }
        }
        unset($p);
        $alive = array_keys(array_filter($s['players'], static fn ($p) => $p['outcome'] === null));
        if (count($alive) === 1) {
            $s['players'][$alive[0]]['outcome'] = 'victory';
            $s['log'][] = $s['players'][$alive[0]]['name'].' побеждает в дуэли!';
        }

        return $s;
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
        $s = self::resolveDuel(self::order($s));
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

    private static function reward(array &$s, string $playerId, array $e): void
    {
        $p = &$s['players'][$playerId];
        $area = self::biome($s);
        $scale = $area['reward'];
        $def = self::definition($e);
        if (self::has($p, 'soul_harvest') && $p['hp'] > 0) {
            $p['mana'] = min($p['max_mana'] ?? 60, ($p['mana'] ?? 0) + 8);
            $p['hp'] = min($p['max_hp'], $p['hp'] + max(1, intdiv($p['max_hp'], 20)));
        }
        if ($e['summoned'] ?? false) {
            $p['xp'] += 2 * $scale;

            return;
        }
        $level = $e['level'] ?? 1;
        $boss = $def['boss'] ?? false;
        $elite = $e['elite'] ?? false;
        $bonus = ($elite ? 2 : 1) * (1 + 0.2 * count($e['affixes'] ?? [])) * (isset($e['fused']) ? 1.5 : 1);
        $p['gold'] += (int) round(5 * $level * $scale * $bonus * (1 + self::omen($s, 'gold')) * (1 + ($p['greed'] ?? 0) / 100));
        $p['xp'] += (int) round($def['xp'] * $scale * $bonus * (1 + 0.1 * ($level - 1)) * (1 + self::omen($s, 'xp')));
        $p['kills']++;
        $p['slain'][$e['type']] = ($p['slain'][$e['type']] ?? 0) + 1;
        if (isset($e['fused'])) $p['slain'][$e['fused']] = ($p['slain'][$e['fused']] ?? 0) + 1;
        $cycle = intdiv($s['floor'] - 1, $area['floors']);
        if ($boss) {
            $p['essence'] = ($p['essence'] ?? 0) + 2 + $area['chapter'] + $cycle;
        } elseif ($elite || count($e['affixes'] ?? []) >= 2) {
            $p['essence'] = ($p['essence'] ?? 0) + 1;
        }
        $rng = new Random($s['rng']);
        // Every second kill and every elite yield an item; the first boss of an area drops its unique.
        if ($boss || $elite || $p['kills'] % 2 === 0) {
            $tier = max(1, intdiv($level + 2, 3)) + ($boss ? 1 : 0);
            if ($boss && $cycle === 0) {
                $p['loot'][] = $area['unique'];
            } elseif ($cycle === 0 && $rng->next(1, 100) <= 30) {
                $pool = $area['loot'][$s['floor'] === 1 ? 0 : 1];
                $p['loot'][] = $pool[$rng->next(0, count($pool) - 1)];
            } else {
                $p['loot'][] = Catalog::generate($rng, $tier, $boss || $elite || $rng->next(1, 100) <= 25, $p['class_id'] ?? 'guardian');
            }
        }
        // Reagents for the forge and the alchemist: every species yields its own, a chimera both halves.
        $times = 1 + (int) self::omen($s, 'reagents');
        foreach (array_filter([$e['type'], $e['fused'] ?? null]) as $species) {
            $reagent = Catalog::reagentOf($species);
            $count = $boss ? 2 : ($elite ? 1 : ($rng->next(1, 100) <= 35 ? 1 : 0));
            if ($count > 0) $p['reagents'][$reagent] = ($p['reagents'][$reagent] ?? 0) + $count * $times;
        }
        $s['rng'] = $rng->state;
        unset($p);
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
        $sight = 8;
        foreach ($s['omens'] ?? [] as $omen) $sight = min($sight, Catalog::omens()[$omen]['sight'] ?? 8);
        // Each line of sight is traced once; wall cells re-use their neighbours' results.
        $seen = [];
        $sees = static function (int $x, int $y) use (&$seen, $s, $p): bool {
            return $seen[$y * 64 + $x + 65] ??= self::visible($s['map'], $p['x'], $p['y'], $x, $y);
        };
        for ($y = max(0, $p['y'] - $sight); $y <= min(31, $p['y'] + $sight); $y++) {
            for ($x = max(0, $p['x'] - $sight); $x <= min(31, $p['x'] + $sight); $x++) {
                // Reveal adjacent walls, but never objects behind them.
                if (abs($x - $p['x']) + abs($y - $p['y']) <= $sight) {
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
        $duel = ($s['mode'] ?? '') === 'duel';
        if ($duel) {
            // The arena is lit: both duelists always see the whole hall and each other.
            $map = $s['map'];
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
        $bossType = $duel ? null : Catalog::enemy(self::bossType(['floor' => self::bossFloor($s)] + $s))['name'];
        $omens = array_map(static fn ($o) => Catalog::omens()[$o]['name'], $s['omens'] ?? []);

        return ['tick' => (string) $s['tick'], 'mode' => $s['mode'] ?? 'expedition', 'floor' => $s['floor'],
            'last_floor' => self::bossFloor($s), 'boss_floor' => self::bossFloor($s), 'depth_level' => self::depthLevel($s),
            'party_level' => $s['party_level'] ?? 1, 'party_size' => count($s['players']), 'biome'=>$s['biome']??'mines',
            'biome_name'=>$duel ? 'Арена' : $biome['name'], 'boss_name' => $bossType, 'status' => $s['status'], 'omens' => $omens,
            'map' => $map, 'exit' => $exit, 'self' => $p, 'players' => $players, 'enemies' => $enemies, 'objects' => $objects, 'log' => $s['log']];
    }
}
