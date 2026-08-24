<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Normalization;

use BackedEnum;
use cieplik206\BirRegon\Enums\EntityType;
use cieplik206\BirRegon\Enums\NipStatus;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Enums\Silo;
use cieplik206\BirRegon\Exceptions\BirProtocolException;
use cieplik206\BirRegon\Protocol\XsDate;
use cieplik206\BirRegon\Protocol\XsInteger;
use DateTimeImmutable;
use DateTimeZone;

/** @internal */
final class ReportRow
{
    private bool $hasRecognizedValue = false;

    /** @param array<string, string> $fields */
    public function __construct(
        private ReportType $reportType,
        #[\SensitiveParameter]
        private array $fields,
    ) {}

    public function string(string ...$fields): ?string
    {
        $value = null;

        foreach ($fields as $field) {
            if (! array_key_exists($field, $this->fields)) {
                continue;
            }

            $candidate = $this->fields[$field];

            if ($candidate === '') {
                continue;
            }

            $this->hasRecognizedValue = true;

            if ($value !== null && $candidate !== $value) {
                $this->invalid($fields[0]);
            }

            $value = $candidate;
        }

        return $value;
    }

    public function hasRecognizedValue(): bool
    {
        return $this->hasRecognizedValue;
    }

    public function date(string ...$fields): ?DateTimeImmutable
    {
        $field = $fields[0];
        $value = $this->string(...$fields);

        if ($value === null) {
            return null;
        }

        $date = XsDate::toDateTimeImmutable(
            $value,
            new DateTimeZone('Europe/Warsaw'),
        );

        if ($date === null) {
            $this->invalid($field);
        }

        return $date;
    }

    public function boundedString(int $maxLength, string ...$fields): ?string
    {
        $field = $fields[0];
        $value = $this->string(...$fields);

        if ($value === null) {
            return null;
        }

        $length = preg_match_all('/./us', $value);

        if ($length === false || $length > $maxLength) {
            $this->invalid($field);
        }

        return $value;
    }

    public function boolean(string ...$fields): ?bool
    {
        $field = $fields[0];
        $value = $this->string(...$fields);

        if ($value !== null) {
            $value = preg_replace(
                '/[\x09\x0A\x0D\x20]+/',
                ' ',
                trim($value, "\x09\x0A\x0D\x20"),
            );

            if ($value === null) {
                $this->invalid($field);
            }
        }

        return match ($value) {
            null => null,
            'true', '1' => true,
            'false', '0' => false,
            default => $this->invalid($field),
        };
    }

    public function flag(string ...$fields): ?bool
    {
        $field = $fields[0];
        $value = $this->string(...$fields);

        return match ($value) {
            null => null,
            '1' => true,
            '0' => false,
            default => $this->invalid($field),
        };
    }

    public function nonNegativeInteger(string ...$fields): ?int
    {
        $field = $fields[0];
        $value = $this->string(...$fields);

        if ($value === null) {
            return null;
        }

        $integer = XsInteger::toNonNegativeInt($value);

        if ($integer === null) {
            $this->invalid($field);
        }

        return $integer;
    }

    /** @param list<int> $lengths */
    public function regon(array $lengths, string ...$fields): ?string
    {
        $field = $fields[0];
        $value = $this->string(...$fields);

        if (
            $value !== null
            && (preg_match('/^\d+$/D', $value) !== 1 || ! in_array(strlen($value), $lengths, true))
        ) {
            $this->invalid($field);
        }

        return $value;
    }

    public function nip(string ...$fields): ?string
    {
        $field = $fields[0];
        $value = $this->string(...$fields);

        if ($value !== null && preg_match('/^\d{10}$/D', $value) !== 1) {
            $this->invalid($field);
        }

        return $value;
    }

    public function nipStatus(string ...$fields): ?NipStatus
    {
        return $this->backedEnum(NipStatus::class, ...$fields);
    }

    public function entityType(string ...$fields): ?EntityType
    {
        return $this->backedEnum(EntityType::class, ...$fields);
    }

    public function silo(string ...$fields): ?Silo
    {
        $field = $fields[0];
        $value = $this->nonNegativeInteger(...$fields);

        if ($value === null) {
            return null;
        }

        $silo = Silo::tryFrom($value);

        if ($silo === null) {
            $this->invalid($field);
        }

        return $silo;
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum|null
     */
    private function backedEnum(string $enum, string ...$fields): ?BackedEnum
    {
        $field = $fields[0];
        $value = $this->string(...$fields);

        if ($value === null) {
            return null;
        }

        $case = $enum::tryFrom($value);

        if ($case === null) {
            $this->invalid($field);
        }

        return $case;
    }

    private function invalid(string $field): never
    {
        throw new BirProtocolException(
            "GUS BIR returned an invalid {$field} field for {$this->reportType->value}.",
        );
    }
}
