<?php

namespace App\Support\Evaluation;

use App\Support\Extraction\ExtractionSchema;
use App\Support\Extraction\TaxId;
use Illuminate\Support\Str;

/**
 * Compara lo extraído con la verdad anotada, campo a campo y con las
 * mismas tolerancias que usarán las reglas (±0,01 € en importes).
 */
class FieldComparator
{
    /** Campos clave del plan: deben superar el 95 %. */
    public const KEY_FIELDS = ['supplier_tax_id', 'issue_date', 'base', 'tax', 'total'];

    public const FIELDS = [
        'document_type', 'supplier_name', 'supplier_tax_id', 'customer_tax_id', 'invoice_number',
        'issue_date', 'due_date', 'base', 'tax', 'tax_breakdown', 'withholding', 'total',
    ];

    /**
     * Resultado por campo: true (acierto), false (fallo) o se omite si la
     * verdad anotada no incluye ese campo.
     *
     * @param  array<string, mixed>  $expected  Verdad anotada.
     * @param  array<string, mixed>  $actual  Datos normalizados de la extracción.
     * @return array<string, bool>
     */
    public function compare(array $expected, array $actual): array
    {
        $results = [];

        foreach (self::FIELDS as $field) {
            $result = $this->field($field, $expected, $actual);

            if ($result !== null) {
                $results[$field] = $result;
            }
        }

        return $results;
    }

    /**
     * Valor de un campo en una forma legible (para listar los fallos).
     *
     * @param  array<string, mixed>  $data
     */
    public function value(string $field, array $data): mixed
    {
        return match ($field) {
            'supplier_name' => $data['supplier']['name'] ?? null,
            'supplier_tax_id' => $data['supplier']['tax_id'] ?? null,
            'customer_tax_id' => $data['customer']['tax_id'] ?? null,
            'base' => $this->sum($data['tax_breakdown'] ?? null, 'base'),
            'tax' => $this->sum($data['tax_breakdown'] ?? null, 'tax_amount'),
            'withholding' => ExtractionSchema::amount($data['withholding']['amount'] ?? null),
            'total' => ExtractionSchema::amount($data['total'] ?? null),
            default => $data[$field] ?? null,
        };
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function field(string $field, array $expected, array $actual): ?bool
    {
        return match ($field) {
            'supplier_name' => $this->has($expected, 'supplier.name')
                ? $this->sameName($this->value($field, $expected), $this->value($field, $actual)) : null,
            'supplier_tax_id', 'customer_tax_id' => $this->has($expected, str_replace('_tax_id', '', $field).'.tax_id')
                ? TaxId::normalize($this->value($field, $expected)) === TaxId::normalize($this->value($field, $actual)) : null,
            'invoice_number' => array_key_exists('invoice_number', $expected)
                ? $this->code($expected['invoice_number']) === $this->code($actual['invoice_number'] ?? null) : null,
            'document_type', 'issue_date', 'due_date' => array_key_exists($field, $expected)
                ? ($expected[$field] ?? null) === ($actual[$field] ?? null) : null,
            'base', 'tax', 'total' => $this->has($expected, $field === 'total' ? 'total' : 'tax_breakdown')
                ? $this->sameAmount($this->value($field, $expected), $this->value($field, $actual)) : null,
            'withholding' => array_key_exists('withholding', $expected)
                ? $this->sameAmount($this->value($field, $expected) ?? '0.00', $this->value($field, $actual) ?? '0.00') : null,
            'tax_breakdown' => array_key_exists('tax_breakdown', $expected)
                ? $this->breakdown($expected['tax_breakdown']) === $this->breakdown($actual['tax_breakdown'] ?? []) : null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function has(array $data, string $path): bool
    {
        return Str::contains($path, '.')
            ? is_array($data[Str::before($path, '.')] ?? null) && array_key_exists(Str::after($path, '.'), $data[Str::before($path, '.')])
            : array_key_exists($path, $data);
    }

    private function sameAmount(mixed $a, mixed $b): bool
    {
        $a = ExtractionSchema::amount($a);
        $b = ExtractionSchema::amount($b);

        if ($a === null || $b === null) {
            return $a === $b;
        }

        return abs((int) round((float) $a * 100) - (int) round((float) $b * 100)) <= 1;
    }

    private function sum(mixed $rows, string $key): ?string
    {
        if (! is_array($rows)) {
            return null;
        }

        $cents = 0;
        foreach ($rows as $row) {
            $cents += (int) round((float) ($row[$key] ?? 0) * 100);
        }

        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Desglose comparable: "21.00|100.00|21.00", ordenado por tipo.
     *
     * @return list<string>
     */
    private function breakdown(mixed $rows): array
    {
        $lines = array_map(fn ($row) => implode('|', [
            ExtractionSchema::amount($row['rate'] ?? null),
            ExtractionSchema::amount($row['base'] ?? null),
            ExtractionSchema::amount($row['tax_amount'] ?? null),
        ]), is_array($rows) ? array_values($rows) : []);
        sort($lines);

        return $lines;
    }

    private function code(mixed $value): ?string
    {
        $code = Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $value));

        return $code === '' ? null : $code;
    }

    /**
     * Nombres: sin tildes, mayúsculas, signos ni forma jurídica (S.L., S.A...).
     */
    private function sameName(mixed $a, mixed $b): bool
    {
        $normalize = function (mixed $name): string {
            $name = Str::upper(Str::ascii((string) $name));
            $name = (string) preg_replace('/[^A-Z0-9 ]/', ' ', $name);
            $name = (string) preg_replace('/\b(S ?L ?U?|S ?A ?U?|S ?L ?L|S ?C ?P?|C ?B|SOCIEDAD LIMITADA|SOCIEDAD ANONIMA)\s*$/', '', trim($name));

            return trim((string) preg_replace('/\s+/', ' ', $name));
        };

        $a = $normalize($a);
        $b = $normalize($b);

        if ($a === '' || $b === '') {
            return $a === $b;
        }

        similar_text($a, $b, $percent);

        return $a === $b || $percent >= 85;
    }
}
