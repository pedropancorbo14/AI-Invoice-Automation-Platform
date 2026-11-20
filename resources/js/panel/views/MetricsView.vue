<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { api } from '../api.js';

const month = ref(new Date().toISOString().slice(0, 7));
const metrics = ref(null);
const error = ref(null);

async function load() {
    error.value = null;
    try {
        metrics.value = (await api('metrics', { query: { month: month.value } })).data;
    } catch (e) {
        error.value = e.message;
    }
}

const automaticRate = computed(() => metrics.value?.validated.automatic_rate);
const maxReason = computed(() => Math.max(1, ...(metrics.value?.review_reasons ?? []).map((r) => r.count)));

onMounted(load);
watch(month, load);
</script>

<template>
    <div class="mb-5 flex flex-wrap items-end gap-4">
        <div>
            <h1 class="text-xl font-semibold">Métricas</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">Cuánto entra, cuánto sale solo y por qué se revisa.</p>
        </div>
        <div class="ml-auto w-44"><input v-model="month" type="month" class="input" aria-label="Mes" /></div>
    </div>

    <p v-if="error" class="card p-4 text-sm text-red-600">{{ error }}</p>

    <template v-else-if="metrics">
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="card p-5">
                <p class="text-sm text-slate-500 dark:text-slate-400">Facturas recibidas</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums">{{ metrics.received }}</p>
            </div>
            <div class="card p-5">
                <p class="text-sm text-slate-500 dark:text-slate-400">Validadas sin intervención</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums">{{ automaticRate === null ? '—' : `${automaticRate.toLocaleString('es-ES')} %` }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ metrics.validated.automatic }} solas · {{ metrics.validated.manual }} revisadas</p>
            </div>
            <RouterLink to="/revisar" class="card p-5 hover:border-amber-300">
                <p class="text-sm text-slate-500 dark:text-slate-400">Pendientes ahora</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums" :class="metrics.pending_review ? 'text-amber-600' : ''">{{ metrics.pending_review }}</p>
            </RouterLink>
        </div>

        <div class="card mt-4 p-5">
            <h2 class="mb-4 font-medium">Motivos de revisión más frecuentes</h2>
            <p v-if="!metrics.review_reasons.length" class="text-sm text-slate-500">Nada ha ido a revisión este mes.</p>
            <ul v-else class="space-y-2.5">
                <li v-for="r in metrics.review_reasons" :key="r.code" class="grid grid-cols-[180px_1fr_40px] items-center gap-3 text-sm">
                    <span class="truncate">{{ r.label }}</span>
                    <span class="h-2.5 rounded-full bg-amber-400 dark:bg-amber-500" :style="{ width: `${(r.count / maxReason) * 100}%` }" />
                    <span class="text-right tabular-nums text-slate-500">{{ r.count }}</span>
                </li>
            </ul>
        </div>
    </template>
</template>
