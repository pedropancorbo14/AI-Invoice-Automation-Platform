<?php

namespace App\Actions\Review;

use App\Enums\DocumentEventType;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Support\Extraction\Confidence;
use App\Support\Extraction\ExtractionSchema;
use App\Support\Extraction\InputMode;
use App\Support\Review\NeedsConfirmation;
use App\Support\Validation\InvoiceKey;
use App\Support\Validation\RuleFailure;
use App\Support\Validation\SupplierMemory;
use App\Support\Validation\ValidationInput;
use App\Support\Validation\Validator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Una persona corrige (o no) los datos y aprueba la factura:
 *
 * 1. Normaliza los datos igual que los de la IA.
 * 2. Vuelve a pasar las reglas: si algo no cuadra, avisa y pide confirmación.
 * 3. Guarda cada campo cambiado en la auditoría (antes, después y quién).
 * 4. Guarda los datos corregidos como la extracción vigente.
 * 5. NEEDS_REVIEW → VALIDATED y actualiza la memoria del proveedor.
 */
class ApproveDocument
{
    /** Campos que se comparan uno a uno para la auditoría. */
    private const AUDITED_FIELDS = [
        'document_type', 'supplier.name', 'supplier.tax_id', 'customer.name', 'customer.tax_id',
        'invoice_number', 'issue_date', 'due_date', 'currency', 'tax_breakdown',
        'withholding.rate', 'withholding.amount', 'total', 'lines',
    ];

    /** Reglas que no aplican al aprobar: la persona ya lo ha mirado. */
    private const SKIPPED_RULES = ['confidence', 'duplicate', 'new_supplier'];

    public function __construct(
        private readonly Validator $validator,
        private readonly SupplierMemory $memory,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Datos de la factura con la misma forma que la extracción.
     * @param  array{ledger_account?: string|null, category?: string|null}  $supplierMemory
     *
     * @throws NeedsConfirmation
     */
    public function handle(Document $document, User $by, array $data, array $supplierMemory = [], bool $force = false): Document
    {
        if ($document->status !== DocumentStatus::NeedsReview) {
            throw new InvalidArgumentException('Solo se pueden aprobar documentos pendientes de revisión.');
        }

        $company = $document->company;

        if ($company === null) {
            throw new InvalidArgumentException('Asigna el documento a un cliente antes de aprobarlo.');
        }

        $normalized = ExtractionSchema::normalize([
            ...$data,
            'document_type' => $data['document_type'] ?? $document->latestExtraction?->document_type->value ?? 'invoice',
            'confidence' => array_fill_keys(ExtractionSchema::CONFIDENCE_FIELDS, Confidence::High->value),
            'notes' => [],
        ]);

        $report = $this->validator->validate(new ValidationInput(
            documentType: $normalized['type'],
            data: $normalized['data'],
            confidence: array_map(fn (Confidence $c) => $c->value, $normalized['confidence']),
            notes: [],
            companyTaxId: $company->tax_id,
            companyName: $company->name,
            minConfidence: Confidence::Low,
            today: CarbonImmutable::now((string) config('invoices.validation.timezone'))->startOfDay(),
        ));

        $warnings = array_values(array_filter(
            $report->failures,
            fn (RuleFailure $f) => ! in_array($f->rule, self::SKIPPED_RULES, true),
        ));

        if ($warnings !== [] && ! $force) {
            throw new NeedsConfirmation($warnings);
        }

        DB::transaction(function () use ($document, $by, $normalized, $report, $warnings, $supplierMemory): void {
            $previous = $document->latestExtraction->data ?? [];

            foreach ($this->changes($previous, $normalized['data']) as $field => [$before, $after]) {
                $document->recordEvent(DocumentEventType::Corrected, by: $by, payload: [
                    'field' => $field,
                    'before' => $before,
                    'after' => $after,
                ]);
            }

            $extraction = $document->extractions()->create([
                'account_id' => $document->account_id,
                'model' => 'revisión manual',
                'input_mode' => InputMode::Manual,
                'document_type' => $normalized['type'],
                'data' => $normalized['data'],
                'confidence' => array_map(fn (Confidence $c) => $c->value, $normalized['confidence']),
                'notes' => [],
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cost_usd' => 0,
                'duration_ms' => 0,
            ]);

            $document->direction = $report->direction;
            $document->invoice_key = InvoiceKey::for($normalized['data']);
            $document->save();

            $document->transitionTo(DocumentStatus::Validated, 'Aprobada en revisión.', $by, [
                'extraction_id' => $extraction->id,
                'forced' => $warnings !== [],
                'warnings' => array_map(fn (RuleFailure $f) => $f->message, $warnings),
            ]);

            $this->memory->remember($document, $normalized['data'], array_filter([
                'ledger_account' => $supplierMemory['ledger_account'] ?? null,
                'category' => $supplierMemory['category'] ?? null,
            ]));
        });

        return $document->refresh();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function changes(array $before, array $after): array
    {
        $changes = [];

        foreach (self::AUDITED_FIELDS as $field) {
            $old = Arr::get($before, $field);
            $new = Arr::get($after, $field);

            if ($old !== $new) {
                $changes[$field] = [$old, $new];
            }
        }

        return $changes;
    }
}
