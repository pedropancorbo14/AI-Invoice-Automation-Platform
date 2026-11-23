import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { errorMessage, toast, toasts, track, update } from './toast.js';

beforeEach(() => vi.useFakeTimers());
afterEach(() => {
    toasts.splice(0);
    vi.useRealTimers();
});

describe('toast', () => {
    it('closes ok notices by itself, errors later and loading ones never', () => {
        toast('Hecho');
        toast('Falló', { type: 'error' });
        toast('Trabajando…', { type: 'loading' });

        vi.advanceTimersByTime(4000);
        expect(toasts.map((t) => t.message)).toEqual(['Falló', 'Trabajando…']);

        vi.advanceTimersByTime(4000);
        expect(toasts.map((t) => t.message)).toEqual(['Trabajando…']);
    });

    it('turns a loading notice into the result, keeping its place', () => {
        const id = toast('Enviando…', { type: 'loading' });
        update(id, 'Enviado', { detail: 'a ana@example.com' });

        expect(toasts).toHaveLength(1);
        expect(toasts[0]).toMatchObject({ id, message: 'Enviado', type: 'ok', detail: 'a ana@example.com' });

        vi.advanceTimersByTime(4000);
        expect(toasts).toHaveLength(0);
    });
});

describe('track', () => {
    it('shows the work in progress and then the success', async () => {
        let finish;
        const promise = track(() => new Promise((resolve) => (finish = resolve)), {
            loading: 'Generando…',
            success: (name) => `Descargado: ${name}`,
        });

        expect(toasts[0]).toMatchObject({ type: 'loading', message: 'Generando…' });
        finish('libro.xlsx');

        await expect(promise).resolves.toBe('libro.xlsx');
        expect(toasts[0]).toMatchObject({ type: 'ok', message: 'Descargado: libro.xlsx' });
    });

    it('shows the error with the API reason, and rethrows it', async () => {
        const failure = Object.assign(new Error('422'), { body: { errors: { period: ['El periodo no es válido.'] } } });

        await expect(track(() => Promise.reject(failure), { loading: 'Generando…', error: 'No se pudo generar' })).rejects.toBe(failure);
        expect(toasts[0]).toMatchObject({ type: 'error', message: 'No se pudo generar', detail: 'El periodo no es válido.' });
    });
});

describe('errorMessage', () => {
    it('prefers the first validation error, then the message', () => {
        expect(errorMessage({ body: { errors: { a: ['Primero'], b: ['Segundo'] } } })).toBe('Primero');
        expect(errorMessage(new Error('Sin conexión'))).toBe('Sin conexión');
        expect(errorMessage(undefined)).toBe('Algo ha fallado. Inténtalo de nuevo.');
    });
});
