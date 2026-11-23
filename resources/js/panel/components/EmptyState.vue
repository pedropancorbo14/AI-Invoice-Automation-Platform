<script setup>
import Icon from './Icon.vue';

defineProps({
    icon: { type: String, default: 'inbox' },
    title: { type: String, required: true },
    tone: { type: String, default: 'brand' },
});
</script>

<template>
    <div class="card flex flex-col items-center px-6 py-14 text-center">
        <div
            class="mb-4 flex size-12 items-center justify-center rounded-2xl"
            :class="tone === 'ok'
                ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-300'
                : 'bg-brand-50 text-brand-600 dark:bg-brand-950/60 dark:text-brand-300'"
        >
            <Icon :name="icon" :size="22" />
        </div>
        <p class="text-base font-semibold">{{ title }}</p>
        <div class="mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400"><slot /></div>
        <div v-if="$slots.actions" class="mt-5 flex flex-wrap justify-center gap-2"><slot name="actions" /></div>
    </div>
</template>
