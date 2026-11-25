<?php

namespace App\Support\Tax;

use App\Support\Money;

/**
 * IVA de un lado del resumen (emitidas o recibidas), agrupado por tipo.
 * Importes en céntimos; las rectificativas restan porque vienen en negativo.
 */
class VatBook
{
    public int $base = 0;

    public int $tax = 0;

    public int $surcharge = 0;

    public int $documents = 0;

    /** @var array<int, array{rate: float, base: int, tax: int, surcharge: int}> */
    private array $rates = [];

    /**
     * @param  list<array<string, mixed>>  $lines  El tax_breakdown de una factura.
     */
    public function add(array $lines): void
    {
        $this->documents++;

        foreach ($lines as $line) {
            $rate = (float) ($line['rate'] ?? 0);
            // En centésimas de punto (21 % → 2100): una clave entera y exacta.
            $key = (int) round($rate * 100);
            $base = Money::cents($line['base'] ?? null) ?? 0;
            $tax = Money::cents($line['tax_amount'] ?? null) ?? 0;
            $surcharge = Money::cents($line['surcharge_amount'] ?? null) ?? 0;

            $this->rates[$key] ??= ['rate' => $rate, 'base' => 0, 'tax' => 0, 'surcharge' => 0];
            $this->rates[$key]['base'] += $base;
            $this->rates[$key]['tax'] += $tax;
            $this->rates[$key]['surcharge'] += $surcharge;

            $this->base += $base;
            $this->tax += $tax;
            $this->surcharge += $surcharge;
        }
    }

    /**
     * @return array{documents: int, base: string, tax: string, surcharge: string, rates: list<array{rate: int|float, base: string, tax: string, surcharge: string}>}
     */
    public function toArray(): array
    {
        $rates = $this->rates;
        krsort($rates);

        return [
            'documents' => $this->documents,
            'base' => Money::decimal($this->base),
            'tax' => Money::decimal($this->tax),
            'surcharge' => Money::decimal($this->surcharge),
            'rates' => array_values(array_map(fn (array $r) => [
                // 21 en vez de 21.0 en el JSON; 5.2 se queda como está.
                'rate' => floor($r['rate']) === $r['rate'] ? (int) $r['rate'] : $r['rate'],
                'base' => Money::decimal($r['base']),
                'tax' => Money::decimal($r['tax']),
                'surcharge' => Money::decimal($r['surcharge']),
            ], $rates)),
        ];
    }
}
