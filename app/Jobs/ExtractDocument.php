<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Support\Extraction\DocumentType;
use App\Support\Extraction\Exceptions\ExtractionFailed;
use App\Support\Extraction\Exceptions\ExtractionRefused;
use App\Support\Extraction\Exceptions\UnsupportedInput;
use App\Support\Extraction\ExtractionInput;
use App\Support\Extraction\ExtractionResult;
use App\Support\Extraction\InputPreparer;
use App\Support\Extraction\LLMProvider;
use App\Support\Tenancy\CurrentAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pasa un documento recibido por la IA y decide qué hacer con el resultado:
 *
 *   RECEIVED → CLASSIFIED → EXTRACTED       (factura, ticket, rectificativa)
 *                         → IGNORED         (no es una factura)
 *                         → NEEDS_REVIEW    (albarán o presupuesto)
 *   RECEIVED → NEEDS_REVIEW                 (la IA lo rechaza o no se puede leer)
 *   RECEIVED → FAILED                       (error técnico, tras los reintentos)
 *
 * Después de EXTRACTED encola la validación (ValidateDocument).
 *
 * Los errores pasajeros (429, 5xx, red) se reintentan con espera creciente.
 */
class ExtractDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** Un poco más que el timeout de la llamada a la API. */
    public int $timeout = 300;

    public function __construct(public readonly int $documentId) {}

    public function handle(LLMProvider $provider, InputPreparer $preparer, CurrentAccount $currentAccount): void
    {
        $document = Document::withoutGlobalScopes()->with('company')->find($this->documentId);

        if ($document === null || $document->status !== DocumentStatus::Received) {
            return;
        }

        $currentAccount->run($document->account_id, function () use ($document, $provider, $preparer): void {
            try {
                $input = $preparer->prepare(
                    (string) Storage::disk($document->disk)->get($document->path),
                    $document->mime_type,
                    $document->company?->name,
                    $document->company?->tax_id,
                );
                $result = $provider->extract($input);
            } catch (UnsupportedInput $e) {
                $document->transitionTo(DocumentStatus::NeedsReview, $e->getMessage());

                return;
            } catch (ExtractionRefused $e) {
                $document->transitionTo(DocumentStatus::NeedsReview, 'La IA no ha podido procesarlo: '.$e->getMessage());

                return;
            } catch (ExtractionFailed $e) {
                $document->transitionTo(DocumentStatus::Failed, $e->getMessage());

                return;
            }

            DB::transaction(fn () => $this->apply($document, $input, $result));
        });
    }

    private function apply(Document $document, ExtractionInput $input, ExtractionResult $result): void
    {
        $extraction = $document->extractions()->create([
            'account_id' => $document->account_id,
            'model' => $result->model,
            'input_mode' => $input->mode,
            'document_type' => $result->documentType,
            'data' => $result->data,
            'confidence' => array_map(fn ($level) => $level->value, $result->confidence),
            'notes' => $result->notes,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cache_write_tokens' => $result->cacheWriteTokens,
            'cache_read_tokens' => $result->cacheReadTokens,
            'cost_usd' => $result->costUsd,
            'duration_ms' => $result->durationMs,
        ]);

        $document->transitionTo(DocumentStatus::Classified, payload: [
            'document_type' => $result->documentType->value,
            'extraction_id' => $extraction->id,
        ]);

        $type = $result->documentType;

        match (true) {
            in_array($type->value, (array) config('invoices.extraction.ignored_types'), true) => $document->transitionTo(
                DocumentStatus::Ignored,
                "No es una factura: parece {$type->label()}.",
            ),
            in_array($type->value, (array) config('invoices.extraction.review_types'), true) => $document->transitionTo(
                DocumentStatus::NeedsReview,
                'Parece '.$this->withArticle($type).', no una factura.',
            ),
            default => $document->transitionTo(DocumentStatus::Extracted, payload: $this->summary($extraction)),
        };

        if ($document->status === DocumentStatus::Extracted && config('invoices.validation.enabled')) {
            ValidateDocument::dispatch($document->id)->afterCommit();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(DocumentExtraction $extraction): array
    {
        return [
            'extraction_id' => $extraction->id,
            'model' => $extraction->model,
            'cost_usd' => (float) $extraction->cost_usd,
            'doubtful_fields' => $extraction->doubtfulFields(),
        ];
    }

    private function withArticle(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::DeliveryNote => 'un albarán',
            DocumentType::Quote => 'un presupuesto',
            default => $type->label(),
        };
    }

    public function failed(Throwable $exception): void
    {
        $document = Document::withoutGlobalScopes()->find($this->documentId);

        if ($document !== null && $document->status->canTransitionTo(DocumentStatus::Failed)) {
            $document->transitionTo(DocumentStatus::Failed, 'Error técnico al extraer los datos: '.Str::limit($exception->getMessage(), 300));
        }
    }
}
