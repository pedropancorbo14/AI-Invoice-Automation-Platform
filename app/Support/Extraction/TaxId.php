<?php

namespace App\Support\Extraction;

use Illuminate\Support\Str;

/**
 * Normaliza CIF/NIF/NIE para poder compararlos: sin espacios, guiones ni
 * puntos, en mayúsculas y sin el prefijo "ES" del NIF-IVA intracomunitario.
 */
class TaxId
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));

        if (strlen($clean) === 11 && str_starts_with($clean, 'ES')) {
            $clean = substr($clean, 2);
        }

        return $clean === '' ? null : $clean;
    }
}
