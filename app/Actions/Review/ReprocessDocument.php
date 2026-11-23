<?php

namespace App\Actions\Review;

use App\Enums\DocumentStatus;
use App\Jobs\ExtractDocument;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Vuelve a mandar un documento ignorado o fallido al principio del flujo para
 * que la IA lo lea de nuevo: la IA se equivocó al descartarlo o hubo un error
 * técnico. Es lo mismo que `invoices:extract`, pero desde el panel.
 */
class ReprocessDocument
{
    public function handle(Document $document, User $by): Document
    {
        if (! in_array($document->status, [DocumentStatus::Ignored, DocumentStatus::Failed], true)) {
            throw new InvalidArgumentException('Solo se pueden volver a procesar documentos ignorados o fallidos.');
        }

        if ($document->mime_type === 'message/rfc822') {
            throw new InvalidArgumentException('Este correo no trae la factura: no hay nada que volver a leer.');
        }

        DB::transaction(function () use ($document, $by): void {
            $document->transitionTo(DocumentStatus::Received, "Vuelto a procesar (lo pidió {$by->name}).", $by);

            if (config('invoices.extraction.enabled')) {
                ExtractDocument::dispatch($document->id)->afterCommit();
            }
        });

        return $document->refresh();
    }
}
