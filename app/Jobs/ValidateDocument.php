<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\Supplier;
use App\Support\Extraction\TaxId;
use App\Support\Tenancy\CurrentAccount;
use App\Support\Validation\InvoiceKey;
use App\Support\Validation\RuleFailure;
use App\Support\Validation\SupplierMemory;
use App\Support\Validation\ValidationInput;
use App\Support\Validation\ValidationReport;
use App\Support\Validation\Validator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Decide con reglas deterministas si una factura extraída pasa sola:
 *
 *   EXTRACTED → VALIDATED      todo cuadra (y se apunta en la memoria del proveedor)
 *   EXTRACTED → NEEDS_REVIEW   alguna regla falla; el motivo explica cuál y por qué
 */
class ValidateDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $documentId) {}

    public function handle(Validator $validator, SupplierMemory $memory, CurrentAccount $currentAccount): void
    {
        $document = Document::withoutGlobalScopes()->with(['company', 'latestExtraction'])->find($this->documentId);

        if ($document === null || $document->status !== DocumentStatus::Extracted) {
            return;
        }

        $extraction = $document->latestExtraction;

        if ($extraction === null) {
            $document->sendToReview('No hay datos extraídos que validar.', ['no_data']);

            return;
        }

        $currentAccount->run($document->account_id, function () use ($document, $extraction, $validator, $memory): void {
            $report = $validator->validate($this->input($document, $extraction));

            DB::transaction(fn () => $this->apply($document, $extraction, $report, $memory));
        });
    }

    private function input(Document $document, DocumentExtraction $extraction): ValidationInput
    {
        return new ValidationInput(
            documentType: $extraction->document_type,
            data: $extraction->data,
            confidence: $extraction->confidence,
            notes: $extraction->notes,
            companyTaxId: $document->company?->tax_id,
            companyName: $document->company?->name,
            minConfidence: $document->account()->firstOrFail()->minConfidence(),
            today: CarbonImmutable::now((string) config('invoices.validation.timezone'))->startOfDay(),
            accountId: $document->account_id,
            companyId: $document->company_id,
            documentId: $document->id,
        );
    }

    private function apply(Document $document, DocumentExtraction $extraction, ValidationReport $report, SupplierMemory $memory): void
    {
        $document->direction = $report->direction;
        $document->invoice_key = InvoiceKey::for($extraction->data);
        $this->linkKnownSupplier($document, $extraction);
        $document->save();

        if (! $report->passed()) {
            $document->sendToReview($report->reason(), $report->rules(), payload: [
                'rules' => $report->rules(),
                'failures' => array_map(fn (RuleFailure $f) => $f->toArray(), $report->failures),
            ]);

            return;
        }

        $document->transitionTo(DocumentStatus::Validated, payload: [
            'direction' => $report->direction?->value,
            'extraction_id' => $extraction->id,
        ]);

        $memory->remember($document, $extraction->data);
    }

    /**
     * Enlaza el proveedor si ya lo conocemos, aunque la factura vaya a revisión.
     */
    private function linkKnownSupplier(Document $document, DocumentExtraction $extraction): void
    {
        $taxId = TaxId::normalize($extraction->data['supplier']['tax_id'] ?? null);

        if ($taxId === null || $document->company_id === null) {
            return;
        }

        $supplier = Supplier::withoutGlobalScopes()
            ->where('company_id', $document->company_id)
            ->where('tax_id', $taxId)
            ->first();

        $document->supplier()->associate($supplier);
    }
}
