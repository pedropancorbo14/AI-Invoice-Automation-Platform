<?php

namespace App\Support\Tax;

use App\Enums\DocumentStatus;
use App\Models\Company;
use App\Models\Document;
use App\Support\Export\Ledger\InvoiceLedger;
use App\Support\Export\Ledger\Period;
use App\Support\Extraction\DocumentType;
use App\Support\Money;
use App\Support\Validation\Direction;
use Carbon\CarbonImmutable;

/**
 * Resumen de IVA de una empresa en un periodo, como base para el modelo 303
 * (IVA repercutido y soportado) y los de retenciones (111 y 130).
 *
 * Usa las mismas facturas que el libro (validadas o exportadas, por fecha de
 * emisión), así que los totales cuadran con el Excel. Todo se suma en
 * céntimos. Es orientativo: no aplica prorrata, regularizaciones ni bienes de
 * inversión, y solo cuenta las facturas en euros.
 */
class VatSummary
{
    /** Estados que todavía pueden acabar en el libro del periodo. */
    private const PENDING = [
        DocumentStatus::Received,
        DocumentStatus::Classified,
        DocumentStatus::Extracted,
        DocumentStatus::NeedsReview,
        DocumentStatus::Failed,
    ];

    public function __construct(private readonly InvoiceLedger $ledger) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Company $company, Period $period): array
    {
        $issued = new VatBook;
        $received = new VatBook;
        $withheldFromUs = new WithholdingTotal;
        $withheldByUs = new WithholdingTotal;
        $tickets = 0;
        $otherCurrency = 0;

        foreach ($this->ledger->documents($company, $period) as $document) {
            $data = $document->latestExtraction->data ?? [];

            if (strtoupper((string) ($data['currency'] ?? 'EUR')) !== 'EUR') {
                $otherCurrency++;

                continue;
            }

            $isIssued = $document->direction === Direction::Issued;
            $lines = array_values(array_filter((array) ($data['tax_breakdown'] ?? []), 'is_array'));
            ($isIssued ? $issued : $received)->add($lines);

            $withholding = Money::cents($data['withholding']['amount'] ?? null) ?? 0;
            if ($withholding !== 0) {
                // En las emitidas te retiene el cliente (130); en las recibidas
                // retienes tú al profesional y lo ingresas en el 111.
                ($isIssued ? $withheldFromUs : $withheldByUs)->add($lines, $withholding);
            }

            if (! $isIssued && $document->latestExtraction?->document_type === DocumentType::SimplifiedInvoice) {
                $tickets++;
            }
        }

        // El recargo de equivalencia que se repercute se ingresa con el IVA;
        // el soportado no se puede deducir.
        $result = $issued->tax + $issued->surcharge - $received->tax;

        return [
            'period' => [
                'key' => $period->key,
                'label' => $period->label,
                'from' => $period->from->toDateString(),
                'to' => $period->to->toDateString(),
            ],
            'currency' => 'EUR',
            'issued' => $issued->toArray(),
            'received' => $received->toArray() + ['tickets' => $tickets],
            'withholdings' => [
                'issued' => $withheldFromUs->toArray(),
                'received' => $withheldByUs->toArray(),
            ],
            'result' => [
                'amount' => Money::decimal($result),
                'kind' => match (true) {
                    $result > 0 => 'to_pay',
                    $result < 0 => 'to_offset',
                    default => 'zero',
                },
            ],
            'warnings' => [
                'pending' => $this->pending($company, $period),
                'other_currency' => $otherCurrency,
            ],
        ];
    }

    /**
     * Documentos del periodo que aún no están validados: hasta que se
     * resuelvan, el resumen no es definitivo. Sin fecha de emisión leída, se
     * usa el día en que llegaron.
     */
    private function pending(Company $company, Period $period): int
    {
        $timezone = (string) config('invoices.validation.timezone');

        return Document::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('status', self::PENDING)
            ->with('latestExtraction')
            ->get()
            ->filter(function (Document $document) use ($period, $timezone): bool {
                $date = (string) ($document->latestExtraction->data['issue_date'] ?? '');

                if ($date === '' && $document->created_at !== null) {
                    $date = CarbonImmutable::parse($document->created_at)->setTimezone($timezone)->toDateString();
                }

                return $period->contains($date);
            })
            ->count();
    }
}
