<?php

namespace App\Support\Validation;

use App\Support\Extraction\TaxId;

/**
 * Formato y dígito de control de los identificadores fiscales españoles.
 *
 * - NIF de persona (DNI): 8 dígitos + letra.
 * - NIE: X/Y/Z + 7 dígitos + letra.
 * - NIF especial: K/L/M + 7 dígitos + letra.
 * - CIF (personas jurídicas): letra + 7 dígitos + control (dígito o letra).
 *
 * Los NIF-IVA de otros países de la UE (DE123..., FR...) se aceptan sin
 * comprobar el dígito: cada país tiene su algoritmo.
 */
class SpanishTaxId
{
    private const DNI_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    private const CIF_LETTERS = 'JABCDEFGHI';

    public static function isValid(?string $value): bool
    {
        $id = TaxId::normalize($value);

        if ($id === null) {
            return false;
        }

        return match (true) {
            (bool) preg_match('/^\d{8}[A-Z]$/', $id) => self::dni($id),
            (bool) preg_match('/^[XYZ]\d{7}[A-Z]$/', $id) => self::dni(strtr($id[0], 'XYZ', '012').substr($id, 1)),
            (bool) preg_match('/^[KLM]\d{7}[A-Z]$/', $id) => self::dni('0'.substr($id, 1)),
            (bool) preg_match('/^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/', $id) => self::cif($id),
            default => self::isForeignVat($id),
        };
    }

    public static function isSpanish(?string $value): bool
    {
        $id = TaxId::normalize($value);

        return $id !== null && ! self::isForeignVat($id);
    }

    private static function dni(string $id): bool
    {
        return self::DNI_LETTERS[(int) substr($id, 0, 8) % 23] === $id[8];
    }

    private static function cif(string $id): bool
    {
        $digits = substr($id, 1, 7);
        $sum = 0;

        for ($i = 0; $i < 7; $i++) {
            $digit = (int) $digits[$i];
            // Posiciones impares (1.ª, 3.ª...): se dobla y se suman sus cifras.
            $sum += $i % 2 === 0 ? array_sum(str_split((string) ($digit * 2))) : $digit;
        }

        $control = (10 - $sum % 10) % 10;
        $given = $id[8];

        return match (true) {
            // Organismos, entidades sin ánimo de lucro, extranjeras...: siempre letra.
            str_contains('NPQRSW', $id[0]) => $given === self::CIF_LETTERS[$control],
            // Sociedades anónimas y limitadas, comunidades de bienes...: siempre número.
            str_contains('ABEH', $id[0]) => $given === (string) $control,
            default => $given === (string) $control || $given === self::CIF_LETTERS[$control],
        };
    }

    /** Prefijos de NIF-IVA de la UE (EL = Grecia, XI = Irlanda del Norte) y vecinos habituales. */
    private const VAT_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT',
        'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI', 'GB', 'CH', 'NO',
    ];

    private static function isForeignVat(string $id): bool
    {
        return in_array(substr($id, 0, 2), self::VAT_COUNTRIES, true)
            && (bool) preg_match('/^[A-Z]{2}[A-Z0-9]{2,13}$/', $id)
            && preg_match_all('/\d/', $id) >= 2;
    }
}
