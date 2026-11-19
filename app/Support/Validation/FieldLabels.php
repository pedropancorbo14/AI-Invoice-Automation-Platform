<?php

namespace App\Support\Validation;

class FieldLabels
{
    private const LABELS = [
        'document_type' => 'tipo de documento',
        'supplier_name' => 'nombre del proveedor',
        'supplier_tax_id' => 'CIF del proveedor',
        'customer_tax_id' => 'CIF del cliente',
        'invoice_number' => 'número de factura',
        'issue_date' => 'fecha de emisión',
        'due_date' => 'fecha de vencimiento',
        'tax_breakdown' => 'desglose de IVA',
        'withholding' => 'retención',
        'total' => 'total',
        'currency' => 'moneda',
    ];

    public static function for(string $field): string
    {
        return self::LABELS[$field] ?? $field;
    }
}
