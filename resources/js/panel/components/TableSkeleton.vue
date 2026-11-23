<script setup>
defineProps({ rows: { type: Number, default: 6 } });
</script>

<template>
    <div class="card overflow-hidden" aria-busy="true" aria-label="Cargando">
        <div class="h-10 border-b border-slate-100 bg-slate-50/80 dark:border-slate-800 dark:bg-slate-800/40" />
        <div v-for="i in rows" :key="i" class="flex items-center gap-6 border-b border-slate-100 px-4 py-4 last:border-0 dark:border-slate-800">
            <div class="flex-1 space-y-2">
                <div class="skeleton h-3.5" :style="{ width: `${40 + ((i * 17) % 35)}%` }" />
                <div class="skeleton h-3 w-24" />
            </div>
            <div class="skeleton hidden h-3.5 w-28 md:block" />
            <div class="skeleton h-3.5 w-16" />
            <div class="skeleton h-5 w-24 rounded-full" />
        </div>
    </div>
</template>
