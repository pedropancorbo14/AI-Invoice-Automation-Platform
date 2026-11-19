<?php

namespace App\Console\Commands;

use App\Support\Evaluation\EvalCase;
use App\Support\Evaluation\FieldComparator;
use App\Support\Extraction\InputPreparer;
use App\Support\Extraction\LLMProvider;
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
#[Description('Mide la precisión por campo, el coste y el tiempo de la extracción con IA')]
class EvaluateExtraction extends Command
{
    private const TARGET = 95.0;

    public function handle(InputPreparer $preparer, FieldComparator $comparator): int
    {
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

        $this->withProgressBar($cases, function (EvalCase $case) use ($provider, $preparer, $comparator, &$results) {
            $results[] = $this->evaluate($case, $provider, $preparer, $comparator);
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
    private function evaluate(EvalCase $case, LLMProvider $provider, InputPreparer $preparer, FieldComparator $comparator): array
    {
        try {
            $input = $preparer->prepare((string) File::get($case->path), $case->mimeType, $case->companyName, $case->companyTaxId);
            $result = $provider->extract($input);
        } catch (Throwable $e) {
            return ['case' => $case->name, 'tags' => $case->tags, 'error' => class_basename($e).': '.Str::limit($e->getMessage(), 200)];
        }

        return [
            'case' => $case->name,
            'tags' => $case->tags,
            'mode' => $input->mode->value,
            'fields' => $comparator->compare($case->expected, $result->data),
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

        return [
            'model' => $model,
            'effort' => $effort,
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
            'passed' => $keyBelowTarget === [] && count($ok) === count($results),
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

        $report['passed']
            ? $this->info('✓ Los campos clave superan el 95 %.')
            : $this->error('✗ Por debajo del objetivo: '.implode(', ', $report['key_fields_below_target'] ?: ['hay errores técnicos']).'.');
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
