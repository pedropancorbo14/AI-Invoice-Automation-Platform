<?php

namespace App\Support\Validation\Rules;

use App\Support\Money;
use App\Support\Validation\Rule;
use App\Support\Validation\RuleFailure;
use App\Support\Validation\ValidationInput;

/**
 * Tipos de IVA válidos en España y cuota coherente con la base de cada tipo
 * (y lo mismo con el recargo de equivalencia).
 */
class TaxRatesRule implements Rule
{
    public function check(ValidationInput $input): array
    {
        $rows = (array) ($input->data['tax_breakdown'] ?? []);

        if ($rows === []) {
            return [new RuleFailure('tax_rates', 'No hay desglose de IVA.', 'tax_breakdown')];
        }

        $failures = [];
        $tolerance = (int) config('invoices.validation.tolerance_cents');
        $allowed = array_map('floatval', (array) config('invoices.validation.vat_rates'));

        foreach ($rows as $row) {
            $rate = (float) ($row['rate'] ?? -1);
            $base = Money::cents($row['base'] ?? null);
            $tax = Money::cents($row['tax_amount'] ?? null);

            if (! in_array($rate, $allowed, true)) {
                $failures[] = new RuleFailure('tax_rates', 'Tipo de IVA no válido en España: '.Money::percent($rate).'.', 'tax_breakdown');

                continue;
            }

            if ($base === null || $tax === null) {
                $failures[] = new RuleFailure('tax_rates', 'Falta la base o la cuota del IVA al '.Money::percent($rate).'.', 'tax_breakdown');

                continue;
            }

            $expected = (int) round($base * $rate / 100);

            if (abs($expected - $tax) > $tolerance) {
                $failures[] = new RuleFailure('tax_rates', sprintf(
                    'El IVA al %s no cuadra: %s × %s = %s, pero pone %s.',
                    Money::percent($rate), Money::format($base), Money::percent($rate), Money::format($expected), Money::format($tax),
                ), 'tax_breakdown');
            }

            array_push($failures, ...$this->surcharge($row, $rate, $base, $tolerance));
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<RuleFailure>
     */
    private function surcharge(array $row, float $rate, int $base, int $tolerance): array
    {
        $surchargeRate = $row['surcharge_rate'] ?? null;

        if ($surchargeRate === null || (float) $surchargeRate === 0.0) {
            return [];
        }

        $surchargeRate = (float) $surchargeRate;
        $valid = array_map('floatval', (array) config('invoices.validation.surcharge_rates.'.(int) $rate, []));

        if (! in_array($surchargeRate, $valid, true)) {
            return [new RuleFailure('tax_rates', sprintf(
                'Recargo de equivalencia del %s no corresponde al IVA del %s.',
                Money::percent($surchargeRate), Money::percent($rate),
            ), 'tax_breakdown')];
        }

        $amount = Money::cents($row['surcharge_amount'] ?? null);
        $expected = (int) round($base * $surchargeRate / 100);

        if ($amount === null || abs($expected - $amount) > $tolerance) {
            return [new RuleFailure('tax_rates', sprintf(
                'El recargo de equivalencia del %s no cuadra: debería ser %s.',
                Money::percent($surchargeRate), Money::format($expected),
            ), 'tax_breakdown')];
        }

        return [];
    }
}
