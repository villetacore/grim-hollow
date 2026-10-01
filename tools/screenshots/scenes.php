<?php
/**
 * Builds deterministic game scenes with the real rules engine for README screenshots.
 * Usage: php tools/screenshots/scenes.php <output-dir>
 * Each scene is a snapshot as the client receives it, plus the explored map for the fog of war.
 */
declare(strict_types=1);

use GrimHollow\Core\{Catalog, Dungeon, Game, Plaza};

require __DIR__.'/../../apps/server/vendor/autoload.php';
$out = rtrim($argv[1] ?? 'build/screenshots', '/\\');
@mkdir($out, 0777, true);

function party(): array
{
    $xp = fn (int $level) => 20 * ($level - 1) * $level;
    return [
        'hero' => Catalog::profile('Ирвен', 'arcanist', $xp(24), ['intellect' => 20], ['weapon' => 'staff+8~arcane', 'body' => 'robe+7~mystic', 'offhand' => 'orb+7', 'ring' => 'ring+6~keen', 'amulet' => 'talisman+7']),
        'ally' => Catalog::profile('Бран', 'guardian', $xp(22), ['strength' => 18, 'vitality' => 20], ['weapon' => 'axe+8~fierce', 'body' => 'plate+7~warding', 'offhand' => 'shield+7']),
        'scout' => Catalog::profile('Лиса', 'ranger', $xp(23), ['strength' => 20], ['weapon' => 'crossbow+8~keen', 'body' => 'jerkin+7', 'offhand' => 'quiver+7~vampiric']),
    ];
}

function descend(array $s, int $floors): array
{
    for ($i = 0; $i < $floors; $i++) {
        foreach ($s['players'] as $id => $p) {
            $s['players'][$id]['x'] = $s['exit'][0];
            $s['players'][$id]['y'] = $s['exit'][1];
            $s['players'][$id]['ready_at'] = 0;
        }
        if (isset($s['enemies']['boss'])) $s['enemies']['boss']['hp'] = 0;
        $s = Game::command($s, 'hero', ['action' => 'descend']);
    }
    return $s;
}

/** Walkable cell at the given distance from a point, with a clear line of sight to it. */
function standNear(array $s, int $tx, int $ty, int $distance, array $taken = []): array
{
    for ($d = $distance; $d > 0; $d--) {
        for ($y = 1; $y < 31; $y++) for ($x = 1; $x < 31; $x++) {
            if (abs($x - $tx) + abs($y - $ty) !== $d || ! Dungeon::walkable($s['map'], $x, $y) || in_array([$x, $y], $taken, true)) continue;
            foreach ($s['enemies'] as $e) if ($e['hp'] > 0 && $e['x'] === $x && $e['y'] === $y) continue 2;
            $view = Game::view(array_replace_recursive($s, ['players' => ['hero' => ['x' => $x, 'y' => $y]]]), 'hero');
            if ($view['map'][$ty][$tx] === '.') return [$x, $y];
        }
    }
    throw new RuntimeException('no stand point');
}

/** Merges the views from a breadth-first walk between spawn and the hero: the explored map. */
function explored(array $s, int $fromX, int $fromY): array
{
    $to = [$s['players']['hero']['x'], $s['players']['hero']['y']];
    $prev = [$fromX.','.$fromY => null]; $queue = [[$fromX, $fromY]];
    for ($i = 0; $i < count($queue); $i++) {
        [$x, $y] = $queue[$i];
        if ([$x, $y] === $to) break;
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $k = ($x + $dx).','.($y + $dy);
            if (! isset($prev[$k]) && ! array_key_exists($k, $prev) && Dungeon::walkable($s['map'], $x + $dx, $y + $dy)) { $prev[$k] = "$x,$y"; $queue[] = [$x + $dx, $y + $dy]; }
        }
    }
    $path = []; $k = implode(',', $to);
    while ($k !== null && isset($prev[$k]) || $k === "$fromX,$fromY") { $path[] = array_map('intval', explode(',', $k)); if ($k === "$fromX,$fromY") break; $k = $prev[$k]; }
    $map = array_fill(0, 32, str_repeat(' ', 32));
    foreach (array_merge($path ? array_filter($path, fn ($p, $i) => $i % 3 === 0, ARRAY_FILTER_USE_BOTH) : [], [$to]) as [$x, $y]) {
        $v = Game::view(array_replace_recursive($s, ['players' => ['hero' => ['x' => $x, 'y' => $y]]]), 'hero');
        for ($r = 0; $r < 32; $r++) for ($c = 0; $c < 32; $c++) if ($v['map'][$r][$c] !== ' ') $map[$r][$c] = $v['map'][$r][$c];
    }
    return $map;
}

