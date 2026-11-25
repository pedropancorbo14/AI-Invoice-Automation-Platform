<?php

namespace App\Support\Export\Ledger;

use App\Enums\DocumentStatus;
use App\Models\Company;
use App\Models\Document;
use App\Support\Extraction\DocumentType;
use App\Support\Money;
use App\Support\Validation\Direction;
use Illuminate\Database\Eloquent\Collection;

/**
 * Libro de facturas de una empresa en un periodo: las facturas validadas (o
 * ya exportadas), una fila por tipo de IVA, como lo espera un programa
 * contable o una hoja de cálculo.
 */
class InvoiceLedger
{
    public const HEADERS = [
        'Fecha', 'Número', 'Tipo', 'Documento', 'Proveedor / Cliente', 'CIF/NIF',
        'Base', '% IVA', 'Cuota IVA', '% Recargo', 'Recargo', '% IRPF', 'Retención', 'Total',
        'Moneda', 'Cuenta contable', 'Categoría', 'ID documento',
    ];

    /**
     * Filas con importes en céntimos (int) o porcentajes (float); null = vacío.
     *
     * @return list<list<string|int|float|null>>
     */
    public function rows(Company $company, Period $period): array
    {
        $documents = $this->documents($company, $period);

        $rows = [];

        foreach ($documents as $document) {
            $data = $document->latestExtraction->data ?? [];
            $received = $document->direction !== Direction::Issued;
            $party = $received ? ($data['supplier'] ?? []) : ($data['customer'] ?? []);
            $breakdown = (array) ($data['tax_breakdown'] ?? []);

            foreach (array_values($breakdown) as $i => $line) {
                $first = $i === 0;
                $rows[] = [
                    $data['issue_date'] ?? null,
                    $data['invoice_number'] ?? null,
                    $received ? 'Recibida' : 'Emitida',
                    $this->type($document->latestExtraction->document_type ?? DocumentType::Invoice),
                    $party['name'] ?? null,
                    $party['tax_id'] ?? null,
                    Money::cents($line['base'] ?? null),
                    $this->rate($line['rate'] ?? null),
                    Money::cents($line['tax_amount'] ?? null),
                    $this->rate($line['surcharge_rate'] ?? null),
                    Money::cents($line['surcharge_amount'] ?? null),
                    $first ? $this->rate($data['withholding']['rate'] ?? null) : null,
                    $first ? Money::cents($data['withholding']['amount'] ?? null) : null,
                    $first ? Money::cents($data['total'] ?? null) : null,
                    $data['currency'] ?? 'EUR',
                    $received ? $document->supplier?->ledger_account : null,
                    $received ? $document->supplier?->category : null,
                    $document->id,
                ];
            }
        }

        return $rows;
    }

    /**
     * Las facturas que cuentan en un periodo: validadas o ya exportadas, por
     * fecha de emisión, ordenadas por fecha y número. El resumen de IVA usa
     * esta misma selección para que cuadre con el libro.
     *
     * @return Collection<int, Document>
     */
    public function documents(Company $company, Period $period): Collection
    {
        return Document::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('status', [DocumentStatus::Validated, DocumentStatus::Exported])
            ->with(['latestExtraction', 'supplier'])
            ->get()
            ->filter(fn (Document $d) => $d->latestExtraction !== null
                && $period->contains((string) ($d->latestExtraction->data['issue_date'] ?? '')))
            ->sortBy(fn (Document $d) => ($d->latestExtraction?->data['issue_date'] ?? '').'|'.($d->latestExtraction?->data['invoice_number'] ?? ''))
            ->values();
    }

    /** Columnas que son importes en céntimos. */
    public static function isAmountColumn(int $index): bool
    {
        return in_array($index, [6, 8, 10, 12, 13], true);
    }

    private function rate(mixed $rate): ?float
    {
        return $rate === null || $rate === '' ? null : (float) $rate;
    }

    private function type(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::SimplifiedInvoice => 'Ticket',
            DocumentType::CreditNote => 'Rectificativa',
            default => 'Factura',
        };
    }
}
