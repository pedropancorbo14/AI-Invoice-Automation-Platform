<?php

namespace App\Support\Validation\Rules;

use App\Support\Money;
use App\Support\Validation\Rule;
use App\Support\Validation\RuleFailure;
use App\Support\Validation\ValidationInput;

/**
 * Si la factura lleva el QR de VERI*FACTU, el NIF del emisor, el número, la
 * fecha y el total que lee la IA deben ser los del QR: los pone el programa
 * de facturación del emisor, así que una diferencia es un error de lectura
 * (o una factura manipulada) y la mira una persona.
 *
 * Lo que coincide queda confirmado: ConfidenceRule no lo manda a revisión
 * aunque la IA dudara.
 */
class VerifactuRule implements Rule
{
    private const SUBJECTS = [
        'supplier_tax_id' => 'El CIF del proveedor',
        'invoice_number' => 'El número de factura',
        'issue_date' => 'La fecha de emisión',
        'total' => 'El total',
    ];

    public function check(ValidationInput $input): array
    {
        $qr = $input->verifactu;

        if ($qr === null) {
            return [];
        }

        $failures = [];

        if ($qr->testEnvironment) {
            $failures[] = new RuleFailure(
                'verifactu',
                'El QR de VERI*FACTU apunta al entorno de pruebas de la AEAT: una factura real no lo lleva.',
            );
        }

        $data = $input->data;
        $values = [
            'supplier_tax_id' => [$data['supplier']['tax_id'] ?? null, $qr->taxId],
            'invoice_number' => [$data['invoice_number'] ?? null, $qr->number],
            'issue_date' => [self::spanishDate((string) ($data['issue_date'] ?? '')), self::spanishDate($qr->date)],
            'total' => [self::euros($data['total'] ?? null), self::euros($qr->total)],
        ];

        foreach ($qr->compare($data) as $field => $match) {
            if ($match === false) {
                [$read, $expected] = $values[$field];
                $failures[] = new RuleFailure(
                    'verifactu',
                    sprintf('%s no coincide con el QR de VERI*FACTU: la IA leyó %s y el QR dice %s.', self::SUBJECTS[$field], $read, $expected),
                    $field,
                );
            }
        }

        return $failures;
    }

    private static function euros(mixed $amount): string
    {
        $cents = Money::cents(is_scalar($amount) ? (string) $amount : null);

        return $cents === null ? '—' : Money::format($cents).' €';
    }

    private static function spanishDate(string $date): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : $date;
    }
}
