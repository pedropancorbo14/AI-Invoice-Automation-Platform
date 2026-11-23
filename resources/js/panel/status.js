/**
 * Estados del documento tal como los ve el cliente. Los nombres coinciden con
 * DocumentStatus::label() en PHP; aquí se usan donde la API solo da el código
 * (el historial) y para decidir colores y si un documento sigue en marcha.
 */
export const STATUS_LABELS = {
    received: 'Recibido',
    classified: 'Clasificado',
    extracted: 'Extraído',
    validated: 'Validado',
    exported: 'Exportado',
    needs_review: 'Pendiente de revisión',
    ignored: 'Ignorado',
    failed: 'Fallido',
};

/** La IA todavía lo está procesando: merece la pena refrescar pronto. */
export const IN_PROGRESS = ['received', 'classified', 'extracted'];

export function isInProgress(status) {
    return IN_PROGRESS.includes(status);
}

/** Se puede mandar de nuevo a la IA desde el panel. */
export function canReprocess(doc) {
    return ['ignored', 'failed'].includes(doc?.status) && doc?.mime_type !== 'message/rfc822';
}

export function statusTone(status) {
    if (status === 'needs_review') return 'warn';
    if (status === 'validated' || status === 'exported') return 'ok';
    if (status === 'failed') return 'bad';
    if (status === 'ignored') return 'muted';

    return 'busy';
}

const EVENT_LABELS = {
    created: 'Recibido',
    assigned: 'Asignado a un cliente',
    corrected: 'Dato corregido',
    file_replaced: 'Fichero adjuntado',
};

/** Una línea legible del historial de un documento. */
export function describeEvent(event) {
    if (event.type === 'status_changed') {
        return `${STATUS_LABELS[event.from_status] ?? event.from_status} → ${STATUS_LABELS[event.to_status] ?? event.to_status}`;
    }
    if (event.type === 'corrected') {
        return `Corregido ${FIELD_LABELS[event.payload?.field] ?? event.payload?.field}: ${event.payload?.before ?? '—'} → ${event.payload?.after ?? '—'}`;
    }

    return EVENT_LABELS[event.type] ?? event.type;
}

const FIELD_LABELS = {
    supplier_name: 'proveedor',
    supplier_tax_id: 'CIF del proveedor',
    customer_name: 'cliente',
    customer_tax_id: 'CIF del cliente',
    invoice_number: 'número',
    issue_date: 'fecha',
    due_date: 'vencimiento',
    document_type: 'tipo',
    total: 'total',
    withholding: 'retención',
    tax_breakdown: 'desglose de IVA',
    currency: 'divisa',
};
