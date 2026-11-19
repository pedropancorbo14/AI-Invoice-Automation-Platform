<?php

namespace App\Support\Validation;

final readonly class ValidationReport
{
    /**
     * @param  list<RuleFailure>  $failures
     */
    public function __construct(
        public array $failures,
        public ?Direction $direction,
    ) {}

    public function passed(): bool
    {
        return $this->failures === [];
    }

    /**
     * Todos los motivos en un solo texto, para el estado del documento.
     */
    public function reason(): string
    {
        return implode(' ', array_map(fn (RuleFailure $f) => $f->message, $this->failures));
    }

    /**
     * @return list<string>
     */
    public function rules(): array
    {
        return array_values(array_unique(array_map(fn (RuleFailure $f) => $f->rule, $this->failures)));
    }
}
