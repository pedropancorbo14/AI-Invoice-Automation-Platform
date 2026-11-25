/**
 * El QR de VERI*FACTU en la revisión: qué dice, en qué no coincide con el
 * formulario y cómo copiar sus datos. Compara igual que el servidor
 * (VerifactuQr::compare), con los importes en céntimos.
 */

import { cents } from './invoice.js';

const taxId = (value) => {
    const clean = String(value ?? '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();

    return clean.length === 11 && clean.startsWith('ES') ? clean.slice(2) : clean;
};
const alnum = (value) => String(value ?? '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();

export const QR_FIELDS = [
    { field: 'supplier_tax_id', label: 'CIF del proveedor', read: (f) => f.supplier?.tax_id, same: (a, b) => taxId(a) !== '' && taxId(a) === taxId(b), qr: (q) => q.tax_id },
    { field: 'invoice_number', label: 'Número', read: (f) => f.invoice_number, same: (a, b) => alnum(a) !== '' && alnum(a) === alnum(b), qr: (q) => q.number },
    { field: 'issue_date', label: 'Fecha', read: (f) => f.issue_date, same: (a, b) => String(a ?? '') === String(b), qr: (q) => q.date },
    { field: 'total', label: 'Total', read: (f) => f.total, same: (a, b) => cents(a) !== null && cents(a) === cents(b), qr: (q) => q.total },
];

/** Campos del formulario que no coinciden con el QR (o están vacíos). */
export function qrDifferences(form, qr) {
    if (!form || !qr) return [];

    return QR_FIELDS
        .filter((f) => !f.same(f.read(form), f.qr(qr)))
        .map((f) => ({ field: f.field, label: f.label, form: f.read(form) ?? '', qr: f.qr(qr) }));
}

/** Copia al formulario los datos del QR (no toca el desglose de IVA). */
export function applyQr(form, qr) {
    form.supplier ??= { name: '', tax_id: '' };
    form.supplier.tax_id = qr.tax_id;
    form.invoice_number = qr.number;
    form.issue_date = qr.date;
    form.total = qr.total;

    return form;
}
