<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Protocol;

/** @internal */
final class XsInteger
{
    public static function toNonNegativeInt(string $value): ?int
    {
        $normalized = preg_replace('/[\x20\x09\x0D\x0A]+/', ' ', $value);
        $normalized = trim($normalized ?? $value, ' ');

        if (preg_match('/^(?<sign>[+-]?)(?<digits>\d+)$/D', $normalized, $parts) !== 1) {
            return null;
        }

        $digits = ltrim($parts['digits'], '0');
        $digits = $digits === '' ? '0' : $digits;

        if ($parts['sign'] === '-' && $digits !== '0') {
            return null;
        }

        $maximum = '2147483647';

        if (
            strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)
        ) {
            return null;
        }

        return (int) $digits;
    }
}
