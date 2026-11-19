<?php

namespace App\Support\Validation;

use App\Support\Extraction\TaxId;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * Identidad de negocio de una factura: CIF del proveedor + número + total.
 * Dos documentos con la misma clave son la misma factura aunque el PDF sea
 * distinto (otro escaneo, reenviada desde otro sitio...).
 */
class InvoiceKey
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function for(array $data): ?string
    {
        $taxId = TaxId::normalize($data['supplier']['tax_id'] ?? null);
        $number = Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($data['invoice_number'] ?? '')));
        $total = Money::cents($data['total'] ?? null);

        if ($taxId === null || $number === '' || $total === null) {
            return null;
        }

        return hash('sha256', "{$taxId}|{$number}|{$total}");
    }
}
