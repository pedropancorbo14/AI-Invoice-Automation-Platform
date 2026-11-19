<?php

namespace App\Support\Validation;

interface Rule
{
    /**
     * @return list<RuleFailure> Vacía si la regla se cumple.
     */
    public function check(ValidationInput $input): array;
}
