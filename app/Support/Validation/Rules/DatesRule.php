<?php

namespace App\Support\Validation\Rules;

use App\Support\Validation\Rule;
use App\Support\Validation\RuleFailure;
use App\Support\Validation\ValidationInput;
use Carbon\CarbonImmutable;

/**
 * Fechas lógicas: ni futuras, ni demasiado antiguas, y el vencimiento no
 * puede ser anterior a la emisión.
 */
class DatesRule implements Rule
{
    public function check(ValidationInput $input): array
    {
        $issue = $this->date($input->data['issue_date'] ?? null);

        if ($issue === null) {
            return [new RuleFailure('dates', 'Falta la fecha de emisión.', 'issue_date')];
        }

        $failures = [];
        $maxAge = (int) config('invoices.validation.max_age_days');

        if ($issue->greaterThan($input->today)) {
            $failures[] = new RuleFailure('dates', "La fecha de emisión es futura: {$issue->format('d/m/Y')}.", 'issue_date');
        } elseif ($issue->lessThan($input->today->subDays($maxAge))) {
            $failures[] = new RuleFailure(
                'dates',
                "La fecha de emisión tiene más de {$maxAge} días: {$issue->format('d/m/Y')}.",
                'issue_date',
            );
        }

        $due = $this->date($input->data['due_date'] ?? null);

        if ($due !== null && $due->lessThan($issue)) {
            $failures[] = new RuleFailure(
                'dates',
                "El vencimiento ({$due->format('d/m/Y')}) es anterior a la emisión ({$issue->format('d/m/Y')}).",
                'due_date',
            );
        }

        return $failures;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value, $this->timezone()) ?: null;
    }

    private function timezone(): string
    {
        return (string) config('invoices.validation.timezone');
    }
}
