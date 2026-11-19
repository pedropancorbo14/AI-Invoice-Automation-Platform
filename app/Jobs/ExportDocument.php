<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentExport;
use App\Support\Export\Exceptions\ExportFailed;
use App\Support\Export\Exceptions\ExportSkipped;
use App\Support\Export\ExportTarget;
use App\Support\Tenancy\CurrentAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Manda una factura validada a los destinos de su empresa (p. ej. Holded):
 *
 *   VALIDATED → EXPORTED       llegó a todos los destinos
 *   VALIDATED → NEEDS_REVIEW   un destino la rechaza (clave caducada, datos...)
 *
 * Sin destinos configurados se queda en VALIDATED (irá en el CSV/Excel del
 * periodo). Nunca exporta dos veces al mismo destino (document_exports).
 */
class ExportDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $documentId) {}

    public function handle(CurrentAccount $currentAccount): void
    {
        $document = Document::withoutGlobalScopes()->with(['company', 'latestExtraction'])->find($this->documentId);

        if ($document === null || $document->status !== DocumentStatus::Validated || $document->company === null || $document->latestExtraction === null) {
            return;
        }

        $targets = array_values(array_filter(
            array_map(fn (string $class) => app($class), (array) config('invoices.export.targets')),
            fn (ExportTarget $target) => $target->enabledFor($document->company),
        ));

        if ($targets === []) {
            return;
        }

        $currentAccount->run($document->account_id, function () use ($document, $targets): void {
            $exported = [];

            foreach ($targets as $target) {
                $record = DocumentExport::query()->firstOrCreate(
                    ['document_id' => $document->id, 'target' => $target->key()],
                    ['account_id' => $document->account_id],
                );

                if ($record->status === DocumentExport::EXPORTED) {
                    $exported[] = $target->key();

                    continue;
                }

                $record->increment('attempts');

                try {
                    $externalId = $target->export($document, $document->latestExtraction, $record);
                } catch (ExportSkipped $e) {
                    $record->update(['status' => DocumentExport::SKIPPED, 'error' => $e->getMessage()]);

                    continue;
                } catch (ExportFailed $e) {
                    $record->update(['status' => DocumentExport::FAILED, 'error' => $e->getMessage()]);
                    $document->sendToReview("No se pudo exportar a {$target->label()}: {$e->getMessage()}", ['export_failed']);

                    return;
                } catch (Throwable $e) {
                    $record->update(['error' => Str::limit($e->getMessage(), 500)]);

                    throw $e;
                }

                $record->update([
                    'status' => DocumentExport::EXPORTED,
                    'external_id' => $externalId,
                    'error' => null,
                    'exported_at' => now(),
                ]);
                $exported[] = $target->key();
            }

            if ($exported !== []) {
                $document->transitionTo(DocumentStatus::Exported, payload: ['targets' => $exported]);
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        $document = Document::withoutGlobalScopes()->find($this->documentId);

        if ($document !== null && $document->status === DocumentStatus::Validated) {
            $document->sendToReview(
                'No se pudo exportar tras varios intentos: '.Str::limit($exception->getMessage(), 300),
                ['export_failed'],
            );
        }
    }
}
