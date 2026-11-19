<?php

namespace App\Support\Validation\Rules;

use App\Support\Money;
use App\Support\Validation\Rule;
use App\Support\Validation\RuleFailure;
use App\Support\Validation\ValidationInput;

/**
 * Bases + IVA + recargo − retención = total, con ±0,02 € de tolerancia.
 * También comprueba que la retención cuadra con su porcentaje.
 */
class ArithmeticRule implements Rule
{
    public function check(ValidationInput $input): array
    {
        $total = Money::cents($input->data['total'] ?? null);
        $rows = (array) ($input->data['tax_breakdown'] ?? []);

        // Si falta el total o el desglose ya lo avisan otras reglas.
        if ($total === null || $rows === []) {
            return [];
        }

        $tolerance = (int) config('invoices.validation.tolerance_cents');
        $bases = $taxes = $surcharges = 0;

        foreach ($rows as $row) {
            $bases += Money::cents($row['base'] ?? null) ?? 0;
            $taxes += Money::cents($row['tax_amount'] ?? null) ?? 0;
            $surcharges += Money::cents($row['surcharge_amount'] ?? null) ?? 0;
        }

        $withholding = abs(Money::cents($input->data['withholding']['amount'] ?? null) ?? 0);
        $failures = [];

        $computed = $bases + $taxes + $surcharges - $withholding;

        if (abs($computed - $total) > $tolerance) {
            $parts = [Money::format($bases), Money::format($taxes)];
            $formula = implode(' + ', $parts)
                .($surcharges !== 0 ? ' + '.Money::format($surcharges) : '')
                .($withholding !== 0 ? ' − '.Money::format($withholding) : '');

            $failures[] = new RuleFailure(
                'arithmetic',
                'El total no cuadra: '.Money::format($total).' ≠ '.$formula.'.',
                'total',
            );
        }

        $rate = $input->data['withholding']['rate'] ?? null;

        if ($rate !== null && (float) $rate > 0 && $withholding !== 0) {
            $expected = (int) round($bases * (float) $rate / 100);

            if (abs($expected - $withholding) > $tolerance) {
                $failures[] = new RuleFailure('arithmetic', sprintf(
                    'La retención no cuadra: %s × %s = %s, pero pone %s.',
                    Money::format($bases), Money::percent((float) $rate), Money::format($expected), Money::format($withholding),
                ), 'withholding');
            }
        }

        return $failures;
    }
}
