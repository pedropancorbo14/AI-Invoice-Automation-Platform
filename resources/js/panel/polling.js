import { onBeforeUnmount, onMounted, ref } from 'vue';
import { api } from './api.js';

/**
 * Llama a `fn` cada `interval()` milisegundos mientras la pestaña está a la
 * vista, y nada más volver a ella. `interval` es una función para poder
 * acelerar mientras algo se está procesando.
 */
export function usePolling(fn, interval) {
    let timer = null;

    const schedule = () => {
        clearTimeout(timer);
        timer = setTimeout(tick, interval());
    };

    async function tick() {
        if (document.visibilityState === 'visible') {
            try {
                await fn({ silent: true });
            } catch {
                // Un fallo puntual de red no debe romper la pantalla: se reintenta en el siguiente ciclo.
            }
        }
        schedule();
    }

    const onVisible = () => document.visibilityState === 'visible' && tick();

    onMounted(() => {
        schedule();
        document.addEventListener('visibilitychange', onVisible);
    });
    onBeforeUnmount(() => {
        clearTimeout(timer);
        document.removeEventListener('visibilitychange', onVisible);
    });

    return { restart: schedule };
}

/** Recuentos por estado de toda la cuenta: el contador de pendientes del menú. */
export const counts = ref({});

export async function refreshCounts() {
    try {
        counts.value = (await api('documents/counts', { background: true })).data;
    } catch {
        // El contador es un extra: si falla, se queda con el último valor.
    }
}
