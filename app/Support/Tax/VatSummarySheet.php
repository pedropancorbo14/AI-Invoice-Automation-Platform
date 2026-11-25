<?php

namespace App\Support\Tax;

use App\Support\Money;

/**
 * El resumen de IVA como hoja de cálculo: filas listas para escribir, con
 * los importes como número (float en euros) para que se puedan sumar en
 * Excel, los tipos como texto ("21 %") y un estilo por fila.
 */
class VatSummarySheet
{
    public const NAME = 'Resumen IVA';

    /**
     * @param  array<string, mixed>  $summary  Lo que devuelve VatSummary::build().
     * @return list<array{style: 'title'|'header'|'total'|'normal'|'note', cells: list<string|float|int|null>}>
     */
    public static function rows(string $companyName, array $summary): array
    {
        $rows = [
            self::row('title', ["Resumen de IVA · {$companyName} · {$summary['period']['label']}"]),
            self::row('note', ['Facturas validadas con fecha de emisión en el periodo. Importes en euros.']),
        ];

        foreach (['issued' => 'IVA repercutido (facturas emitidas)', 'received' => 'IVA soportado (facturas recibidas)'] as $side => $title) {
            /** @var array{documents: int, base: string, tax: string, surcharge: string, rates: list<array{rate: int|float, base: string, tax: string, surcharge: string}>} $book */
            $book = $summary[$side];
            $rows[] = self::row('normal', []);
            $rows[] = self::row('header', [$title, 'Base', 'Cuota', 'Recargo']);

            foreach ($book['rates'] as $rate) {
                $rows[] = self::row('normal', [Money::percent($rate['rate']), self::euros($rate['base']), self::euros($rate['tax']), self::euros($rate['surcharge'])]);
            }

            $rows[] = self::row('total', ["Total ({$book['documents']} ".($book['documents'] === 1 ? 'factura' : 'facturas').')', self::euros($book['base']), self::euros($book['tax']), self::euros($book['surcharge'])]);
        }

        $result = $summary['result'];
        $rows[] = self::row('normal', []);
        $rows[] = self::row('total', [
            'Resultado del 303 (estimado)',
            self::euros($result['amount']),
            match ($result['kind']) {
                'to_pay' => 'A ingresar',
                'to_offset' => 'A compensar o devolver',
                default => 'Sin resultado',
            },
        ]);
        $rows[] = self::row('note', ['IVA repercutido + recargo repercutido − IVA soportado. El recargo soportado no se deduce.']);

        $rows[] = self::row('normal', []);
        $rows[] = self::row('header', ['Retenciones de IRPF', 'Base', 'Retenido', 'Facturas']);
        foreach (['received' => 'Practicadas a profesionales (modelo 111)', 'issued' => 'Que te han practicado (se descuentan en el 130)'] as $side => $label) {
            $w = $summary['withholdings'][$side];
            $rows[] = self::row('normal', [$label, self::euros($w['base']), self::euros($w['amount']), $w['documents']]);
        }

        $warnings = array_values(array_filter([
            $summary['warnings']['pending'] > 0
                ? "Hay {$summary['warnings']['pending']} ".($summary['warnings']['pending'] === 1 ? 'factura' : 'facturas').' del periodo sin validar: el resumen no es definitivo.'
                : null,
            $summary['warnings']['other_currency'] > 0
                ? "{$summary['warnings']['other_currency']} ".($summary['warnings']['other_currency'] === 1 ? 'factura en otra moneda no se suma' : 'facturas en otra moneda no se suman').': hay que pasarlas a euros a mano.'
                : null,
            $summary['received']['tickets'] > 0
                ? "Incluye {$summary['received']['tickets']} ".($summary['received']['tickets'] === 1 ? 'ticket' : 'tickets').': su IVA solo se deduce si llevan el NIF de la empresa.'
                : null,
            'Orientativo: no aplica prorrata, regularizaciones, bienes de inversión ni inversión del sujeto pasivo.',
        ]));

        $rows[] = self::row('normal', []);
        foreach ($warnings as $warning) {
            $rows[] = self::row('note', [$warning]);
        }

        return $rows;
    }

    /**
     * @param  'title'|'header'|'total'|'normal'|'note'  $style
     * @param  list<string|float|int|null>  $cells
     * @return array{style: 'title'|'header'|'total'|'normal'|'note', cells: list<string|float|int|null>}
     */
    private static function row(string $style, array $cells): array
    {
        return ['style' => $style, 'cells' => $cells];
    }

    /** "1234.56" → 1234.56: en la hoja va como número, no como texto. */
    private static function euros(string $amount): float
    {
        return round((Money::cents($amount) ?? 0) / 100, 2);
    }
}
