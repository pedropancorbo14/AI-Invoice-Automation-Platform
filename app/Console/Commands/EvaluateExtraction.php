<?php

namespace App\Console\Commands;

use App\Support\Evaluation\EvalCase;
use App\Support\Evaluation\FieldComparator;
use App\Support\Extraction\Confidence;
use App\Support\Extraction\ExtractionResult;
use App\Support\Extraction\InputPreparer;
use App\Support\Extraction\LLMProvider;
use App\Support\Extraction\TaxId;
use App\Support\Validation\RuleFailure;
use App\Support\Validation\ValidationInput;
use App\Support\Validation\Validator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

#[Signature('invoices:eval
    {--dataset= : Carpeta con las facturas y sus .json (por defecto storage/app/eval/dataset)}
    {--model= : Modelo a evaluar (por defecto EXTRACTION_MODEL)}
    {--effort= : Esfuerzo: low, medium, high, xhigh o max}
    {--limit= : Evaluar solo las N primeras}
    {--only=* : Evaluar solo estos casos (nombre sin extensión)}
    {--yes : No pedir confirmación (cada factura es una llamada de pago a la API)}')]
#[Description('Mide la precisión por campo, el coste y el tiempo de la extracción con IA, y qué harían las reglas')]
class EvaluateExtraction extends Command
{
    private const TARGET = 95.0;

