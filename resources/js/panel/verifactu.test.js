import { describe, expect, it } from 'vitest';
import { applyQr, qrDifferences } from './verifactu.js';

const qr = { tax_id: 'B12345674', number: 'F-2026-001', date: '2026-11-15', total: '121.00' };
const form = (overrides = {}) => ({
    supplier: { name: 'Fontanería García S.L.', tax_id: 'B-12345674' },
    invoice_number: 'F 2026/001',
    issue_date: '2026-11-15',
    total: '121',
    ...overrides,
});

describe('qrDifferences', () => {
    it('no ve diferencias de formato (guiones, espacios, decimales)', () => {
        expect(qrDifferences(form(), qr)).toEqual([]);
    });

    it('lista lo que no coincide y lo que está vacío', () => {
        expect(qrDifferences(form({ total: '112,00', issue_date: '' }), qr)).toEqual([
            { field: 'issue_date', label: 'Fecha', form: '', qr: '2026-11-15' },
            { field: 'total', label: 'Total', form: '112,00', qr: '121.00' },
        ]);
    });

    it('sin QR no hay nada que comparar', () => {
        expect(qrDifferences(form(), null)).toEqual([]);
    });
});

describe('applyQr', () => {
    it('copia el CIF, el número, la fecha y el total del QR', () => {
        const result = applyQr(form({ total: '112.00', supplier: null }), qr);

        expect(result.supplier.tax_id).toBe('B12345674');
        expect(result.invoice_number).toBe('F-2026-001');
        expect(result.total).toBe('121.00');
        expect(qrDifferences(result, qr)).toEqual([]);
    });
});
