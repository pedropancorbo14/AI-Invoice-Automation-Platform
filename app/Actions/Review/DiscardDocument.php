<?php

namespace App\Actions\Review;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use InvalidArgumentException;

/**
 * Descarta un documento en revisión: no es una factura o está repetido.
 */
class DiscardDocument
{
    public function notInvoice(Document $document, User $by): Document
    {
        $this->ensureReviewable($document);
        $document->transitionTo(DocumentStatus::Ignored, "No es una factura (lo indicó {$by->name}).", $by);

        return $document->refresh();
    }

    public function duplicateOf(Document $document, User $by, ?Document $original = null): Document
    {
        $this->ensureReviewable($document);

        if ($original !== null && ($original->is($document) || $original->account_id !== $document->account_id)) {
            throw new InvalidArgumentException('El documento original no es válido.');
        }

        $document->transitionTo(
            DocumentStatus::Ignored,
            $original !== null ? "Duplicado del documento #{$original->id}." : 'Duplicado de otro documento.',
            $by,
            ['duplicate_of' => $original?->id],
        );

        return $document->refresh();
    }

    private function ensureReviewable(Document $document): void
    {
        if ($document->status !== DocumentStatus::NeedsReview) {
            throw new InvalidArgumentException('Solo se pueden descartar documentos pendientes de revisión.');
        }
    }
}
