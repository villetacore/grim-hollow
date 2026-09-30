<?php

namespace Tests\Unit;

use DomainException;
use GrimHollow\Core\Dungeon;
use GrimHollow\Core\Game;
use GrimHollow\Core\Canonical;
use PHPUnit\Framework\TestCase;

final class GameTest extends TestCase
{
    public function test_ten_thousand_maps_have_reachable_exits(): void
    {
        for ($seed = 1; $seed <= 10000; $seed++) {
            $d = Dungeon::generate($seed, 1 + $seed % 3);
            $seen = ['5,5' => true];
            $q = [[5, 5]];
            for ($head = 0; $head < count($q); $head++) {
                [$x,$y] = $q[$head];
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx,$dy]) {
                    $nx = $x + $dx;
                    $ny = $y + $dy;
                    $key = "$nx,$ny";
                    if (! isset($seen[$key]) && Dungeon::walkable($d['map'], $nx, $ny)) {
                        $seen[$key] = true;
                        $q[] = [$nx, $ny];
                    }
                }
            }
            self::assertArrayHasKey(implode(',', $d['exit']), $seen, "Seed $seed");
        }
    }

    public function test_same_inputs_replay_exactly(): void
    {
        $a = Game::create(123, ['hero' => 'Hero']);
        $b = $a;
        for ($i = 0; $i < 200; $i++) {
            $a = Game::tick($a);
            $b = Game::tick($b);
        }
        self::assertSame($a, $b);
    }

    public function test_database_object_key_order_does_not_change_simulation_or_hash(): void
    {
        $s=Game::create(22,['hero'=>'Hero']);
        $reordered=json_decode(Canonical::json($s),true);
        self::assertSame(Canonical::json(Game::tick($s)),Canonical::json(Game::tick($reordered)));
    }

    public function test_player_cannot_walk_through_walls(): void
    {
        $s = Game::create(7, ['hero' => 'Hero']);
        $s['map'][5][6] = '#';
        $this->expectException(DomainException::class);
        Game::command($s, 'hero', ['action' => 'move', 'direction' => 'east']);
    }

    public function test_cooldown_blocks_speed_hack(): void
    {
        $s = Game::command(Game::create(9, ['hero' => 'Hero']), 'hero', ['action' => 'move', 'direction' => 'east']);
        $this->expectExceptionMessage('cooldown');
        Game::command($s, 'hero', ['action' => 'move', 'direction' => 'east']);
    }

    public function test_hidden_enemies_and_seed_are_not_transmitted(): void
    {
        $s = Game::create(4, ['hero' => 'Hero']);
        $s['enemies']['secret'] = ['id' => 'secret', 'type' => 'brute', 'x' => 30, 'y' => 30, 'hp' => 10, 'ready_at' => 0];
        $v = Game::view($s, 'hero');
        self::assertArrayNotHasKey('seed', $v);
        self::assertArrayNotHasKey('rng', $v);
        self::assertNotContains('secret', array_column($v['enemies'], 'id'));
    }

    public function test_dead_enemy_cannot_award_loot_twice(): void
    {
        $s = Game::create(10, ['hero' => 'Hero']);
        $s['enemies'] = ['target' => ['id' => 'target', 'type' => 'brute', 'x' => 6, 'y' => 5, 'hp' => 1, 'ready_at' => 0]];
        $s = Game::command($s, 'hero', ['action' => 'attack', 'target_id' => 'target']);
        self::assertSame(5, $s['players']['hero']['gold']);
        $s['tick'] = 20;
        $this->expectExceptionMessage('invalid_target');
        Game::command($s,'hero',['action' => 'attack', 'target_id' => 'target']);
    }
}
