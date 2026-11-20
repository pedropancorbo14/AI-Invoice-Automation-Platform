// Importes en céntimos (enteros), como en App\Support\Money: nunca floats.

const MONTHS_ES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const MONTHS_ES_SHORT = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const MONTHS_EN = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const MONTHS_PT = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

/** Redondeo comercial (mitad hacia fuera del cero). */
export function round(value) {
    return Math.sign(value) * Math.round(Math.abs(value));
}

/** Porcentaje de un importe en céntimos. */
export function percentOf(cents, rate) {
    return round((cents * rate) / 100);
}

/** "123.45" con punto decimal: el formato de la verdad anotada. */
export function decimal(cents) {
    const sign = cents < 0 ? '-' : '';
    const abs = Math.abs(cents);
    return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

/** Número para el JSON (123.45). */
export function toNumber(cents) {
    return Number(decimal(cents));
}

function group(intPart, sep) {
    return intPart.replace(/\B(?=(\d{3})+(?!\d))/g, sep);
}

/**
 * Formatos de importe que se ven en facturas reales.
 * es-symbol: 1.234,56 € · es-tight: 1.234,56€ · es-prefix: € 1.234,56
 * es-plain: 1234,56 · en: €1,234.56 · pt: 1 234,56 €
 */
export function formatMoney(cents, style, currency = 'EUR') {
    const sign = cents < 0 ? '-' : '';
    const abs = Math.abs(cents);
    const intPart = String(Math.floor(abs / 100));
    const dec = String(abs % 100).padStart(2, '0');
    const symbol = { EUR: '€', USD: '$', GBP: '£' }[currency] ?? currency;

    switch (style) {
        case 'es-tight':
            return `${sign}${group(intPart, '.')},${dec}€`;
        case 'es-prefix':
            return `${sign}€ ${group(intPart, '.')},${dec}`;
        case 'es-plain':
            return `${sign}${intPart},${dec}`;
        case 'es-nosymbol':
            return `${sign}${group(intPart, '.')},${dec}`;
        case 'en':
            return `${sign}${symbol}${group(intPart, ',')}.${dec}`;
        case 'pt':
            return `${sign}${group(intPart, ' ')},${dec} €`;
        case 'es-symbol':
        default:
            return `${sign}${group(intPart, '.')},${dec} ${symbol}`;
    }
}

/** Número sin moneda (cantidades, precios unitarios con 2-3 decimales). */
export function formatNumber(value, decimals, style) {
    const fixed = Math.abs(value).toFixed(decimals);
    const [intPart, dec] = fixed.split('.');
    const sign = value < 0 ? '-' : '';
    if (style === 'en') {
        return `${sign}${group(intPart, ',')}${dec ? `.${dec}` : ''}`;
    }
    return `${sign}${style === 'es-plain' ? intPart : group(intPart, '.')}${dec ? `,${dec}` : ''}`;
}

export function formatRate(rate, style) {
    const text = Number.isInteger(rate) ? String(rate) : String(rate);
    return style === 'en' ? `${text}%` : `${text.replace('.', ',')} %`;
}

/**
 * Fechas como aparecen en facturas reales. Ninguno de los formatos numéricos
 * españoles se usa con el orden mes/día: así la verdad no es ambigua.
 */
export function formatDate(iso, style) {
    const [y, m, d] = iso.split('-').map(Number);
    const dd = String(d).padStart(2, '0');
    const mm = String(m).padStart(2, '0');

    switch (style) {
        case 'dash':
            return `${dd}-${mm}-${y}`;
        case 'short-year':
            return `${d}/${m}/${String(y).slice(2)}`;
        case 'long':
            return `${d} de ${MONTHS_ES[m - 1]} de ${y}`;
        case 'month-short':
            return `${dd}-${MONTHS_ES_SHORT[m - 1]}-${y}`;
        case 'dot':
            return `${dd}.${mm}.${y}`;
        case 'iso':
            return `${y}-${mm}-${dd}`;
        case 'en-long':
            return `${MONTHS_EN[m - 1]} ${d}, ${y}`;
        case 'en-short':
            return `${d} ${MONTHS_EN[m - 1].slice(0, 3)} ${y}`;
        case 'pt-long':
            return `${d} de ${MONTHS_PT[m - 1]} de ${y}`;
        case 'slash':
        default:
            return `${dd}/${mm}/${y}`;
    }
}

export function monthName(iso) {
    return MONTHS_ES[Number(iso.split('-')[1]) - 1];
}

export function addDays(iso, days) {
    const date = new Date(`${iso}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
}
