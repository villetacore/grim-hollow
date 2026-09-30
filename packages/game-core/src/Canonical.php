<?php
declare(strict_types=1);

namespace GrimHollow\Core;

final class Canonical
{
    public static function json(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::normalize($item);
        return $value;
    }
}
