<?php

namespace App\Support\Extraction;

/**
 * Tipo de documento según la IA. Si es recibida o emitida no lo decide la IA:
 * lo decide Laravel comparando los CIF con el de la empresa (fase 4).
 */
enum DocumentType: string
{
    case Invoice = 'invoice';
    case SimplifiedInvoice = 'simplified_invoice';
    case CreditNote = 'credit_note';
    case DeliveryNote = 'delivery_note';
    case Quote = 'quote';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'factura',
            self::SimplifiedInvoice => 'ticket o factura simplificada',
            self::CreditNote => 'factura rectificativa',
            self::DeliveryNote => 'albarán',
            self::Quote => 'presupuesto',
            self::Other => 'otro documento',
        };
    }
}
