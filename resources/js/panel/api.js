/**
 * Cliente de la API con la sesión del navegador (Sanctum en modo SPA):
 * cookies de sesión + token XSRF, sin tokens en el navegador.
 */

import { ref } from 'vue';

/** Peticiones en curso: alimenta la barra de progreso de arriba. */
export const inFlight = ref(0);

export class ApiError extends Error {
    constructor(status, body) {
        super(body?.message ?? `Error ${status}`);
        this.status = status;
        this.body = body ?? {};
    }
}

function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function buildUrl(path, query) {
    const url = new URL(`/api/${path.replace(/^\//, '')}`, window.location.origin);
    Object.entries(query ?? {}).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') url.searchParams.set(key, value);
    });

    return url;
}

/**
 * Petición con la sesión. `background: true` no mueve la barra de progreso
 * (para los refrescos automáticos, que no ha pedido el usuario).
 */
async function request(path, { method = 'GET', body, query, background = false } = {}) {
    const isForm = body instanceof FormData;
    if (!background) inFlight.value++;
    try {
        const response = await fetch(buildUrl(path, query), {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
                ...(body && !isForm ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body ? (isForm ? body : JSON.stringify(body)) : undefined,
        });

        if (response.status === 401 || response.status === 419) {
            window.location.href = '/login';

            return new Promise(() => {});
        }

        return response;
    } catch {
        throw new ApiError(0, { message: 'Sin conexión con el servidor. Comprueba la red e inténtalo de nuevo.' });
    } finally {
        if (!background) inFlight.value--;
    }
}

export async function api(path, options = {}) {
    const response = await request(path, options);
    const json = response.status === 204 ? null : await response.json().catch(() => null);

    if (!response.ok) throw new ApiError(response.status, json);

    return json;
}

/**
 * Descarga un fichero de la API sin salir del panel: así se puede avisar de
 * que se está generando y enseñar el error si falla (un enlace normal abriría
 * la respuesta de error en el navegador). Devuelve el nombre del fichero.
 */
export async function download(path, query) {
    const response = await request(path, { query });

    if (!response.ok) {
        throw new ApiError(response.status, await response.json().catch(() => null));
    }

    const disposition = response.headers.get('Content-Disposition') ?? '';
    const filename = decodeURIComponent(disposition.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i)?.[1] ?? 'descarga');
    const url = URL.createObjectURL(await response.blob());
    const link = Object.assign(document.createElement('a'), { href: url, download: filename });
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10000);

    return filename;
}
