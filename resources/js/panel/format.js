export function money(value, currency = 'EUR') {
    if (value === null || value === undefined || value === '') return '—';

    return Number(value).toLocaleString('es-ES', { style: 'currency', currency, useGrouping: 'always' });
}

export function date(value) {
    if (!value) return '—';
    const [y, m, d] = String(value).slice(0, 10).split('-');

    return `${d}/${m}/${y}`;
}

/** Fecha y hora completas, para el título (tooltip) de las fechas relativas. */
export function dateTime(value) {
    if (!value) return '';

    return new Date(value).toLocaleString('es-ES', { dateStyle: 'long', timeStyle: 'short' });
}

export function ago(value) {
    if (!value) return '';
    const minutes = Math.round((Date.now() - new Date(value).getTime()) / 60000);

    if (minutes < 1) return 'ahora';
    if (minutes < 60) return `hace ${minutes} min`;
    if (minutes < 60 * 24) return `hace ${Math.round(minutes / 60)} h`;

    return `hace ${Math.round(minutes / 60 / 24)} d`;
}
