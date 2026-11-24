import { describe, expect, it } from 'vitest';
import { isSingleCompany } from './account.js';

describe('isSingleCompany', () => {
    it('solo es empresa única si la cuenta lo dice', () => {
        expect(isSingleCompany({ account: { single_company: true } })).toBe(true);
        expect(isSingleCompany({ account: { single_company: false } })).toBe(false);
        expect(isSingleCompany({ account: {} })).toBe(false);
        expect(isSingleCompany(null)).toBe(false);
    });
});
