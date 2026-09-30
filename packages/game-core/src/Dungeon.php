<?php

declare(strict_types=1);

namespace GrimHollow\Core;

final class Dungeon
{
    /** Rooms are connected incrementally, so every floor has a path to its exit. */
    public static function generate(int $seed, int $floor): array
    {
        $rng = new Random($seed + $floor * 7919);
        $map = array_fill(0, 32, str_repeat('#', 32));
        $rooms = [[2, 2, 7, 7]];
        for ($i = 0; $i < 9; $i++) {
            $rooms[] = [$rng->next(2, 23), $rng->next(2, 23), $rng->next(4, 7), $rng->next(4, 7)];
        }
        $previous = [5, 5];
        $centers = [];
        foreach ($rooms as [$x, $y, $w, $h]) {
            for ($row = $y; $row < $y + $h; $row++) {
                for ($col = $x; $col < $x + $w; $col++) {
                    $map[$row][$col] = '.';
                }
            }
            $center = [$x + intdiv($w, 2), $y + intdiv($h, 2)];
            for ($col = min($previous[0], $center[0]); $col <= max($previous[0], $center[0]); $col++) {
                $map[$previous[1]][$col] = '.';
            }
            for ($row = min($previous[1], $center[1]); $row <= max($previous[1], $center[1]); $row++) {
                $map[$row][$center[0]] = '.';
            }
            $centers[] = $center;
            $previous = $center;
        }
        // Choose the farthest room, excluding the safe spawn area.
        usort($centers, static fn ($a, $b) => (abs($b[0] - 5) + abs($b[1] - 5)) <=> (abs($a[0] - 5) + abs($a[1] - 5)));

        return ['map' => $map, 'spawn' => [5, 5], 'exit' => $centers[0], 'rng' => $rng->state];
    }

    public static function walkable(array $map, int $x, int $y): bool
    {
        return $x >= 0 && $x < 32 && $y >= 0 && $y < 32 && $map[$y][$x] !== '#';
    }
}
