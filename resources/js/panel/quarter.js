/**
 * Trimestres para el resumen de IVA, con la clave que entiende la API
 * ("2026-T4").
 */

export function quarterKey(year, quarter) {
    return `${year}-T${quarter}`;
}

export function parseQuarter(key) {
    const match = /^(\d{4})-T([1-4])$/.exec(key ?? '');

    return match ? { year: Number(match[1]), quarter: Number(match[2]) } : null;
}

/**
 * El trimestre que se está preparando: en el primer mes de un trimestre
 * (enero, abril, julio, octubre) toca presentar el anterior; si no, el actual.
 */
export function defaultQuarter(today = new Date()) {
    const month = today.getMonth();
    const current = Math.floor(month / 3) + 1;

    if (month % 3 !== 0) return quarterKey(today.getFullYear(), current);

    return current === 1 ? quarterKey(today.getFullYear() - 1, 4) : quarterKey(today.getFullYear(), current - 1);
}

/** El trimestre en curso: no tiene sentido ir más allá. */
export function currentQuarter(today = new Date()) {
    return quarterKey(today.getFullYear(), Math.floor(today.getMonth() / 3) + 1);
}

export function shiftQuarter(key, delta) {
    const { year, quarter } = parseQuarter(key);
    const index = year * 4 + (quarter - 1) + delta;

    return quarterKey(Math.floor(index / 4), (index % 4) + 1);
}

/** "4.º trimestre de 2026": igual que la etiqueta del servidor. */
export function quarterLabel(key) {
    const { year, quarter } = parseQuarter(key);

    return `${quarter}.º trimestre de ${year}`;
}
