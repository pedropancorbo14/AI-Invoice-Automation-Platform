/**
 * Cliente de la API con la sesión del navegador (Sanctum en modo SPA):
 * cookies de sesión + token XSRF, sin tokens en el navegador.
 */

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

export async function api(path, { method = 'GET', body, query } = {}) {
    const url = new URL(`/api/${path.replace(/^\//, '')}`, window.location.origin);
    Object.entries(query ?? {}).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') url.searchParams.set(key, value);
    });

    const isForm = body instanceof FormData;
    const response = await fetch(url, {
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

    const json = response.status === 204 ? null : await response.json().catch(() => null);

    if (!response.ok) throw new ApiError(response.status, json);

    return json;
}
