<script setup>
import { computed } from 'vue';
import { statusTone } from '../status.js';

const props = defineProps({
    status: { type: String, required: true },
    label: { type: String, required: true },
});

const TONES = {
    warn: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/50 dark:text-amber-200 dark:ring-amber-800',
    ok: 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-200 dark:ring-emerald-800',
    bad: 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-950/50 dark:text-red-200 dark:ring-red-800',
    muted: 'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
    busy: 'bg-brand-50 text-brand-700 ring-brand-200 dark:bg-brand-950/60 dark:text-brand-200 dark:ring-brand-900',
};
const DOTS = { warn: 'bg-amber-500', ok: 'bg-emerald-500', bad: 'bg-red-500', muted: 'bg-slate-400', busy: 'bg-brand-500 animate-pulse' };

const tone = computed(() => statusTone(props.status));
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset" :class="TONES[tone]">
        <span class="size-1.5 rounded-full" :class="DOTS[tone]" />
        {{ label }}
    </span>
</template>
