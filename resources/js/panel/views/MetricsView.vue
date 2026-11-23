<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { api } from '../api.js';
import { STATUS_LABELS, statusTone } from '../status.js';
import Icon from '../components/Icon.vue';

const month = ref(new Date().toISOString().slice(0, 7));
const metrics = ref(null);
const error = ref(null);
const loading = ref(true);

async function load() {
    error.value = null;
    loading.value = true;
    try {
        metrics.value = (await api('metrics', { query: { month: month.value } })).data;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

const currentMonth = new Date().toISOString().slice(0, 7);
const monthLabel = computed(() => {
    const [y, m] = month.value.split('-').map(Number);
    const label = new Date(y, m - 1, 1).toLocaleDateString('es-ES', { month: 'long', year: 'numeric' });

    return label.charAt(0).toUpperCase() + label.slice(1);
});

function shift(delta) {
    const [y, m] = month.value.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    month.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

const automaticRate = computed(() => metrics.value?.validated.automatic_rate);
const maxReason = computed(() => Math.max(1, ...(metrics.value?.review_reasons ?? []).map((r) => r.count)));

const BAR = { warn: 'bg-amber-400', ok: 'bg-emerald-500', bad: 'bg-red-500', muted: 'bg-slate-300 dark:bg-slate-600', busy: 'bg-brand-400' };
const ORDER = ['validated', 'exported', 'needs_review', 'received', 'classified', 'extracted', 'ignored', 'failed'];
const byStatus = computed(() => {
    const entries = Object.entries(metrics.value?.by_status ?? {}).filter(([, n]) => n > 0);
    const total = entries.reduce((sum, [, n]) => sum + n, 0) || 1;

    return entries
        .sort(([a], [b]) => ORDER.indexOf(a) - ORDER.indexOf(b))
        .map(([status, count]) => ({ status, count, label: STATUS_LABELS[status] ?? status, share: (count / total) * 100, bar: BAR[statusTone(status)] }));
});

onMounted(load);
watch(month, load);
</script>

<template>
    <div class="mb-6 flex flex-wrap items-end gap-4">
        <div>
            <h1 class="page-title">Métricas</h1>
            <p class="page-subtitle">Cuánto entra, cuánto sale solo y por qué se revisa.</p>
        </div>
        <div class="ml-auto flex items-center gap-1">
            <button type="button" class="btn btn-secondary !px-2" aria-label="Mes anterior" @click="shift(-1)"><Icon name="chevron-left" /></button>
            <label class="relative">
                <span class="btn btn-secondary min-w-44">{{ monthLabel }}</span>
                <input v-model="month" type="month" class="absolute inset-0 cursor-pointer opacity-0" aria-label="Mes" />
            </label>
            <button type="button" class="btn btn-secondary !px-2" aria-label="Mes siguiente" :disabled="month >= currentMonth" @click="shift(1)"><Icon name="chevron-right" /></button>
        </div>
    </div>

    <p v-if="error" class="card flex items-center gap-2 p-4 text-sm text-red-600"><Icon name="alert" /> {{ error }}</p>

    <div v-else-if="loading && !metrics" class="grid gap-4 sm:grid-cols-3">
        <div v-for="i in 3" :key="i" class="card space-y-3 p-5"><div class="skeleton h-3 w-1/2" /><div class="skeleton h-8 w-1/3" /></div>
    </div>

    <template v-else-if="metrics">
        <div class="grid gap-4 sm:grid-cols-3" :class="{ 'opacity-60': loading }">
            <RouterLink to="/facturas" class="card group p-5 transition hover:border-brand-300 hover:shadow-md dark:hover:border-brand-700">
                <div class="flex items-center justify-between text-sm text-slate-500 dark:text-slate-400">
                    Facturas recibidas
                    <span class="flex size-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-950/60 dark:text-brand-300"><Icon name="files" /></span>
                </div>
                <p class="mt-2 text-3xl font-semibold tracking-tight tabular-nums">{{ metrics.received }}</p>
                <p class="mt-1 text-xs text-slate-500 group-hover:text-brand-600">Ver todas →</p>
            </RouterLink>

            <div class="card p-5">
                <div class="flex items-center justify-between text-sm text-slate-500 dark:text-slate-400">
                    Validadas sin intervención
                    <span class="flex size-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-300"><Icon name="check-circle" /></span>
                </div>
                <p class="mt-2 text-3xl font-semibold tracking-tight tabular-nums">{{ automaticRate === null ? '—' : `${automaticRate.toLocaleString('es-ES')} %` }}</p>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div class="h-full rounded-full bg-emerald-500 transition-all" :style="{ width: `${automaticRate ?? 0}%` }" />
                </div>
                <p class="mt-1.5 text-xs text-slate-500">{{ metrics.validated.automatic }} solas · {{ metrics.validated.manual }} revisadas</p>
            </div>

            <RouterLink to="/revisar" class="card group p-5 transition hover:border-amber-300 hover:shadow-md dark:hover:border-amber-700">
                <div class="flex items-center justify-between text-sm text-slate-500 dark:text-slate-400">
                    Pendientes ahora
                    <span class="flex size-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/60 dark:text-amber-300"><Icon name="inbox" /></span>
                </div>
                <p class="mt-2 text-3xl font-semibold tracking-tight tabular-nums" :class="metrics.pending_review ? 'text-amber-600 dark:text-amber-400' : ''">{{ metrics.pending_review }}</p>
                <p class="mt-1 text-xs text-slate-500 group-hover:text-amber-600">{{ metrics.pending_review ? 'Revisar ahora →' : 'Todo al día' }}</p>
            </RouterLink>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2" :class="{ 'opacity-60': loading }">
            <div class="card p-5">
                <h2 class="mb-4 font-semibold">Cómo terminaron las de este mes</h2>
                <p v-if="!byStatus.length" class="text-sm text-slate-500">No ha llegado nada este mes.</p>
                <template v-else>
                    <div class="mb-4 flex h-3 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div v-for="s in byStatus" :key="s.status" :class="s.bar" :style="{ width: `${s.share}%` }" :title="`${s.label}: ${s.count}`" />
                    </div>
                    <ul class="space-y-2 text-sm">
                        <li v-for="s in byStatus" :key="s.status">
                            <RouterLink :to="{ name: 'documents', query: { estado: s.status } }" class="flex items-center gap-2 rounded-lg px-2 py-1 -mx-2 hover:bg-slate-50 dark:hover:bg-slate-800/60">
                                <span class="size-2.5 rounded-sm" :class="s.bar" />
                                <span class="flex-1">{{ s.label }}</span>
                                <span class="text-slate-500 tabular-nums">{{ s.count }}</span>
                                <span class="w-12 text-right text-xs text-slate-400 tabular-nums">{{ Math.round(s.share) }} %</span>
                            </RouterLink>
                        </li>
                    </ul>
                </template>
            </div>

            <div class="card p-5">
                <h2 class="mb-4 font-semibold">Motivos de revisión más frecuentes</h2>
                <p v-if="!metrics.review_reasons.length" class="text-sm text-slate-500">Nada ha ido a revisión este mes.</p>
                <ul v-else class="space-y-3">
                    <li v-for="r in metrics.review_reasons" :key="r.code" class="grid grid-cols-[minmax(0,160px)_1fr_32px] items-center gap-3 text-sm">
                        <span class="truncate" :title="r.label">{{ r.label }}</span>
                        <span class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <span class="block h-full rounded-full bg-amber-400 dark:bg-amber-500" :style="{ width: `${(r.count / maxReason) * 100}%` }" />
                        </span>
                        <span class="text-right text-slate-500 tabular-nums">{{ r.count }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </template>
</template>
