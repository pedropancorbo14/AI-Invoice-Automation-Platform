<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api.js';
import { ago, dateTime, money } from '../format.js';
import { usePolling } from '../polling.js';
import EmptyState from '../components/EmptyState.vue';
import Icon from '../components/Icon.vue';
import TableSkeleton from '../components/TableSkeleton.vue';

const router = useRouter();
const documents = ref([]);
const companies = ref([]);
const loading = ref(true);
const error = ref(null);
const companyId = ref('');
const reason = ref('');

async function load({ silent = false } = {}) {
    if (!silent) loading.value = true;
    error.value = null;
    try {
        const response = await api('documents', {
            background: silent,
            query: { status: 'needs_review', company_id: companyId.value, review_code: reason.value },
        });
        documents.value = response.data;
    } catch (e) {
        if (!silent) error.value = e.message;
    } finally {
        loading.value = false;
    }
}

// Motivos presentes en la bandeja, con cuántos hay de cada uno.
const reasons = computed(() => {
    const counts = new Map();
    for (const doc of documents.value) {
        for (const r of doc.review_reasons) counts.set(r.code, { ...r, count: (counts.get(r.code)?.count ?? 0) + 1 });
    }
    return [...counts.values()].sort((a, b) => b.count - a.count);
});

const filtered = computed(() => companyId.value !== '' || reason.value !== '');

// Más de 3 días esperando: se marca para que no se quede olvidada.
const isOld = (doc) => Date.now() - new Date(doc.created_at).getTime() > 3 * 24 * 3600 * 1000;

function open(doc) {
    router.push({ name: 'review', params: { id: doc.id } });
}

function start() {
    if (documents.value.length) open(documents.value[0]);
}

function onKey(event) {
    const target = event.target;
    if (event.ctrlKey || event.metaKey || event.altKey) return;
    if (target instanceof HTMLElement && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) return;
    if (event.key === 'r' || event.key === 'R') {
        event.preventDefault();
        start();
    }
}

// Llegan facturas nuevas mientras se trabaja: se refresca sola cada 30 s.
usePolling(load, () => 30000);

onMounted(async () => {
    window.addEventListener('keydown', onKey);
    companies.value = (await api('companies')).data;
    await load();
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

watch([companyId, reason], () => load());
</script>

<template>
    <div class="mb-6 flex flex-wrap items-end gap-4">
        <div>
            <h1 class="page-title">Pendientes de revisar</h1>
            <p class="page-subtitle">Lo que el sistema no ha podido validar solo. Por orden de llegada.</p>
        </div>
        <div class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
            <select v-model="companyId" class="input sm:!w-52" aria-label="Cliente">
                <option value="">Todos los clientes</option>
                <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <select v-model="reason" class="input sm:!w-52" aria-label="Motivo">
                <option value="">Todos los motivos</option>
                <option v-for="r in reasons" :key="r.code" :value="r.code">{{ r.label }} ({{ r.count }})</option>
                <option v-if="reason && !reasons.some((r) => r.code === reason)" :value="reason">{{ reason }}</option>
            </select>
            <button v-if="documents.length" type="button" class="btn btn-primary w-full sm:w-auto" title="Atajo: R" @click="start">
                <Icon name="play" :size="14" /> Empezar a revisar <kbd class="!border-brand-400 !bg-brand-500 !text-brand-50">R</kbd>
            </button>
        </div>
    </div>

    <p v-if="error" class="card flex items-center gap-2 p-4 text-sm text-red-600"><Icon name="alert" /> {{ error }}</p>

    <TableSkeleton v-else-if="loading" />

    <EmptyState v-else-if="documents.length === 0 && !filtered" icon="check-circle" tone="ok" title="Todo al día">
        No hay nada pendiente de revisar. Las facturas nuevas que lleguen aparecerán aquí solas.
        <template #actions>
            <RouterLink to="/facturas" class="btn btn-secondary"><Icon name="files" /> Ver todas las facturas</RouterLink>
            <RouterLink to="/subir" class="btn btn-secondary"><Icon name="upload" /> Subir facturas</RouterLink>
        </template>
    </EmptyState>

    <EmptyState v-else-if="documents.length === 0" icon="search" title="Nada con estos filtros">
        Prueba con otro cliente o motivo.
        <template #actions>
            <button type="button" class="btn btn-secondary" @click="companyId = ''; reason = ''">Quitar filtros</button>
        </template>
    </EmptyState>

    <div v-else class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="table-head">
                <tr>
                    <th class="px-4 py-3">Proveedor</th>
                    <th class="hidden px-4 py-3 md:table-cell">Cliente</th>
                    <th class="px-4 py-3 text-right">Total</th>
                    <th class="hidden px-4 py-3 sm:table-cell">Motivo</th>
                    <th class="hidden px-4 py-3 text-right sm:table-cell">Recibida</th>
                </tr>
            </thead>
            <TransitionGroup tag="tbody" name="list" class="divide-y divide-slate-100 dark:divide-slate-800">
                <tr v-for="doc in documents" :key="doc.id" class="table-row" tabindex="0" @click="open(doc)" @keydown.enter="open(doc)">
                    <td class="px-4 py-3.5">
                        <div class="font-medium">{{ doc.extraction?.data?.supplier?.name ?? doc.original_filename }}</div>
                        <div class="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500">
                            <Icon :name="doc.source === 'upload' ? 'upload' : 'mail'" :size="12" />
                            {{ doc.extraction?.data?.invoice_number ?? 'Sin datos' }}
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1 sm:hidden">
                            <span v-for="r in doc.review_reasons" :key="r.code" class="chip">{{ r.label }}</span>
                        </div>
                    </td>
                    <td class="hidden px-4 py-3.5 md:table-cell">
                        <span v-if="doc.company">{{ doc.company.name }}</span>
                        <span v-else class="inline-flex items-center gap-1 text-brand-600 dark:text-brand-400"><Icon name="building" :size="13" /> Sin asignar</span>
                    </td>
                    <td class="px-4 py-3.5 text-right font-medium tabular-nums">{{ money(doc.extraction?.data?.total, doc.extraction?.data?.currency ?? 'EUR') }}</td>
                    <td class="hidden px-4 py-3.5 sm:table-cell">
                        <div class="flex flex-wrap gap-1">
                            <span v-for="r in doc.review_reasons" :key="r.code" class="chip">{{ r.label }}</span>
                        </div>
                    </td>
                    <td class="hidden px-4 py-3.5 text-right whitespace-nowrap sm:table-cell" :class="isOld(doc) ? 'font-medium text-amber-600 dark:text-amber-400' : 'text-slate-500'" :title="dateTime(doc.created_at)">
                        {{ ago(doc.created_at) }}
                    </td>
                </tr>
            </TransitionGroup>
        </table>
    </div>
</template>
