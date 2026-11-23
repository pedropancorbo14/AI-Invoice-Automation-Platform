<script setup>
import { dismiss, toasts } from '../toast.js';
import Icon from './Icon.vue';

const ICON = { ok: 'check-circle', error: 'alert', info: 'info', loading: 'loader' };
const COLOR = {
    ok: 'text-emerald-600 dark:text-emerald-400',
    error: 'text-red-600 dark:text-red-400',
    info: 'text-brand-600 dark:text-brand-400',
    loading: 'text-brand-600 dark:text-brand-400',
};
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-center gap-2 p-4 sm:items-end">
        <TransitionGroup name="toast" tag="div" class="flex w-full flex-col items-center gap-2 sm:items-end" aria-live="polite">
            <div
                v-for="t in toasts"
                :key="t.id"
                class="pointer-events-auto relative flex w-full max-w-sm items-start gap-3 overflow-hidden rounded-xl border bg-white px-4 py-3 text-sm shadow-lg shadow-slate-900/10 dark:bg-slate-900"
                :class="t.type === 'error' ? 'border-red-200 dark:border-red-900' : 'border-slate-200 dark:border-slate-700'"
                :role="t.type === 'error' ? 'alert' : 'status'"
            >
                <span :key="t.type" class="mt-px animate-pop" :class="COLOR[t.type]">
                    <Icon :name="ICON[t.type] ?? 'info'" :size="18" :class="{ 'animate-spin': t.type === 'loading' }" />
                </span>
                <div class="flex-1">
                    <p class="font-medium">{{ t.message }}</p>
                    <p v-if="t.detail" class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ t.detail }}</p>
                </div>
                <button v-if="t.type !== 'loading'" type="button" class="text-slate-400 hover:text-slate-700 dark:hover:text-white" aria-label="Cerrar" @click="dismiss(t.id)">
                    <Icon name="x" :size="16" />
                </button>
                <!-- Mientras trabaja, una barra que recorre el aviso. -->
                <span v-if="t.type === 'loading'" class="absolute inset-x-0 bottom-0 h-0.5 overflow-hidden bg-brand-100 dark:bg-brand-950">
                    <span class="block h-full w-1/3 animate-indeterminate bg-brand-500" />
                </span>
            </div>
        </TransitionGroup>
    </div>
</template>
