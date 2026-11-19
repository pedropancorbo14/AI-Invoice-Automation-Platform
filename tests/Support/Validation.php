<?php

namespace Tests\Support;

use App\Support\Extraction\Confidence;
use App\Support\Extraction\ExtractionSchema;
use App\Support\Validation\ValidationInput;
use Carbon\CarbonImmutable;

/**
 * Entradas de validación a partir de la factura de ejemplo (121,00 €,
 * proveedor B12345674, cliente B87654323, 15/11/2026).
 */
class Validation
{
    public const COMPANY_TAX_ID = 'B87654323';

    /**
     * @param  array<string, mixed>  $output  Cambios sobre la respuesta del modelo.
     * @param  array<string, mixed>  $context  companyTaxId, minConfidence, today, accountId...
     */
    public static function input(array $output = [], array $context = []): ValidationInput
    {
        $normalized = ExtractionSchema::normalize(FakeAnthropicApi::invoiceOutput($output));

        return new ValidationInput(
            documentType: $normalized['type'],
            data: $normalized['data'],
            confidence: array_map(fn (Confidence $c) => $c->value, $normalized['confidence']),
            notes: $normalized['notes'],
            companyTaxId: array_key_exists('companyTaxId', $context) ? $context['companyTaxId'] : self::COMPANY_TAX_ID,
            companyName: $context['companyName'] ?? 'Panadería La Espiga S.L.',
            minConfidence: $context['minConfidence'] ?? Confidence::High,
            today: $context['today'] ?? CarbonImmutable::parse('2026-11-20', 'Europe/Madrid'),
            accountId: $context['accountId'] ?? null,
            companyId: $context['companyId'] ?? null,
            documentId: $context['documentId'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function vat(float $rate, float $base, float $tax, array $overrides = []): array
    {
        return array_replace([
            'rate' => $rate, 'base' => $base, 'tax_amount' => $tax, 'surcharge_rate' => null, 'surcharge_amount' => null,
        ], $overrides);
    }
}
