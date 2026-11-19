<?php

namespace App\Support\Review;

use App\Support\Validation\RuleFailure;
use RuntimeException;

/**
 * Al aprobar, las reglas siguen sin cuadrar: se avisa a la persona y solo se
 * aprueba si confirma (force).
 */
class NeedsConfirmation extends RuntimeException
{
    /**
     * @param  list<RuleFailure>  $warnings
     */
    public function __construct(public readonly array $warnings)
    {
        parent::__construct('Las reglas siguen sin cuadrar. Revisa los avisos o confirma para aprobar igualmente.');
    }
}
