<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Validation;

use cieplik206\BirRegon\Exceptions\BirValidationException;

final class PolishIdentifierChecksum
{
    /** @var list<int> */
    private const NIP_WEIGHTS = [6, 5, 7, 2, 3, 4, 5, 6, 7];

    /** @var list<int> */
    private const REGON9_WEIGHTS = [8, 9, 2, 3, 4, 5, 6, 7];

    /** @var list<int> */
    private const REGON14_WEIGHTS = [2, 4, 8, 5, 0, 9, 7, 3, 6, 1, 2, 4, 8];

    private function __construct() {}

    public static function isValidNip(string $nip): bool
    {
        if (! preg_match('/^\d{10}$/D', $nip)) {
            return false;
        }

        $checksum = self::weightedModulo(substr($nip, 0, 9), self::NIP_WEIGHTS);

        return $checksum !== 10 && $checksum === (int) $nip[9];
    }

    public static function isValidRegon(string $regon): bool
    {
        return match (strlen($regon)) {
            9 => self::isValidRegon9($regon),
            14 => self::isValidRegon14($regon),
            default => false,
        };
    }

    public static function isValidRegon9(string $regon): bool
    {
        if (! preg_match('/^\d{9}$/D', $regon)) {
            return false;
        }

        return self::regonChecksum(substr($regon, 0, 8), self::REGON9_WEIGHTS) === (int) $regon[8];
    }

    public static function isValidRegon14(string $regon): bool
    {
        if (! preg_match('/^\d{14}$/D', $regon)) {
            return false;
        }

        return self::isValidRegon9(substr($regon, 0, 9))
            && self::regonChecksum(substr($regon, 0, 13), self::REGON14_WEIGHTS) === (int) $regon[13];
    }

    public static function assertValidNip(string $nip): void
    {
        if (! self::isValidNip($nip)) {
            throw new BirValidationException('NIP checksum is invalid.');
        }
    }

    public static function assertValidRegon(string $regon): void
    {
        if (! self::isValidRegon($regon)) {
            throw new BirValidationException('REGON checksum is invalid.');
        }
    }

    public static function assertValidRegon9(string $regon): void
    {
        if (! self::isValidRegon9($regon)) {
            throw new BirValidationException('REGON-9 checksum is invalid.');
        }
    }

    public static function assertValidRegon14(string $regon): void
    {
        if (! self::isValidRegon14($regon)) {
            throw new BirValidationException('REGON-14 checksum is invalid.');
        }
    }

    /** @param list<int> $weights */
    private static function regonChecksum(string $digits, array $weights): int
    {
        $checksum = self::weightedModulo($digits, $weights);

        return $checksum === 10 ? 0 : $checksum;
    }

    /** @param list<int> $weights */
    private static function weightedModulo(string $digits, array $weights): int
    {
        $sum = 0;

        foreach ($weights as $position => $weight) {
            $sum += (int) $digits[$position] * $weight;
        }

        return $sum % 11;
    }
}
