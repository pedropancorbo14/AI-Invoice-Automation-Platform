<?php

namespace App\Support;

/**
 * Importes en céntimos (enteros) para sumar y comparar sin errores de coma
 * flotante. Los importes se guardan como texto "1234.56".
 */
class Money
{
    public static function cents(?string $amount): ?int
    {
        if ($amount === null || ! is_numeric($amount)) {
            return null;
        }

        return (int) round((float) $amount * 100);
    }

    /**
     * Céntimos a texto con 2 decimales, como se guardan: -1234 → "-12.34".
     */
    public static function decimal(int $cents): string
    {
        $abs = abs($cents);

        return ($cents < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Formato español para los motivos: 1234.5 € → "1.234,50".
     */
    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }

    public static function percent(float|string|null $rate): string
    {
        $rate = (float) $rate;

        return rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',').' %';
    }
}
