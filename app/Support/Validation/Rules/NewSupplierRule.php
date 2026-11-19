<?php

namespace App\Support\Validation\Rules;

use App\Models\Supplier;
use App\Support\Validation\Direction;
use App\Support\Validation\Rule;
use App\Support\Validation\RuleFailure;
use App\Support\Validation\ValidationInput;

/**
 * La primera factura de cada proveedor la revisa una persona; las
 * siguientes pasan solas si todo cuadra. Solo para facturas recibidas: las
 * emitidas salen del programa de la propia empresa.
 */
class NewSupplierRule implements Rule
{
    public function check(ValidationInput $input): array
    {
        $taxId = $input->supplierTaxId();

        if ($input->companyId === null || $taxId === null || DirectionRule::resolve($input) !== Direction::Received) {
            return [];
        }

        $known = Supplier::withoutGlobalScopes()
            ->where('company_id', $input->companyId)
            ->where('tax_id', $taxId)
            ->where('validated_count', '>', 0)
            ->exists();

        if ($known) {
            return [];
        }

        $name = $input->data['supplier']['name'] ?? null;

        return [new RuleFailure(
            'new_supplier',
            sprintf('Proveedor nuevo: %s. La primera factura de cada proveedor se revisa.', $name ? "{$name} ({$taxId})" : $taxId),
            'supplier_tax_id',
        )];
    }
}
