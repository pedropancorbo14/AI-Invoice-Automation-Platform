<?php

namespace App\Support\Validation;

/**
 * Una regla que no se cumple, con un motivo que entiende una persona.
 */
final readonly class RuleFailure
{
    public function __construct(
        public string $rule,
        public string $message,
        public ?string $field = null,
    ) {}

    /**
     * @return array{rule: string, message: string, field: string|null}
     */
    public function toArray(): array
    {
        return ['rule' => $this->rule, 'message' => $this->message, 'field' => $this->field];
    }
}
