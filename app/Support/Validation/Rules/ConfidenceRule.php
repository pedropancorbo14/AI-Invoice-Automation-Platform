<?php

namespace App\Support\Validation\Rules;

use App\Support\Extraction\Confidence;
use App\Support\Validation\FieldLabels;
use App\Support\Validation\Rule;
use App\Support\Validation\RuleFailure;
use App\Support\Validation\ValidationInput;

/**
 * Si la IA no está segura de un campo clave, lo mira una persona. El umbral
 * se configura por cuenta: "alta" (lo más prudente) o "media".
 */
class ConfidenceRule implements Rule
{
    private const ORDER = ['low' => 0, 'medium' => 1, 'high' => 2];

    public function check(ValidationInput $input): array
    {
        $threshold = self::ORDER[$input->minConfidence->value];
        $failures = [];

        foreach ($input->confidence as $field => $level) {
            if ((self::ORDER[$level] ?? 0) >= $threshold) {
                continue;
            }

            // Un dato opcional que no aparece no es una duda.
            if ($field === 'due_date' && ($input->data['due_date'] ?? null) === null) {
                continue;
            }

            $note = $input->noteFor($field);
            $failures[] = new RuleFailure(
                'confidence',
                sprintf('Dato dudoso en %s%s', FieldLabels::for($field), $note !== null ? ': '.rtrim($note, '.').'.' : '.'),
                $field,
            );
        }

        return $failures;
    }

    public static function levelFrom(string $value): Confidence
    {
        return Confidence::tryFrom($value) ?? Confidence::High;
    }
}
