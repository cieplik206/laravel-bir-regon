<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Protocol;

use cieplik206\BirRegon\Exceptions\BirValidationException;
use cieplik206\BirRegon\Validation\PolishIdentifierChecksum;
use LogicException;
use SensitiveParameterValue;

/** @property-read string $value */
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

    private SensitiveParameterValue $value;

    private bool $available;

    private function __construct(
        public string $field,
        #[\SensitiveParameter] string $value,
        bool $available = true,
    ) {
        $this->value = new SensitiveParameterValue($value);
        $this->available = $available;
    }

    public static function nip(
        #[\SensitiveParameter] string $nip,
        bool $validateChecksum = false,
    ): self {
        self::validateIdentifier($nip, 10, 'NIP');

        if ($validateChecksum) {
            PolishIdentifierChecksum::assertValidNip($nip);
        }

        return new self('Nip', $nip);
    }

    public static function regon(
        #[\SensitiveParameter] string $regon,
        bool $validateChecksum = false,
    ): self {
        if (! preg_match('/^(?:\d{9}|\d{14})$/D', $regon)) {
            throw new BirValidationException('REGON must contain exactly 9 or 14 digits.');
        }

        if ($validateChecksum) {
            PolishIdentifierChecksum::assertValidRegon($regon);
        }

        return new self('Regon', $regon);
    }

    public static function krs(#[\SensitiveParameter] string $krs): self
    {
        self::validateIdentifier($krs, 10, 'KRS');

        return new self('Krs', $krs);
    }

    /** @param list<string> $nips */
    public static function nips(
        #[\SensitiveParameter] array $nips,
        bool $validateChecksum = false,
    ): self {
        self::validateBatch($nips, 10, 'NIP');

        if ($validateChecksum) {
            foreach ($nips as $nip) {
                PolishIdentifierChecksum::assertValidNip($nip);
            }
        }

        return new self('Nipy', implode(',', $nips));
    }

    /** @param list<string> $krsNumbers */
    public static function krsNumbers(#[\SensitiveParameter] array $krsNumbers): self
    {
        self::validateBatch($krsNumbers, 10, 'KRS');

        return new self('Krsy', implode(',', $krsNumbers));
    }

    /** @param list<string> $regons */
    public static function regons9(
        #[\SensitiveParameter] array $regons,
        bool $validateChecksum = false,
    ): self {
        self::validateBatch($regons, 9, 'REGON9');

        if ($validateChecksum) {
            foreach ($regons as $regon) {
                PolishIdentifierChecksum::assertValidRegon9($regon);
            }
        }

        return new self('Regony9zn', implode(',', $regons));
    }

    /** @param list<string> $regons */
    public static function regons14(
        #[\SensitiveParameter] array $regons,
        bool $validateChecksum = false,
    ): self {
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
        $this->ensureAvailable();

        return in_array($this->field, ['Krs', 'Nip', 'Regon'], true)
            ? 1
            : substr_count($this->value(), ',') + 1;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'value') {
            return $this->value();
        }

        trigger_error(sprintf(
            'Undefined property: %s::$%s',
            self::class,
            $name,
        ), E_USER_WARNING);

        return null;
    }

    public function __isset(string $name): bool
    {
        return $name === 'value' && $this->available;
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        return [];
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(#[\SensitiveParameter] array $data): void
    {
        unset($data);

        $this->field = 'Nip';
        $this->value = new SensitiveParameterValue('');
        $this->available = false;
    }

    /** @param array<string, mixed> $properties */
    public static function __set_state(#[\SensitiveParameter] array $properties): self
    {
        unset($properties);

        return new self('Nip', '', false);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        if (! $this->available) {
            return [
                'field' => '[UNAVAILABLE]',
                'value' => '[UNAVAILABLE]',
            ];
        }

        return [
            'field' => $this->field,
            'value' => '[REDACTED]',
        ];
    }

    private function value(): string
    {
        $this->ensureAvailable();

        $value = $this->value->getValue();

        if (! is_string($value)) {
            throw new LogicException('The BIR search criterion is unavailable.');
        }

        return $value;
    }

    private function ensureAvailable(): void
    {
        if (! $this->available) {
            throw new LogicException(sprintf(
                'Serialization of %s is not supported.',
                self::class,
            ));
        }
    }

    private static function validateIdentifier(
        #[\SensitiveParameter] string $identifier,
        int $length,
        string $type,
    ): void {
        if (! preg_match('/^\d{'.$length.'}$/D', $identifier)) {
            throw new BirValidationException("{$type} must contain exactly {$length} digits.");
        }
    }

    /** @param list<mixed> $identifiers */
    private static function validateBatch(
        #[\SensitiveParameter] array $identifiers,
        int $length,
        string $type,
    ): void {
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
