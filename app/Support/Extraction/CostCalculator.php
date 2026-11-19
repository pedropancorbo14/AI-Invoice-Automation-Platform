<?php

namespace App\Support\Extraction;

/**
 * Coste en USD de una llamada, con los precios de config('invoices.extraction.pricing').
 */
class CostCalculator
{
    public static function cost(string $model, int $input, int $output, int $cacheWrite = 0, int $cacheRead = 0): float
    {
        $prices = config("invoices.extraction.pricing.{$model}");

        if (! is_array($prices)) {
            return 0.0;
        }

        $total = $input * (float) $prices['input']
            + $output * (float) $prices['output']
            + $cacheWrite * (float) $prices['cache_write']
            + $cacheRead * (float) $prices['cache_read'];

        return round($total / 1_000_000, 6);
    }

    public static function knows(string $model): bool
    {
        return is_array(config("invoices.extraction.pricing.{$model}"));
    }
}