function save(string $file, array $s, string $target, array $explored): void
{
    $view = Game::view($s, 'hero');
    $snapshot = ['v' => 1, 'type' => 'snapshot', 'instance_id' => 'readme', 'revision' => '1', 'lobby' => false, 'join_code' => 'README0000', 'host_id' => 'hero', 'world' => $view];
    file_put_contents($file, json_encode(['snapshot' => $snapshot, 'hero' => 'hero', 'target' => $target, 'explored' => $explored,
        'est_tick' => $s['tick'], 'sheet' => ['spells' => Catalog::spells()]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo $file, PHP_EOL;
}

// 1. A deep floor: a mixed pack with modifiers, the party casting into it.
$s = descend(Game::create(4242, party(), 'citadel'), 6);
$pack = null;
foreach ($s['enemies'] as $id => $e) if (! empty($e['affixes']) && ($pack === null || count($e['affixes']) > count($s['enemies'][$pack]['affixes']))) $pack = $id;
$e = $s['enemies'][$pack];
[$x, $y] = standNear($s, $e['x'], $e['y'], 4);
$s['players']['hero']['x'] = $x; $s['players']['hero']['y'] = $y;
[$ax, $ay] = standNear($s, $e['x'], $e['y'], 1, [[$x, $y]]);
$s['players']['ally']['x'] = $ax; $s['players']['ally']['y'] = $ay;
[$rx, $ry] = standNear($s, $e['x'], $e['y'], 3, [[$x, $y], [$ax, $ay]]);
$s['players']['scout']['x'] = $rx; $s['players']['scout']['y'] = $ry;
$s['players']['hero']['mana'] = 400;
$s = Game::command($s, 'hero', ['action' => 'cast', 'spell_id' => 'chain', 'target_id' => $pack]);
$s = Game::command($s, 'ally', ['action' => 'attack', 'target_id' => $pack]);
for ($i = 0; $i < 12; $i++) $s = Game::tick($s);
$s['players']['hero']['ready_at'] = $s['tick'];
$target = $pack; foreach ($s['enemies'] as $id => $en) if ($en['hp'] > 0 && ($s['enemies'][$target]['hp'] ?? 0) <= 0) $target = $id;
save("$out/deep.json", $s, $target, explored($s, 5, 5));

// 2. A boss floor: the boss has called its guards.
$s = descend(Game::create(777, party(), 'catacombs'), 3);
$b = $s['enemies']['boss'];
[$x, $y] = standNear($s, $b['x'], $b['y'], 4);
$s['players']['hero']['x'] = $x; $s['players']['hero']['y'] = $y;
[$ax, $ay] = standNear($s, $b['x'], $b['y'], 2, [[$x, $y]]);
$s['players']['ally']['x'] = $ax; $s['players']['ally']['y'] = $ay;
$s['players']['scout']['outcome'] = 'extracted';
$s['enemies']['boss']['summon_at'] = 0; $s['enemies']['boss']['ready_at'] = 0;
for ($i = 0; $i < 3; $i++) { $s = Game::tick($s); $s['enemies']['boss']['summon_at'] = 0; $s['enemies']['boss']['ready_at'] = 0; }
$s['players']['hero']['mana'] = 300;
$s = Game::command($s, 'hero', ['action' => 'cast', 'spell_id' => 'meteor', 'target_id' => 'boss']);
for ($i = 0; $i < 4; $i++) $s = Game::tick($s);
save("$out/boss.json", $s, 'boss', explored($s, 5, 5));

// 3. A duel on the arena.
$duel = party();
$s = Game::create(9, ['hero' => $duel['hero'], 'ally' => $duel['scout']], 'mines', 'duel');
$s['tick'] = 40; $s['players']['hero']['x'] = 13; $s['players']['ally']['x'] = 17; $s['players']['ally']['y'] = $s['players']['hero']['y'] = 10;
$s = Game::command($s, 'ally', ['action' => 'attack', 'target_id' => 'hero']);
$s = Game::command($s, 'hero', ['action' => 'cast', 'spell_id' => 'firebolt', 'target_id' => 'ally']);
for ($i = 0; $i < 6; $i++) $s = Game::tick($s);
save("$out/duel.json", $s, 'ally', Game::view($s, 'hero')['map']);

// 4. The town square: heroes of different builds, keepers and walkers, a chat bubble and an emote.
$now = 1000000000;
$hero = fn ($id, $name, $class, $origin, $level, $x, $y, $extra = []) => $extra + ['id' => $id, 'name' => $name, 'class_id' => $class,
    'origin' => $origin, 'level' => $level, 'x' => $x, 'y' => $y, 'facing' => 'south', 'emote' => null, 'bubble' => null];
$npcs = array_map(fn ($n) => array_intersect_key($n, array_flip(['id', 'name', 'kind', 'service', 'x', 'y'])), Plaza::npcs());
$players = [
    $hero('p1', 'Мирель', 'druid', 'elf', 23, 24, 15, ['bubble' => 'Кто со мной в Гнилую топь? Нужен лекарь!']),
    $hero('p2', 'Грок', 'berserker', 'orc', 31, 30, 14, ['emote' => 'cheer']),
    $hero('p3', 'Тень', 'assassin', 'undead', 18, 22, 19),
    $hero('p4', 'Ольга', 'warden', 'dwarf', 27, 33, 18, ['emote' => 'dance']),
    $hero('p5', 'Каэль', 'necromancer', 'drakeborn', 40, 20, 12),
];
$view = ['v' => 1, 'type' => 'plaza', 'now' => (string) $now, 'online' => 6, 'self' => $hero('me', 'Ардан', 'guardian', 'human', 12, 26, 15),
    'players' => $players, 'npcs' => array_merge($npcs, Plaza::walkersAt($now))];
file_put_contents("$out/plaza.json", json_encode(['town' => ['width' => Plaza::WIDTH, 'height' => Plaza::HEIGHT, 'map' => Plaza::map(),
    'buildings' => Plaza::buildings()], 'plaza' => $view, 'hero' => 'me',
    'talk' => 'Наставница Кайра: Страж с наставником-убийцей? Почему бы и нет. Восемь классов — шестьдесят четыре пути.'], JSON_UNESCAPED_UNICODE));