    public function handle(InputPreparer $preparer, FieldComparator $comparator, Validator $validator): int
    {
        // El dataset puede tener facturas antiguas: aquí no queremos que la
        // regla de antigüedad las mande todas a revisión.
        config(['invoices.validation.max_age_days' => 36500]);

        if ($this->option('model')) {
            config(['invoices.extraction.model' => $this->option('model')]);
        }
        if ($this->option('effort')) {
            config(['invoices.extraction.effort' => $this->option('effort')]);
        }

        $model = (string) config('invoices.extraction.model');
        $effort = (string) config('invoices.extraction.effort');

        try {
            $cases = $this->cases();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        if ($cases === []) {
            $this->error('El dataset está vacío: hace falta cada factura con su .json al lado (ver docs/evaluacion.md).');

            return self::INVALID;
        }

        $this->info(sprintf('%d facturas · modelo %s · esfuerzo %s', count($cases), $model, $effort));

        if (! $this->option('yes') && ! $this->confirm('Cada factura es una llamada de pago a la API. ¿Continuar?', true)) {
            return self::SUCCESS;
        }

        $provider = app(LLMProvider::class);
        $results = [];

        $this->withProgressBar($cases, function (EvalCase $case) use ($provider, $preparer, $comparator, $validator, &$results) {
            $results[] = $this->evaluate($case, $provider, $preparer, $comparator, $validator);
        });
        $this->newLine(2);

        $report = $this->report($results, $model, $effort);
        $this->print($report, $results, $comparator);

        $path = $this->save($report, $results, $model, $effort);
        $this->line("Informe completo: {$path}");

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<EvalCase>
     */
    private function cases(): array
    {
        $cases = EvalCase::load((string) ($this->option('dataset') ?: storage_path('app/eval/dataset')));

        $only = (array) $this->option('only');
        if ($only !== []) {
            $cases = array_values(array_filter($cases, fn (EvalCase $c) => in_array($c->name, $only, true)));
        }

        if ($this->option('limit')) {
            $cases = array_slice($cases, 0, (int) $this->option('limit'));
        }

        return $cases;
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluate(EvalCase $case, LLMProvider $provider, InputPreparer $preparer, FieldComparator $comparator, Validator $validator): array
    {
        try {
            $input = $preparer->prepare((string) File::get($case->path), $case->mimeType, $case->companyName, $case->companyTaxId);
            $result = $provider->extract($input);
        } catch (Throwable $e) {
            return ['case' => $case->name, 'tags' => $case->tags, 'error' => class_basename($e).': '.Str::limit($e->getMessage(), 200)];
        }

        $fields = $comparator->compare($case->expected, $result->data);

        return [
            'case' => $case->name,
            'tags' => $case->tags,
            'mode' => $input->mode->value,
            'fields' => $fields,
            'validation' => $this->validation($case, $result, $fields, $validator),
            'expected' => $case->expected,
            'actual' => $result->data,
            'doubtful' => $result->lowConfidenceFields(),
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cost_usd' => $result->costUsd,
            'duration_ms' => $result->durationMs,
        ];
    }

    /**
     * Qué harían las reglas de la fase 4 con esta extracción. Solo las reglas
     * sin estado: aquí no hay cuenta (duplicados ni proveedores conocidos).
     *
     * @param  array<string, bool>  $fields
     * @return array{passed: bool, key_correct: bool, rules: list<string>, reason: string}|null
     */
    private function validation(EvalCase $case, ExtractionResult $result, array $fields, Validator $validator): ?array
    {
        $type = $result->documentType->value;
        $decidedByType = in_array($type, [...(array) config('invoices.extraction.ignored_types'), ...(array) config('invoices.extraction.review_types')], true);

        if ($decidedByType) {
            return null;
        }

        $report = $validator->validate(new ValidationInput(
            documentType: $result->documentType,
            data: $result->data,
            confidence: array_map(fn (Confidence $c) => $c->value, $result->confidence),
            notes: $result->notes,
            companyTaxId: TaxId::normalize($case->companyTaxId),
            companyName: $case->companyName,
            minConfidence: Confidence::High,
            today: CarbonImmutable::now((string) config('invoices.validation.timezone'))->startOfDay(),
        ));

        // Sin empresa en el caso no se puede saber si es recibida o emitida: esa regla no cuenta.
        $failures = array_values(array_filter(
            $report->failures,
            fn (RuleFailure $f) => $case->companyTaxId !== null || $f->rule !== 'direction',
        ));

        return [
            'passed' => $failures === [],
            'key_correct' => ! in_array(false, array_intersect_key($fields, array_flip(FieldComparator::KEY_FIELDS)), true),
            'rules' => array_values(array_unique(array_map(fn (RuleFailure $f) => $f->rule, $failures))),
            'reason' => implode(' ', array_map(fn (RuleFailure $f) => $f->message, $failures)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function report(array $results, string $model, string $effort): array
    {
        $ok = array_values(array_filter($results, fn ($r) => ! isset($r['error'])));

        $fields = [];
        foreach (FieldComparator::FIELDS as $field) {
            $scored = array_values(array_filter(array_map(fn ($r) => $r['fields'][$field] ?? null, $ok), fn ($v) => $v !== null));

            if ($scored !== []) {
                $correct = count(array_filter($scored));
                $fields[$field] = [
                    'correct' => $correct,
                    'total' => count($scored),
                    'accuracy' => round($correct / count($scored) * 100, 1),
                    'key' => in_array($field, FieldComparator::KEY_FIELDS, true),
                ];
            }
        }

        $keyBelowTarget = array_keys(array_filter($fields, fn ($f) => $f['key'] && $f['accuracy'] < self::TARGET));
        $allKeyCorrect = count(array_filter($ok, fn ($r) => ! in_array(false, array_intersect_key($r['fields'], array_flip(FieldComparator::KEY_FIELDS)), true)));
        $durations = array_column($ok, 'duration_ms');
        sort($durations);

        $validated = array_values(array_filter(array_column($ok, 'validation')));
        $auto = array_filter($validated, fn ($v) => $v['passed']);
        $falseAccepts = array_values(array_filter($ok, fn ($r) => ($r['validation']['passed'] ?? false) && ! $r['validation']['key_correct']));
        $ruleCounts = array_count_values(array_merge([], ...array_column($validated, 'rules')));
        arsort($ruleCounts);

        return [
            'model' => $model,
            'effort' => $effort,
            'validation' => [
                'cases' => count($validated),
                'auto_rate' => $validated === [] ? 0.0 : round(count($auto) / count($validated) * 100, 1),
                'false_accepts' => array_column($falseAccepts, 'case'),
                'caught_errors' => count(array_filter($validated, fn ($v) => ! $v['passed'] && ! $v['key_correct'])),
                'unnecessary_reviews' => count(array_filter($validated, fn ($v) => ! $v['passed'] && $v['key_correct'])),
                'rules' => $ruleCounts,
            ],
            'cases' => count($results),
            'errors' => count($results) - count($ok),
            'fields' => $fields,
            'all_key_fields_correct' => $ok === [] ? 0.0 : round($allKeyCorrect / count($ok) * 100, 1),
            'avg_cost_usd' => $ok === [] ? 0.0 : round(array_sum(array_column($ok, 'cost_usd')) / count($ok), 5),
            'total_cost_usd' => round(array_sum(array_column($ok, 'cost_usd')), 4),
            'avg_duration_ms' => $ok === [] ? 0 : (int) round(array_sum($durations) / count($durations)),
            'p95_duration_ms' => $durations === [] ? 0 : $durations[(int) max(0, ceil(count($durations) * 0.95) - 1)],
            'avg_input_tokens' => $ok === [] ? 0 : (int) round(array_sum(array_column($ok, 'input_tokens')) / count($ok)),
            'avg_output_tokens' => $ok === [] ? 0 : (int) round(array_sum(array_column($ok, 'output_tokens')) / count($ok)),
            'modes' => array_count_values(array_column($ok, 'mode')),
            'key_fields_below_target' => $keyBelowTarget,
            // Una factura con datos mal que pasaría sola es lo peor que puede pasar.
            'passed' => $keyBelowTarget === [] && count($ok) === count($results) && $falseAccepts === [],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $results
     */
    private function print(array $report, array $results, FieldComparator $comparator): void
    {
        $this->table(['Campo', 'Aciertos', '%', 'Clave'], array_map(fn ($field, $f) => [
            $field,
            "{$f['correct']}/{$f['total']}",
            number_format($f['accuracy'], 1, ',', '').' %',
            $f['key'] ? ($f['accuracy'] >= self::TARGET ? '✓' : '✗ < 95 %') : '',
        ], array_keys($report['fields']), $report['fields']));

        $this->table(['Métrica', 'Valor'], [
            ['Facturas con todos los campos clave bien', number_format($report['all_key_fields_correct'], 1, ',', '').' %'],
            ['Coste medio por factura', '$'.number_format($report['avg_cost_usd'], 4)],
            ['Coste total de esta evaluación', '$'.number_format($report['total_cost_usd'], 4)],
            ['Tiempo medio / p95', sprintf('%.1f s / %.1f s', $report['avg_duration_ms'] / 1000, $report['p95_duration_ms'] / 1000)],
            ['Tokens medios (entrada / salida)', "{$report['avg_input_tokens']} / {$report['avg_output_tokens']}"],
            ['Entrada usada', implode(', ', array_map(fn ($mode, $n) => "{$mode}: {$n}", array_keys($report['modes']), $report['modes']))],
            ['Errores', (string) $report['errors']],
        ]);

        $v = $report['validation'];
        $this->table(['Reglas (fase 4)', 'Valor'], [
            ['Facturas que pasarían solas', number_format($v['auto_rate'], 1, ',', '')." % de {$v['cases']} (objetivo ≥ 70 %)"],
            ['Falsos aprobados (datos mal que pasarían)', $v['false_accepts'] === [] ? '0' : count($v['false_accepts']).': '.implode(', ', $v['false_accepts'])],
            ['Errores detectados (a revisión)', (string) $v['caught_errors']],
            ['Revisiones de más (datos bien)', (string) $v['unnecessary_reviews']],
            ['Reglas que más saltan', implode(', ', array_map(fn ($rule, $n) => "{$rule}: {$n}", array_keys($v['rules']), $v['rules'])) ?: '—'],
        ]);

        $failures = [];
        foreach ($results as $r) {
            if (isset($r['error'])) {
                $failures[] = [$r['case'], '—', 'error', $r['error']];

                continue;
            }
            foreach ($r['fields'] as $field => $correct) {
                if (! $correct) {
                    $failures[] = [
                        $r['case'], $field,
                        json_encode($comparator->value($field, $r['expected']), JSON_UNESCAPED_UNICODE),
                        json_encode($comparator->value($field, $r['actual']), JSON_UNESCAPED_UNICODE)
                            .(in_array(str_replace(['base', 'tax'], 'tax_breakdown', $field), $r['doubtful'], true) ? ' (marcado dudoso)' : ''),
                    ];
                }
            }
        }

        if ($failures !== []) {
            $this->warn('Fallos (para mejorar el prompt):');
            $this->table(['Caso', 'Campo', 'Esperado', 'Obtenido'], array_slice($failures, 0, 40));
        }

        $report['key_fields_below_target'] === [] && $report['errors'] === 0
            ? $this->info('✓ Los campos clave superan el 95 %.')
            : $this->error('✗ Por debajo del objetivo: '.implode(', ', $report['key_fields_below_target'] ?: ['hay errores técnicos']).'.');

        $v['false_accepts'] === []
            ? $this->info('✓ Ninguna factura con datos mal pasaría sola.')
            : $this->error('✗ Hay facturas con datos mal que pasarían sin revisión: '.implode(', ', $v['false_accepts']).'.');

        if ($v['cases'] > 0) {
            $v['auto_rate'] >= 70
                ? $this->info('✓ Al menos el 70 % pasaría sin intervención.')
                : $this->warn('· Menos del 70 % pasaría sin intervención (mira qué reglas saltan).');
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $results
     */
    private function save(array $report, array $results, string $model, string $effort): string
    {
        $dir = storage_path('app/eval/reports');
        File::ensureDirectoryExists($dir);

        $path = sprintf('%s/%s-%s-%s.json', $dir, now()->format('Ymd-His'), Str::slug($model), $effort);
        File::put($path, (string) json_encode(['summary' => $report, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $path;
    }
}
