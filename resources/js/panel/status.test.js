import { describe, expect, it } from 'vitest';
import { canReprocess, describeEvent, isInProgress, statusTone } from './status.js';

describe('statusTone', () => {
    it('gives each status its colour', () => {
        expect(statusTone('needs_review')).toBe('warn');
        expect(statusTone('validated')).toBe('ok');
        expect(statusTone('exported')).toBe('ok');
        expect(statusTone('failed')).toBe('bad');
        expect(statusTone('ignored')).toBe('muted');
        expect(statusTone('received')).toBe('busy');
    });
});

describe('isInProgress', () => {
    it('is true only while the AI is still working', () => {
        expect(['received', 'classified', 'extracted'].every(isInProgress)).toBe(true);
        expect(['needs_review', 'validated', 'failed', 'ignored'].some(isInProgress)).toBe(false);
    });
});

describe('canReprocess', () => {
    it('allows ignored and failed documents with a file', () => {
        expect(canReprocess({ status: 'ignored', mime_type: 'application/pdf' })).toBe(true);
        expect(canReprocess({ status: 'failed', mime_type: 'image/jpeg' })).toBe(true);
    });

    it('refuses pending documents and emails without the invoice', () => {
        expect(canReprocess({ status: 'needs_review', mime_type: 'application/pdf' })).toBe(false);
        expect(canReprocess({ status: 'ignored', mime_type: 'message/rfc822' })).toBe(false);
        expect(canReprocess(null)).toBe(false);
    });
});

describe('describeEvent', () => {
    it('reads status changes in Spanish', () => {
        expect(describeEvent({ type: 'status_changed', from_status: 'extracted', to_status: 'needs_review' }))
            .toBe('Extraído → Pendiente de revisión');
    });

    it('names the corrected field with its before and after', () => {
        expect(describeEvent({ type: 'corrected', payload: { field: 'total', before: '130.00', after: '121.00' } }))
            .toBe('Corregido total: 130.00 → 121.00');
        expect(describeEvent({ type: 'corrected', payload: { field: 'due_date', before: null, after: '2026-12-15' } }))
            .toBe('Corregido vencimiento: — → 2026-12-15');
    });

    it('labels the other events and falls back to the raw type', () => {
        expect(describeEvent({ type: 'created' })).toBe('Recibido');
        expect(describeEvent({ type: 'file_replaced' })).toBe('Fichero adjuntado');
        expect(describeEvent({ type: 'something_new' })).toBe('something_new');
    });
});
