<?php

namespace App\Support\Validation;

use App\Models\Document;
use App\Models\Supplier;
use App\Support\CompanyName;
use App\Support\Extraction\TaxId;
use Illuminate\Support\Facades\DB;

/**
 * Aprende de cada factura recibida que se valida (sola o por una persona).
 */
class SupplierMemory
{
    /**
     * @param  array<string, mixed>  $data  Datos de la extracción (ya corregidos si los tocó una persona).
     * @param  array{ledger_account?: string|null, category?: string|null}  $corrections  Lo que añade una persona al revisar.
     */
    public function remember(Document $document, array $data, array $corrections = []): ?Supplier
    {
        $taxId = TaxId::normalize($data['supplier']['tax_id'] ?? null);

        if ($taxId === null || $document->company_id === null || $document->direction !== Direction::Received) {
            return null;
        }

        return DB::transaction(function () use ($document, $data, $corrections, $taxId): Supplier {
            $supplier = Supplier::withoutGlobalScopes()->lockForUpdate()->firstOrNew([
                'company_id' => $document->company_id,
                'tax_id' => $taxId,
            ]);

            $name = (string) ($data['supplier']['name'] ?? $supplier->name ?? $taxId);

            $supplier->fill(array_filter([
                'account_id' => $document->account_id,
                'name' => $name,
                'normalized_name' => CompanyName::normalize($name),
                'usual_vat_rate' => $this->mainVatRate($data),
                'ledger_account' => $corrections['ledger_account'] ?? null,
                'category' => $corrections['category'] ?? null,
            ], fn ($value) => $value !== null));

            $supplier->validated_count = $supplier->validated_count + 1;
            $supplier->last_validated_at = now();
            $supplier->save();

            $document->supplier()->associate($supplier);
            $document->save();

            return $supplier;
        });
    }

    /**
     * El tipo de IVA con más base imponible.
     *
     * @param  array<string, mixed>  $data
     */
    private function mainVatRate(array $data): ?string
    {
        $rows = (array) ($data['tax_breakdown'] ?? []);
        usort($rows, fn ($a, $b) => abs((float) ($b['base'] ?? 0)) <=> abs((float) ($a['base'] ?? 0)));

        return isset($rows[0]['rate']) ? (string) $rows[0]['rate'] : null;
    }
}
