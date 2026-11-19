<?php

namespace App\Actions\Review;

use App\Actions\StoreDocument;
use App\Enums\DocumentEventType;
use App\Enums\DocumentStatus;
use App\Jobs\ExtractDocument;
use App\Models\Document;
use App\Models\User;
use App\Support\Inbox\FileType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Adjunta el PDF que faltaba (p. ej. en una factura por enlace) y vuelve a
 * mandar el documento al principio del flujo para que lo lea la IA.
 */
class ReplaceDocumentFile
{
    public function handle(Document $document, User $by, string $contents, string $filename): Document
    {
        if ($document->status !== DocumentStatus::NeedsReview) {
            throw new InvalidArgumentException('Solo se puede adjuntar el fichero a documentos pendientes de revisión.');
        }

        $type = FileType::detect($contents)
            ?? throw new InvalidArgumentException('El fichero no es un PDF ni una imagen (JPG, PNG o HEIC).');

        $sha256 = hash('sha256', $contents);
        $existing = Document::withoutGlobalScopes()
            ->where('account_id', $document->account_id)
            ->where('sha256', $sha256)
            ->whereKeyNot($document->id)
            ->value('id');

        if ($existing !== null) {
            throw new InvalidArgumentException("Ese fichero ya está en el documento #{$existing}.");
        }

        [$disk, $path] = StoreDocument::putFile($document->account()->firstOrFail(), $contents, $filename);

        DB::transaction(function () use ($document, $by, $contents, $filename, $type, $sha256, $disk, $path): void {
            $previous = $document->original_filename;

            $document->forceFill([
                'disk' => $disk,
                'path' => $path,
                'original_filename' => $filename,
                'mime_type' => $type->mimeType(),
                'size' => strlen($contents),
                'sha256' => $sha256,
            ])->save();

            $document->recordEvent(DocumentEventType::FileReplaced, by: $by, payload: [
                'previous_filename' => $previous,
                'filename' => $filename,
            ]);

            $document->transitionTo(DocumentStatus::Received, 'Se adjuntó el fichero de la factura.', $by);

            if (config('invoices.extraction.enabled')) {
                ExtractDocument::dispatch($document->id)->afterCommit();
            }
        });

        return $document->refresh();
    }
}
