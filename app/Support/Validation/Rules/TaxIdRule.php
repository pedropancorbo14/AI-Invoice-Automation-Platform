<?php

namespace App\Support\Validation\Rules;

use App\Support\Validation\Rule;
use App\Support\Validation\RuleFailure;
use App\Support\Validation\SpanishTaxId;
use App\Support\Validation\ValidationInput;

/**
 * Formato y dígito de control de CIF, NIF y NIE.
 */
class TaxIdRule implements Rule
{
    public function check(ValidationInput $input): array
    {
        $failures = [];

        foreach (['supplier' => 'proveedor', 'customer' => 'cliente'] as $party => $label) {
            $taxId = $party === 'supplier' ? $input->supplierTaxId() : $input->customerTaxId();

            if ($taxId !== null && ! SpanishTaxId::isValid($taxId)) {
                $failures[] = new RuleFailure(
                    'tax_id',
                    "El CIF/NIF del {$label} no es válido: {$taxId}.",
                    "{$party}_tax_id",
                );
            }
        }

        return $failures;
    }
}
