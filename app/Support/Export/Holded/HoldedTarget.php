<?php

namespace App\Support\Export\Holded;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentExport;
use App\Models\DocumentExtraction;
use App\Support\Export\Exceptions\ExportFailed;
use App\Support\Export\Exceptions\ExportSkipped;
use App\Support\Export\ExportTarget;
use App\Support\Extraction\DocumentType;
use App\Support\Validation\Direction;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Storage;

/**
 * Cada factura recibida y validada se crea en Holded como gasto, con su PDF.
 */
class HoldedTarget implements ExportTarget
{
    /**
     * @param  (Closure(string): HoldedClient)|null  $clientFactory
     */
    public function __construct(private readonly ?Closure $clientFactory = null) {}

    public function key(): string
    {
        return 'holded';
    }

    public function label(): string
    {
        return 'Holded';
    }

    public function enabledFor(Company $company): bool
    {
        return filled($company->holded_api_key);
    }

    public function export(Document $document, DocumentExtraction $extraction, DocumentExport $record): string
    {
        if ($document->direction !== Direction::Received) {
            throw new ExportSkipped('Factura emitida: en Holded solo se registran los gastos.');
        }

        $docType = $extraction->document_type === DocumentType::CreditNote ? 'purchaserefund' : 'purchase';
        $client = $this->client((string) $document->company?->holded_api_key);

        // Si un intento anterior ya lo creó (y falló al adjuntar), no se crea otra vez.
        $externalId = $record->external_id ?? $client->createDocument($docType, $this->payload($document, $extraction));

        if ($record->external_id === null) {
            $record->forceFill(['external_id' => $externalId])->save();
        }

        $client->attachFile(
            $docType,
            $externalId,
            (string) Storage::disk($document->disk)->get($document->path),
            $document->original_filename,
        );

        return $externalId;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Document $document, DocumentExtraction $extraction): array
    {
        $data = $extraction->data;
        $rows = (array) ($data['tax_breakdown'] ?? []);

        foreach ($rows as $row) {
            if (($row['surcharge_amount'] ?? null) !== null && (float) $row['surcharge_amount'] !== 0.0) {
                throw new ExportFailed('Lleva recargo de equivalencia, que no se exporta automáticamente a Holded: regístrala a mano.');
            }
        }

        if ($rows === []) {
            throw new ExportFailed('No hay desglose de IVA que exportar.');
        }

        $supplier = (string) ($data['supplier']['name'] ?? $data['supplier']['tax_id']);
        $number = (string) $data['invoice_number'];
        $retention = (float) ($data['withholding']['rate'] ?? 0);
        $sign = $extraction->document_type === DocumentType::CreditNote ? -1 : 1;

        $items = array_map(fn (array $row) => array_filter([
            'name' => count($rows) > 1 ? "{$supplier} · {$number} · IVA {$row['rate']}%" : "{$supplier} · {$number}",
            'units' => 1,
            // En un abono los importes van en positivo: el tipo ya indica que es un abono.
            'subtotal' => round($sign * (float) $row['base'], 2),
            'tax' => (float) $row['rate'],
            'retention' => $retention > 0 ? $retention : null,
        ], fn ($value) => $value !== null), $rows);

        return array_filter([
            'contactCode' => $data['supplier']['tax_id'],
            'contactName' => $supplier,
            'applyContactDefaults' => true,
            'invoiceNum' => $number,
            'date' => $this->timestamp($data['issue_date']),
            'dueDate' => $data['due_date'] !== null ? $this->timestamp($data['due_date']) : null,
            'currency' => strtolower((string) ($data['currency'] ?? 'EUR')),
            'isReceipt' => $extraction->document_type === DocumentType::SimplifiedInvoice ? true : null,
            'notes' => "Importada por Facturas IA (documento #{$document->id}).",
            'items' => $items,
        ], fn ($value) => $value !== null);
    }

    private function timestamp(string $date): int
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Europe/Madrid')?->getTimestamp()
            ?? throw new ExportFailed("Fecha no válida: {$date}.");
    }

    private function client(string $apiKey): HoldedClient
    {
        return $this->clientFactory !== null
            ? ($this->clientFactory)($apiKey)
            : new HoldedClient($apiKey, (string) config('invoices.export.holded.base_url'), (int) config('invoices.export.holded.timeout'));
    }
}
