import { ref } from 'vue';

/**
 * Ventana de confirmación del panel (en lugar del confirm() del navegador).
 * `await confirm({ title, message, confirm, danger })` → true si acepta.
 */
export const pending = ref(null);

export function confirm({ title, message = '', confirm: confirmLabel = 'Aceptar', cancel = 'Cancelar', danger = false }) {
    return new Promise((resolve) => {
        pending.value = { title, message, confirmLabel, cancel, danger, resolve };
    });
}

export function answer(value) {
    pending.value?.resolve(value);
    pending.value = null;
}
