<?php

namespace App\Exceptions;

use App\Enums\DocumentStatus;
use DomainException;

class InvalidDocumentTransition extends DomainException
{
    public static function between(DocumentStatus $from, DocumentStatus $to): self
    {
        return new self(sprintf(
            'Transición no permitida: %s → %s.',
            $from->value,
            $to->value,
        ));
    }
}
