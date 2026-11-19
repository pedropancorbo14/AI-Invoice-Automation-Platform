/**
 * Cuentas de la factura en céntimos (las mismas que hace el servidor), para
 * avisar mientras se corrige.
 */

export function cents(value) {
    if (value === null || value === undefined || value === '') return null;
    const number = Number(String(value).replace(',', '.'));

    return Number.isFinite(number) ? Math.round(number * 100) : null;
}

export function formatCents(value) {
    if (value === null) return '—';

    return (value / 100).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2, useGrouping: 'always' });
}

/**
 * ¿Cuadra el total? bases + IVA + recargo − retención = total (±0,02 €).
 */
export function checkTotal(data, toleranceCents = 2) {
    const rows = data.tax_breakdown ?? [];
    const sum = (key) => rows.reduce((acc, row) => acc + (cents(row[key]) ?? 0), 0);

    const bases = sum('base');
    const taxes = sum('tax_amount');
    const surcharges = sum('surcharge_amount');
    const withholding = Math.abs(cents(data.withholding?.amount) ?? 0);
    const computed = bases + taxes + surcharges - withholding;
    const total = cents(data.total);

    return {
        bases,
        taxes,
        surcharges,
        withholding,
        computed,
        total,
        ok: total !== null && rows.length > 0 && Math.abs(computed - total) <= toleranceCents,
    };
}

/**
 * Cuota esperada de una línea de IVA (para sugerirla al corregir la base).
 */
export function expectedTax(row) {
    const base = cents(row.base);
    const rate = Number(row.rate);

    return base === null || !Number.isFinite(rate) ? null : Math.round((base * rate) / 100);
}

/**
 * Prepara los datos del formulario para la API: importes como texto con 2 decimales.
 */
export function toPayload(data) {
    const amount = (value) => {
        const c = cents(value);

        return c === null ? null : (c / 100).toFixed(2);
    };

    return {
        ...data,
        total: amount(data.total),
        withholding: { rate: data.withholding?.rate ?? null, amount: amount(data.withholding?.amount) },
        tax_breakdown: (data.tax_breakdown ?? []).map((row) => ({
            rate: row.rate === '' || row.rate === null ? null : Number(row.rate),
            base: amount(row.base),
            tax_amount: amount(row.tax_amount),
            surcharge_rate: row.surcharge_rate === '' || row.surcharge_rate === null ? null : Number(row.surcharge_rate),
            surcharge_amount: amount(row.surcharge_amount),
        })),
    };
}
