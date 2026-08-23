<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Protocol;

use cieplik206\BirRegon\Exceptions\BirValidationException;
use cieplik206\BirRegon\Validation\PolishIdentifierChecksum;

final readonly class SearchCriteria
{
    public const MAX_BATCH_SIZE = 20;

    /** @var list<string> */
    public const WSDL_FIELD_ORDER = [
        'Krs',
        'Krsy',
        'Nip',
        'Nipy',
        'Regon',
        'Regony14zn',
        'Regony9zn',
    ];

    private function __construct(
        public string $field,
        public string $value,
    ) {}

    public static function nip(string $nip, bool $validateChecksum = false): self
    {
        self::validateIdentifier($nip, 10, 'NIP');

        if ($validateChecksum) {
            PolishIdentifierChecksum::assertValidNip($nip);
        }

        return new self('Nip', $nip);
    }

    public static function regon(string $regon, bool $validateChecksum = false): self
    {
        if (! preg_match('/^(?:\d{9}|\d{14})$/D', $regon)) {
            throw new BirValidationException('REGON must contain exactly 9 or 14 digits.');
        }

        if ($validateChecksum) {
            PolishIdentifierChecksum::assertValidRegon($regon);
        }

        return new self('Regon', $regon);
    }

    public static function krs(string $krs): self
    {
        self::validateIdentifier($krs, 10, 'KRS');

        return new self('Krs', $krs);
    }

    /** @param list<string> $nips */
    public static function nips(array $nips, bool $validateChecksum = false): self
    {
        self::validateBatch($nips, 10, 'NIP');

        if ($validateChecksum) {
            foreach ($nips as $nip) {
                PolishIdentifierChecksum::assertValidNip($nip);
            }
        }

        return new self('Nipy', implode(',', $nips));
    }

    /** @param list<string> $krsNumbers */
    public static function krsNumbers(array $krsNumbers): self
    {
        self::validateBatch($krsNumbers, 10, 'KRS');

        return new self('Krsy', implode(',', $krsNumbers));
    }

    /** @param list<string> $regons */
    public static function regons9(array $regons, bool $validateChecksum = false): self
    {
        self::validateBatch($regons, 9, 'REGON9');

        if ($validateChecksum) {
            foreach ($regons as $regon) {
                PolishIdentifierChecksum::assertValidRegon9($regon);
            }
        }

        return new self('Regony9zn', implode(',', $regons));
    }

    /** @param list<string> $regons */
    public static function regons14(array $regons, bool $validateChecksum = false): self
    {
        self::validateBatch($regons, 14, 'REGON14');

        if ($validateChecksum) {
            foreach ($regons as $regon) {
                PolishIdentifierChecksum::assertValidRegon14($regon);
            }
        }

        return new self('Regony14zn', implode(',', $regons));
    }

    public function identifierCount(): int
    {
        return in_array($this->field, ['Krs', 'Nip', 'Regon'], true)
            ? 1
            : substr_count($this->value, ',') + 1;
    }

    private static function validateIdentifier(string $identifier, int $length, string $type): void
    {
        if (! preg_match('/^\d{'.$length.'}$/D', $identifier)) {
            throw new BirValidationException("{$type} must contain exactly {$length} digits.");
        }
    }

    /** @param list<mixed> $identifiers */
    private static function validateBatch(array $identifiers, int $length, string $type): void
    {
        if ($identifiers === []) {
            throw new BirValidationException("{$type} batch must contain at least one identifier.");
        }

        if (count($identifiers) > self::MAX_BATCH_SIZE) {
            throw new BirValidationException(
                'Too many identifiers. Maximum allowed is '.self::MAX_BATCH_SIZE.'.',
            );
        }

        foreach ($identifiers as $identifier) {
            if (! is_string($identifier)) {
                throw new BirValidationException("{$type} batch values must be strings.");
            }

            self::validateIdentifier($identifier, $length, $type);
        }
    }
}
