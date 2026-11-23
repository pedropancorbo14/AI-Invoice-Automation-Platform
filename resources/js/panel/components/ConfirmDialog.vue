<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { answer, pending } from '../confirm.js';
import Icon from './Icon.vue';

const confirmButton = ref(null);

// Al abrirse, el foco va al botón de aceptar: Enter acepta, Esc cancela.
watch(pending, async (value) => {
    if (value) {
        await nextTick();
        confirmButton.value?.focus();
    }
});

function onKey(event) {
    if (event.key === 'Escape' && pending.value) {
        event.stopImmediatePropagation();
        answer(false);
    }
}

onMounted(() => window.addEventListener('keydown', onKey, true));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey, true));
</script>

<template>
    <Transition name="dialog">
        <div v-if="pending" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4 backdrop-blur-sm" @click.self="answer(false)">
            <div class="card w-full max-w-md p-6 shadow-xl" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title">
                <div class="flex gap-4">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-full"
                        :class="pending.danger ? 'bg-red-50 text-red-600 dark:bg-red-950/60 dark:text-red-300' : 'bg-brand-50 text-brand-600 dark:bg-brand-950/60 dark:text-brand-300'"
                    >
                        <Icon :name="pending.danger ? 'alert' : 'info'" :size="20" />
                    </span>
                    <div>
                        <h2 id="confirm-title" class="font-semibold">{{ pending.title }}</h2>
                        <p v-if="pending.message" class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ pending.message }}</p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary" @click="answer(false)">{{ pending.cancel }}</button>
                    <button
                        ref="confirmButton"
                        type="button"
                        class="btn"
                        :class="pending.danger ? 'bg-red-600 text-white shadow-sm hover:bg-red-700' : 'btn-primary'"
                        @click="answer(true)"
                    >{{ pending.confirmLabel }}</button>
                </div>
            </div>
        </div>
    </Transition>
</template>
