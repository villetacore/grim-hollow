<?php

declare(strict_types=1);

namespace GrimHollow\Core;

final class Random
{
    public function __construct(public int $state)
    {
        $this->state = max(1, $state % 2147483647);
    }

    public function next(int $min, int $max): int
    {
        $this->state = ($this->state * 48271) % 2147483647;

        return $min + ($this->state % ($max - $min + 1));
    }
}
