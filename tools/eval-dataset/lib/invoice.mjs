// Cálculo de una factura y de su verdad anotada (formato de docs/evaluacion.md).

import { percentOf, round, toNumber } from './money.mjs';

/**
 * Importe de cada línea, desglose por tipo de IVA, retención y total, todo
 * en céntimos. Si la factura lleva los precios con IVA incluido (tickets),
 * la base se saca del bruto de cada tipo, como hacen los TPV.
 */
export function compute(inv) {
    const lines = inv.lines.map((line) => ({
        ...line,
        amount: line.amount ?? round(line.qty * line.unit * (1 - (line.discount ?? 0) / 100)),
    }));

    const byRate = new Map();
    for (const line of lines) {
        byRate.set(line.rate, (byRate.get(line.rate) ?? 0) + line.amount);
    }

    const breakdown = [...byRate.entries()]
        .sort((a, b) => b[0] - a[0])
        .map(([rate, gross]) => {
            if (inv.pricesIncludeTax) {
                const base = round((gross * 100) / (100 + rate));
                return { rate, base, tax: gross - base, surchargeRate: 0, surcharge: 0, beforeDiscount: gross };
            }

            const discount = inv.globalDiscount ? percentOf(gross, inv.globalDiscount) : 0;
            const base = gross - discount;
            const surchargeRate = inv.surcharge ? (inv.surchargeRates?.[rate] ?? 0) : 0;

            return {
                rate,
                beforeDiscount: gross,
                discount,
                base,
                tax: percentOf(base, rate),
                surchargeRate,
                surcharge: surchargeRate ? percentOf(base, surchargeRate) : 0,
            };
        });

    const base = breakdown.reduce((sum, row) => sum + row.base, 0);
    const tax = breakdown.reduce((sum, row) => sum + row.tax, 0);
    const surcharge = breakdown.reduce((sum, row) => sum + row.surcharge, 0);
    const withholding = inv.withholdingRate ? percentOf(base, inv.withholdingRate) : 0;
    const total = base + tax + surcharge - withholding;

    return {
        lines,
        breakdown,
        gross: lines.reduce((sum, line) => sum + line.amount, 0),
        base,
        tax,
        surcharge,
        withholding,
        total,
        // Lo que se imprime como total: igual al real salvo en los errores del proveedor.
        printedTotal: total + (inv.totalError ?? 0),
    };
}

/** La verdad anotada: lo que pone el documento, no lo que "debería" poner. */
export function expected(inv, calc) {
    if (!['invoice', 'simplified_invoice', 'credit_note'].includes(inv.kind)) {
        return { document_type: inv.kind };
    }

    const withholding = calc.withholding
        ? { rate: inv.withholdingRate, amount: toNumber(calc.withholding) }
        : { amount: null };

    return {
        document_type: inv.kind,
        supplier: { name: inv.supplier.name, tax_id: inv.supplier.taxId },
        customer: { tax_id: inv.customer?.taxId ?? null },
        invoice_number: inv.number,
        issue_date: inv.issueDate,
        due_date: inv.dueDate ?? null,
        tax_breakdown: calc.breakdown.map((row) => ({
            rate: row.rate,
            base: toNumber(row.base),
            tax_amount: toNumber(row.tax),
            ...(row.surchargeRate ? { surcharge_rate: row.surchargeRate, surcharge_amount: toNumber(row.surcharge) } : {}),
        })),
        withholding,
        total: toNumber(calc.printedTotal),
    };
}
