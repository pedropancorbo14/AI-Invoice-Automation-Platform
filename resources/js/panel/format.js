export function money(value, currency = 'EUR') {
    if (value === null || value === undefined || value === '') return '—';

    return Number(value).toLocaleString('es-ES', { style: 'currency', currency, useGrouping: 'always' });
}

export function date(value) {
    if (!value) return '—';
    const [y, m, d] = String(value).slice(0, 10).split('-');

    return `${d}/${m}/${y}`;
}

export function ago(value) {
    if (!value) return '';
    const minutes = Math.round((Date.now() - new Date(value).getTime()) / 60000);

    if (minutes < 1) return 'ahora';
    if (minutes < 60) return `hace ${minutes} min`;
    if (minutes < 60 * 24) return `hace ${Math.round(minutes / 60)} h`;

    return `hace ${Math.round(minutes / 60 / 24)} d`;
}
