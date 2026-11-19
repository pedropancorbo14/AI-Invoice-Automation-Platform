<?php

namespace App\Support\Validation\Rules;

use App\Support\Validation\Rule;
use App\Support\Validation\RuleFailure;
use App\Support\Validation\ValidationInput;

/**
 * Lo mínimo para poder contabilizar una factura.
 */
class RequiredFieldsRule implements Rule
{
    public function check(ValidationInput $input): array
    {
        $failures = [];

        if (($input->data['invoice_number'] ?? null) === null) {
            $failures[] = new RuleFailure('required', 'Falta el número de factura.', 'invoice_number');
        }

        if (($input->data['total'] ?? null) === null) {
            $failures[] = new RuleFailure('required', 'Falta el total de la factura.', 'total');
        }

        if ($input->supplierTaxId() === null) {
            $failures[] = new RuleFailure('required', 'Falta el CIF del proveedor.', 'supplier_tax_id');
        }

        // En una factura completa el CIF del cliente es obligatorio; en un ticket, no.
        if ($input->customerTaxId() === null && ! $input->isSimplified()) {
            $failures[] = new RuleFailure('required', 'Falta el CIF del cliente.', 'customer_tax_id');
        }

        $currency = $input->data['currency'] ?? 'EUR';
        if ($currency !== 'EUR') {
            $failures[] = new RuleFailure('currency', "Factura en {$currency}: revisa el cambio a euros.", 'currency');
        }

        return $failures;
    }
}
