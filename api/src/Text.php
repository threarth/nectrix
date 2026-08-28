<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

/**
 * String helpers that work without the mbstring extension, which is not always installed.
 * Length is always counted in Unicode code points; lowercasing covers the full Unicode range only
 * when mbstring is available, otherwise it is ASCII only and non ASCII characters are left as they
 * are. The result stays deterministic in both cases, so normalised values remain comparable.
 */
final class Text
{
    public static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    public static function length(string $value): int
    {
        $count = preg_match_all('/./us', $value);
        return $count === false ? strlen($value) : $count;
    }
}
