<?php

namespace App\Support\Export\Ledger;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Periodo de exportación: un mes (2026-11), un trimestre (2026-T4 o
 * 2026-Q4) o un año (2026).
 */
final readonly class Period
{
    private const MONTHS = [
        1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    public function __construct(
        public string $key,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $label,
    ) {}

    public static function parse(string $value): self
    {
        $value = strtoupper(trim($value));

        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            $from = self::firstDay((int) $m[1], (int) $m[2]);

            return new self($value, $from, $from->endOfMonth(), self::MONTHS[(int) $m[2]]." de {$m[1]}");
        }

        if (preg_match('/^(\d{4})-[TQ]([1-4])$/', $value, $m)) {
            $from = self::firstDay((int) $m[1], ((int) $m[2] - 1) * 3 + 1);

            return new self("{$m[1]}-T{$m[2]}", $from, $from->addMonths(2)->endOfMonth(), "{$m[2]}.º trimestre de {$m[1]}");
        }

        if (preg_match('/^(\d{4})$/', $value, $m)) {
            $from = self::firstDay((int) $m[1], 1);

            return new self($value, $from, $from->endOfYear(), "año {$m[1]}");
        }

        throw new InvalidArgumentException("Periodo no válido: «{$value}». Usa 2026-11, 2026-T4 o 2026.");
    }

    private static function firstDay(int $year, int $month): CarbonImmutable
    {
        return CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, $month));
    }

    public function contains(string $date): bool
    {
        return $date >= $this->from->toDateString() && $date <= $this->to->toDateString();
    }
}
