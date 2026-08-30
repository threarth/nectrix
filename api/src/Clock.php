<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

use DateTimeImmutable;
use DateTimeZone;

final class Clock
{
    private const FORMAT = 'Y-m-d\TH:i:s.v\Z';

    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(self::FORMAT);
    }

    public static function after(string $previous): string
    {
        $now = self::now();
        if (strcmp($now, $previous) > 0) {
            return $now;
        }

        return (new DateTimeImmutable($previous))->modify('+1 millisecond')->format(self::FORMAT);
    }
}
