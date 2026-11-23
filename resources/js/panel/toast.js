import { reactive } from 'vue';

/**
 * Avisos en una esquina. Tipos: ok, error, info y loading (con indicador de
 * carga y sin cierre automático hasta que se actualiza). Para una acción que
 * tarda, `track()` muestra "en curso" y lo convierte en éxito o error al acabar.
 */
export const toasts = reactive([]);
let nextId = 1;
const timers = new Map();

const DURATION = { ok: 3500, info: 4500, error: 7000 };

function schedule(id, type, timeout) {
    clearTimeout(timers.get(id));
    if (type === 'loading') return;
    timers.set(id, setTimeout(() => dismiss(id), timeout ?? DURATION[type] ?? 4000));
}

export function toast(message, { type = 'ok', timeout, detail } = {}) {
    const id = nextId++;
    toasts.push({ id, message, detail, type });
    schedule(id, type, timeout);

    return id;
}

/** Cambia un aviso ya visible (p. ej. de "Enviando…" a "Enviado"). */
export function update(id, message, { type = 'ok', timeout, detail } = {}) {
    const item = toasts.find((t) => t.id === id);
    if (!item) return toast(message, { type, timeout, detail });
    Object.assign(item, { message, type, detail });
    schedule(id, type, timeout);

    return id;
}

export function dismiss(id) {
    clearTimeout(timers.get(id));
    timers.delete(id);
    const index = toasts.findIndex((t) => t.id === id);
    if (index !== -1) toasts.splice(index, 1);
}

/** Mensaje legible de un error de la API (el primer error de validación, si lo hay). */
export function errorMessage(e) {
    if (e?.body?.errors) return Object.values(e.body.errors).flat()[0];

    return e?.message ?? 'Algo ha fallado. Inténtalo de nuevo.';
}

/**
 * Muestra `loading` mientras corre `action` y lo cambia por el resultado.
 * `success` puede ser un texto o una función que recibe lo que devuelve la acción.
 * Devuelve el resultado, o lanza el error tras mostrarlo.
 */
export async function track(action, { loading, success, error } = {}) {
    const id = toast(loading, { type: 'loading' });
    try {
        const result = await action();
        update(id, typeof success === 'function' ? success(result) : success, { timeout: 5000 });

        return result;
    } catch (e) {
        update(id, error ?? errorMessage(e), { type: 'error', detail: error ? errorMessage(e) : undefined });
        throw e;
    }
}
