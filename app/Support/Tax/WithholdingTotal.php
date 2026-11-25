<?php

namespace App\Support\Tax;

use App\Support\Money;

/**
 * Retenciones de IRPF de un lado del resumen: la base de las facturas que
 * llevan retención y lo retenido, en céntimos.
 */
class WithholdingTotal
{
    private int $base = 0;

    private int $amount = 0;

    private int $documents = 0;

    /**
     * @param  list<array<string, mixed>>  $lines  El tax_breakdown de la factura.
     */
    public function add(array $lines, int $amount): void
    {
        $this->documents++;
        $this->amount += $amount;

        foreach ($lines as $line) {
            $this->base += Money::cents($line['base'] ?? null) ?? 0;
        }
    }

    /**
     * @return array{documents: int, base: string, amount: string}
     */
    public function toArray(): array
    {
        return [
            'documents' => $this->documents,
            'base' => Money::decimal($this->base),
            'amount' => Money::decimal($this->amount),
        ];
    }
}
