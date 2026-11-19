import { describe, expect, it } from 'vitest';
import { cents, checkTotal, expectedTax, formatCents, toPayload } from './invoice.js';

const invoice = (overrides = {}) => ({
    tax_breakdown: [{ rate: '21.00', base: '100.00', tax_amount: '21.00', surcharge_amount: null }],
    withholding: { rate: null, amount: null },
    total: '121.00',
    ...overrides,
});

describe('cents', () => {
    it('reads dots and commas', () => {
        expect(cents('121.00')).toBe(12100);
        expect(cents('121,5')).toBe(12150);
        expect(cents(0.1 + 0.2)).toBe(30);
        expect(cents('')).toBeNull();
        expect(cents('abc')).toBeNull();
    });

    it('formats like an invoice', () => {
        expect(formatCents(123456)).toBe('1.234,56');
        expect(formatCents(null)).toBe('—');
    });
});

describe('checkTotal', () => {
    it('accepts a total within ±0.02', () => {
        expect(checkTotal(invoice()).ok).toBe(true);
        expect(checkTotal(invoice({ total: '121.02' })).ok).toBe(true);
        expect(checkTotal(invoice({ total: '121.03' })).ok).toBe(false);
    });

    it('subtracts the withholding and adds the surcharge', () => {
        const data = invoice({
            tax_breakdown: [{ rate: 21, base: 100, tax_amount: 21, surcharge_amount: 5.2 }],
            withholding: { rate: 15, amount: -15 },
            total: '111.20',
        });

        expect(checkTotal(data)).toMatchObject({ computed: 11120, ok: true });
    });

    it('is not ok without a breakdown or a total', () => {
        expect(checkTotal(invoice({ tax_breakdown: [] })).ok).toBe(false);
        expect(checkTotal(invoice({ total: '' })).ok).toBe(false);
    });
});

describe('expectedTax', () => {
    it('suggests the VAT amount for a base', () => {
        expect(expectedTax({ base: '33.33', rate: 21 })).toBe(700);
        expect(expectedTax({ base: '', rate: 21 })).toBeNull();
    });
});

describe('toPayload', () => {
    it('sends amounts as two-decimal strings', () => {
        const payload = toPayload(invoice({ total: '121,5', tax_breakdown: [{ rate: '21', base: '100,4', tax_amount: '21.1', surcharge_rate: '', surcharge_amount: '' }] }));

        expect(payload.total).toBe('121.50');
        expect(payload.tax_breakdown[0]).toEqual({ rate: 21, base: '100.40', tax_amount: '21.10', surcharge_rate: null, surcharge_amount: null });
    });
});
