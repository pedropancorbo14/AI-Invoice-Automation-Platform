<?php

namespace App\Support\Validation;

/**
 * Si la factura la recibe la empresa (un gasto) o la emite (un ingreso).
 */
enum Direction: string
{
    case Received = 'received';
    case Issued = 'issued';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'recibida',
            self::Issued => 'emitida',
        };
    }
}
