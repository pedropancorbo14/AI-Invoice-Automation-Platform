import { describe, expect, it } from 'vitest';
import { currentQuarter, defaultQuarter, parseQuarter, quarterLabel, shiftQuarter } from './quarter.js';

describe('defaultQuarter', () => {
    it('propone el trimestre actual a partir de su segundo mes', () => {
        expect(defaultQuarter(new Date(2026, 10, 25))).toBe('2026-T4');
        expect(defaultQuarter(new Date(2026, 5, 30))).toBe('2026-T2');
    });

    it('en el primer mes de un trimestre propone el anterior, que es el que se presenta', () => {
        expect(defaultQuarter(new Date(2026, 9, 10))).toBe('2026-T3');
        expect(defaultQuarter(new Date(2026, 3, 1))).toBe('2026-T1');
        expect(defaultQuarter(new Date(2027, 0, 20))).toBe('2026-T4');
    });
});

describe('currentQuarter', () => {
    it('es el trimestre de la fecha, aunque sea su primer mes', () => {
        expect(currentQuarter(new Date(2026, 9, 10))).toBe('2026-T4');
        expect(currentQuarter(new Date(2026, 2, 31))).toBe('2026-T1');
    });
});

describe('shiftQuarter', () => {
    it('cambia de año al pasar del cuarto al primero y al revés', () => {
        expect(shiftQuarter('2026-T4', 1)).toBe('2027-T1');
        expect(shiftQuarter('2026-T1', -1)).toBe('2025-T4');
        expect(shiftQuarter('2026-T2', 1)).toBe('2026-T3');
    });
});

describe('parseQuarter y quarterLabel', () => {
    it('solo acepta claves válidas', () => {
        expect(parseQuarter('2026-T3')).toEqual({ year: 2026, quarter: 3 });
        expect(parseQuarter('2026-T5')).toBeNull();
        expect(parseQuarter(undefined)).toBeNull();
    });

    it('pone la etiqueta como el servidor', () => {
        expect(quarterLabel('2026-T4')).toBe('4.º trimestre de 2026');
    });
});
