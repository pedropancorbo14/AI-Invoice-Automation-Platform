<?php

namespace App\Support\Review;

/**
 * Motivos por los que un documento está en revisión, para filtrar la bandeja.
 * Los de validación coinciden con el código de cada regla.
 */
class ReviewCode
{
    public const LABELS = [
        // Validación (fase 4)
        'required' => 'Faltan datos',
        'currency' => 'Otra moneda',
        'tax_id' => 'CIF/NIF no válido',
        'dates' => 'Fechas',
        'tax_rates' => 'Tipos de IVA',
        'arithmetic' => 'El total no cuadra',
        'direction' => 'No es de este cliente',
        'confidence' => 'Datos dudosos',
        'duplicate' => 'Posible duplicado',
        'new_supplier' => 'Proveedor nuevo',
        // Entrada y extracción
        'link_invoice' => 'Factura por enlace',
        'unreadable' => 'No se puede leer',
        'ai_refused' => 'La IA no lo procesó',
        'not_invoice' => 'Albarán o presupuesto',
        'no_data' => 'Sin datos extraídos',
    ];

    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? $code;
    }
}
