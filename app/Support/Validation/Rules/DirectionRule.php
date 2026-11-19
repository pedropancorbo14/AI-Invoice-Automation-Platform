<?php

namespace App\Support\Validation\Rules;

use App\Support\Validation\Direction;
use App\Support\Validation\Rule;
use App\Support\Validation\RuleFailure;
use App\Support\Validation\ValidationInput;

/**
 * Recibida o emitida, comparando los CIF con el de la empresa. Si el CIF de
 * la empresa no aparece, la factura no es suya (o está mal asignada).
 */
class DirectionRule implements Rule
{
    public function check(ValidationInput $input): array
    {
        if ($input->companyTaxId === null) {
            return [new RuleFailure('direction', 'El documento no tiene cliente asignado: asígnalo para validarlo.')];
        }

        if (self::resolve($input) !== null) {
            return [];
        }

        return [new RuleFailure('direction', sprintf(
            'La factura no parece de %s: su CIF (%s) no aparece ni como cliente ni como proveedor.',
            $input->companyName ?? 'la empresa',
            $input->companyTaxId,
        ), 'customer_tax_id')];
    }

    public static function resolve(ValidationInput $input): ?Direction
    {
        $company = $input->companyTaxId;

        return match (true) {
            $company === null => null,
            $input->customerTaxId() === $company => Direction::Received,
            $input->supplierTaxId() === $company => Direction::Issued,
            // Un ticket no suele llevar el CIF del cliente: si no lo emite la empresa, es un gasto.
            $input->isSimplified() && $input->customerTaxId() === null => Direction::Received,
            default => null,
        };
    }
}
