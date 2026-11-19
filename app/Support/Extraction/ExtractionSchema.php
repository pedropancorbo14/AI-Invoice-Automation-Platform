<?php

namespace App\Support\Extraction;

use App\Support\Extraction\Exceptions\ExtractionFailed;
use DateTimeImmutable;

/**
 * Esquema de salida estructurada y su normalización.
 *
 * Los importes se devuelven como texto con 2 decimales ("121.00") para no
 * arrastrar errores de coma flotante a las reglas de la fase 4.
 */
class ExtractionSchema
{
    /** Campos con confianza propia: los que deciden si una factura pasa sola. */
    public const CONFIDENCE_FIELDS = [
        'document_type', 'supplier_name', 'supplier_tax_id', 'customer_tax_id',
        'invoice_number', 'issue_date', 'due_date', 'tax_breakdown', 'withholding', 'total',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        $nullableString = ['anyOf' => [['type' => 'string'], ['type' => 'null']]];
        $nullableNumber = ['anyOf' => [['type' => 'number'], ['type' => 'null']]];
        $nullableDate = ['anyOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'null']]];
        $party = self::object([
            'name' => $nullableString,
            'tax_id' => $nullableString,
        ]);

        return self::object([
            'document_type' => ['type' => 'string', 'enum' => array_column(DocumentType::cases(), 'value')],
            'supplier' => $party,
            'customer' => $party,
            'invoice_number' => $nullableString,
            'issue_date' => $nullableDate,
            'due_date' => $nullableDate,
            'currency' => $nullableString,
            'tax_breakdown' => [
                'type' => 'array',
                'items' => self::object([
                    'rate' => ['type' => 'number'],
                    'base' => ['type' => 'number'],
                    'tax_amount' => ['type' => 'number'],
                    'surcharge_rate' => $nullableNumber,
                    'surcharge_amount' => $nullableNumber,
                ]),
            ],
            'withholding' => self::object([
                'rate' => $nullableNumber,
                'amount' => $nullableNumber,
            ]),
            'total' => $nullableNumber,
            'lines' => [
                'type' => 'array',
                'items' => self::object([
                    'description' => ['type' => 'string'],
                    'quantity' => $nullableNumber,
                    'unit_price' => $nullableNumber,
                    'amount' => $nullableNumber,
                    'tax_rate' => $nullableNumber,
                ]),
            ],
            'confidence' => self::object(array_fill_keys(
                self::CONFIDENCE_FIELDS,
                ['type' => 'string', 'enum' => array_column(Confidence::cases(), 'value')],
            )),
            'notes' => [
                'type' => 'array',
                'items' => self::object([
                    'field' => ['type' => 'string'],
                    'note' => ['type' => 'string'],
                ]),
            ],
        ]);
    }

    /**
     * Instrucciones fijas (se cachean). Lo que cambia por documento va en el mensaje.
     */
    public static function systemPrompt(): string
    {
        return <<<'PROMPT'
            Extraes los datos de facturas para una gestoría española. Tus datos los
            comprueban después reglas automáticas (aritmética, CIF, tipos de IVA) y,
            si algo no cuadra, una persona. Por eso es más útil un campo vacío o con
            confianza baja que un dato inventado.

            Tipos de documento:
            - invoice: factura completa.
            - simplified_invoice: ticket o factura simplificada.
            - credit_note: factura rectificativa o abono (sus importes van en negativo).
            - delivery_note: albarán.
            - quote: presupuesto o proforma.
            - other: cualquier otra cosa (contratos, nóminas, publicidad, extractos...).

            Cómo rellenar los campos:
            - supplier es quien emite el documento; customer, a quien va dirigido.
            - tax_id: el CIF, NIF, NIE o NIF-IVA tal como aparece.
            - Fechas en formato AAAA-MM-DD. Si el día y el mes son ambiguos, usa el
              formato español (día/mes/año) y dilo en notes.
            - Importes como números con punto decimal: "1.234,56 €" es 1234.56.
            - tax_breakdown: una entrada por tipo de IVA (o IGIC), con su base y su
              cuota. Si hay recargo de equivalencia, va en surcharge_rate y
              surcharge_amount de esa misma entrada. Una factura exenta lleva rate 0.
            - withholding: la retención de IRPF, con el importe en positivo.
            - total: el importe final a pagar, tal como aparece en el documento.
            - lines: las líneas de detalle si se leen bien; si no, una lista vacía.
            - currency: código ISO 4217 (EUR, USD...).
            - Si un dato no aparece o no se lee, déjalo en null.

            Confianza (confidence) por campo:
            - high: se lee con claridad y no hay otra interpretación posible.
            - medium: se lee, pero hay alguna duda (borroso, dos candidatos...).
            - low: es una deducción o casi no se lee.
            Cada vez que un campo no sea high, explica el motivo en notes, en español
            y en una frase.
            PROMPT;
    }

    /**
     * Texto del mensaje que acompaña al documento.
     */
    public static function userPrompt(ExtractionInput $input): string
    {
        $context = $input->companyTaxId !== null
            ? sprintf('El documento se ha recibido en la carpeta de %s (CIF %s).', $input->companyName ?? 'la empresa', $input->companyTaxId)
            : 'No se sabe todavía de qué empresa es el documento.';

        return $context.' Extrae sus datos.';
    }

    /**
     * Valida y normaliza la respuesta del modelo.
     *
     * @param  array<string, mixed>  $raw
     * @return array{type: DocumentType, data: array<string, mixed>, confidence: array<string, Confidence>, notes: list<array{field: string, note: string}>}
     */
    public static function normalize(array $raw): array
    {
        $type = DocumentType::tryFrom((string) ($raw['document_type'] ?? ''))
            ?? throw new ExtractionFailed('La respuesta del modelo no trae un tipo de documento válido.');

        $confidence = [];
        foreach (self::CONFIDENCE_FIELDS as $field) {
            $confidence[$field] = Confidence::tryFrom((string) ($raw['confidence'][$field] ?? '')) ?? Confidence::Low;
        }

        $notes = [];
        foreach ((array) ($raw['notes'] ?? []) as $note) {
            if (is_array($note) && isset($note['field'], $note['note'])) {
                $notes[] = ['field' => (string) $note['field'], 'note' => (string) $note['note']];
            }
        }

        $data = [
            'document_type' => $type->value,
            'supplier' => self::party($raw['supplier'] ?? null),
            'customer' => self::party($raw['customer'] ?? null),
            'invoice_number' => self::text($raw['invoice_number'] ?? null),
            'issue_date' => self::date($raw['issue_date'] ?? null, 'issue_date', $notes, $confidence),
            'due_date' => self::date($raw['due_date'] ?? null, 'due_date', $notes, $confidence),
            'currency' => strtoupper((string) (self::text($raw['currency'] ?? null) ?? 'EUR')),
            'tax_breakdown' => array_values(array_map(fn ($row) => [
                'rate' => self::amount($row['rate'] ?? null),
                'base' => self::amount($row['base'] ?? null),
                'tax_amount' => self::amount($row['tax_amount'] ?? null),
                'surcharge_rate' => self::amount($row['surcharge_rate'] ?? null),
                'surcharge_amount' => self::amount($row['surcharge_amount'] ?? null),
            ], array_filter((array) ($raw['tax_breakdown'] ?? []), 'is_array'))),
            'withholding' => [
                'rate' => self::amount($raw['withholding']['rate'] ?? null),
                'amount' => self::amount($raw['withholding']['amount'] ?? null),
            ],
            'total' => self::amount($raw['total'] ?? null),
            'lines' => array_values(array_map(fn ($row) => [
                'description' => (string) ($row['description'] ?? ''),
                'quantity' => self::amount($row['quantity'] ?? null),
                'unit_price' => self::amount($row['unit_price'] ?? null),
                'amount' => self::amount($row['amount'] ?? null),
                'tax_rate' => self::amount($row['tax_rate'] ?? null),
            ], array_filter((array) ($raw['lines'] ?? []), 'is_array'))),
        ];

        return ['type' => $type, 'data' => $data, 'confidence' => $confidence, 'notes' => $notes];
    }

    /**
     * Importe como texto con 2 decimales, o null.
     */
    public static function amount(mixed $value): ?string
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        return number_format(round((float) $value, 2), 2, '.', '');
    }

    /**
     * @return array{name: string|null, tax_id: string|null}
     */
    private static function party(mixed $party): array
    {
        $party = is_array($party) ? $party : [];

        return [
            'name' => self::text($party['name'] ?? null),
            'tax_id' => TaxId::normalize(self::text($party['tax_id'] ?? null)),
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Una fecha que no es AAAA-MM-DD real se descarta, se anota y baja a confianza "low".
     *
     * @param  list<array{field: string, note: string}>  $notes
     * @param  array<string, Confidence>  $confidence
     */
    private static function date(mixed $value, string $field, array &$notes, array &$confidence): ?string
    {
        $value = self::text($value);

        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            $notes[] = ['field' => $field, 'note' => "Fecha no válida devuelta por el modelo: {$value}."];
            $confidence[$field] = Confidence::Low;

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }
}
