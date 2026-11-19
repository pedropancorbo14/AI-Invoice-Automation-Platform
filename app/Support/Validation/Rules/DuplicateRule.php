<?php

namespace App\Support\Validation\Rules;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Support\Validation\InvoiceKey;
use App\Support\Validation\Rule;
use App\Support\Validation\RuleFailure;
use App\Support\Validation\ValidationInput;

/**
 * Duplicado de negocio: mismo CIF de proveedor, número y total que otra
 * factura de la cuenta (aunque el fichero sea distinto).
 */
class DuplicateRule implements Rule
{
    public function check(ValidationInput $input): array
    {
        $key = InvoiceKey::for($input->data);

        if ($key === null || $input->accountId === null) {
            return [];
        }

        $original = Document::withoutGlobalScopes()
            ->where('account_id', $input->accountId)
            ->where('invoice_key', $key)
            ->whereNotIn('status', [DocumentStatus::Ignored, DocumentStatus::Failed])
            ->when($input->documentId !== null, fn ($q) => $q->whereKeyNot($input->documentId))
            ->orderBy('id')
            ->first();

        if ($original === null) {
            return [];
        }

        return [new RuleFailure(
            'duplicate',
            "Posible duplicado del documento #{$original->id} (mismo proveedor, número y total).",
            'invoice_number',
        )];
    }
}
