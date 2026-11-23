<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api.js';
import { ago, date, money } from '../format.js';

const route = useRoute();
const router = useRouter();
const documents = ref([]);
const companies = ref([]);
const counts = ref({});
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const error = ref(null);

// El estado, el cliente y la página van en la URL para poder volver o compartir el enlace.
const status = computed(() => route.query.estado ?? '');
const companyId = computed(() => route.query.cliente ?? '');
const page = computed(() => Number(route.query.pagina ?? 1));

const STATUSES = [
    { value: 'needs_review', label: 'Pendientes' },
    { value: 'received', label: 'Recibidas' },
    { value: 'classified', label: 'Clasificadas' },
    { value: 'extracted', label: 'Extraídas' },
    { value: 'validated', label: 'Validadas' },
    { value: 'exported', label: 'Exportadas' },
    { value: 'ignored', label: 'Ignoradas' },
    { value: 'failed', label: 'Fallidas' },
];

const BADGES = {
    needs_review: 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200',
    validated: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200',
    exported: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200',
    ignored: 'bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
};
const inProgress = 'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-200';

const total = computed(() => Object.values(counts.value).reduce((sum, n) => sum + n, 0));

// Las pestañas sin documentos no se muestran (salvo la activa), para no llenar la barra de ceros.
const tabs = computed(() => STATUSES.filter((s) => (counts.value[s.value] ?? 0) > 0 || s.value === status.value));

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const response = await api('documents', {
            query: { status: status.value, company_id: companyId.value, page: page.value },
        });
        documents.value = response.data;
        counts.value = response.meta.status_counts ?? {};
        pagination.value = response.meta;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function filter(changes) {
    const query = { ...route.query, ...changes };
    if (!('pagina' in changes)) delete query.pagina;
    Object.keys(query).forEach((key) => (query[key] === '' || query[key] === 1) && delete query[key]);
    router.push({ query });
}

function open(doc) {
    router.push({ name: 'review', params: { id: doc.id } });
}

function detail(doc) {
    if (doc.review_reasons.length > 0) return null;
    if (['failed', 'ignored', 'needs_review'].includes(doc.status)) return doc.status_reason;

    return null;
}

onMounted(async () => {
    companies.value = (await api('companies')).data;
    await load();
});

// Solo al cambiar los filtros de esta vista, no al salir de ella.
watch(() => route.query, () => route.name === 'documents' && load());
</script>

<template>
    <div class="mb-5 flex flex-wrap items-end gap-4">
        <div>
            <h1 class="text-xl font-semibold">Facturas</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">Todo lo recibido, en cualquier estado. Lo último primero.</p>
        </div>
        <div class="ml-auto w-56">
            <select :value="companyId" class="input" aria-label="Cliente" @change="filter({ cliente: $event.target.value })">
                <option value="">Todos los clientes</option>
                <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
        </div>
    </div>

    <nav class="mb-4 flex gap-1 overflow-x-auto text-sm" aria-label="Estado">
        <button type="button" class="tab" :class="{ 'tab-active': status === '' }" @click="filter({ estado: '' })">
            Todas <span class="tab-count">{{ total }}</span>
        </button>
        <button
            v-for="s in tabs"
            :key="s.value"
            type="button"
            class="tab"
            :class="{ 'tab-active': status === s.value }"
            @click="filter({ estado: s.value })"
        >
            {{ s.label }} <span class="tab-count">{{ counts[s.value] ?? 0 }}</span>
        </button>
    </nav>

    <p v-if="error" class="card p-4 text-sm text-red-600">{{ error }}</p>

    <div v-else-if="loading" class="card p-8 text-center text-sm text-slate-500">Cargando…</div>

    <div v-else-if="documents.length === 0" class="card p-12 text-center">
        <p class="text-lg font-medium">No hay facturas</p>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Nada en este estado. Puedes <a href="/subir" class="text-sky-600 hover:underline">subir facturas</a> o reenviarlas por email.
        </p>
    </div>

    <template v-else>
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Proveedor</th>
                        <th class="hidden px-4 py-2.5 font-medium md:table-cell">Cliente</th>
                        <th class="hidden px-4 py-2.5 font-medium lg:table-cell">Fecha</th>
                        <th class="px-4 py-2.5 text-right font-medium">Total</th>
                        <th class="px-4 py-2.5 font-medium">Estado</th>
                        <th class="hidden px-4 py-2.5 text-right font-medium sm:table-cell">Recibida</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr
                        v-for="doc in documents"
                        :key="doc.id"
                        class="cursor-pointer hover:bg-sky-50/60 dark:hover:bg-slate-800/60"
                        tabindex="0"
                        @click="open(doc)"
                        @keydown.enter="open(doc)"
                    >
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ doc.extraction?.data?.supplier?.name ?? doc.original_filename }}</div>
                            <div class="text-xs text-slate-500">
                                #{{ doc.id }} · {{ doc.extraction?.data?.invoice_number ?? 'Sin datos' }}
                                · {{ doc.source === 'upload' ? 'Subida' : 'Email' }}
                            </div>
                        </td>
                        <td class="hidden px-4 py-3 md:table-cell">{{ doc.company?.name ?? 'Sin asignar' }}</td>
                        <td class="hidden px-4 py-3 tabular-nums lg:table-cell">{{ date(doc.extraction?.data?.issue_date) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ money(doc.extraction?.data?.total, doc.extraction?.data?.currency ?? 'EUR') }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="BADGES[doc.status] ?? inProgress">
                                {{ doc.status_label }}
                            </span>
                            <div v-if="doc.review_reasons.length" class="mt-1 flex flex-wrap gap-1">
                                <span v-for="r in doc.review_reasons" :key="r.code" class="chip">{{ r.label }}</span>
                            </div>
                            <p v-else-if="detail(doc)" class="mt-1 max-w-xs truncate text-xs text-slate-500" :title="detail(doc)">{{ detail(doc) }}</p>
                        </td>
                        <td class="hidden px-4 py-3 text-right text-slate-500 sm:table-cell">{{ ago(doc.created_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="pagination.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-slate-500">
            <span>Página {{ pagination.current_page }} de {{ pagination.last_page }} · {{ pagination.total }} facturas</span>
            <div class="flex gap-2">
                <button type="button" class="btn btn-secondary" :disabled="page <= 1" @click="filter({ pagina: page - 1 })">Anterior</button>
                <button type="button" class="btn btn-secondary" :disabled="page >= pagination.last_page" @click="filter({ pagina: page + 1 })">Siguiente</button>
            </div>
        </div>
    </template>
</template>

<style scoped>
@reference '../../../css/app.css';

.tab {
    @apply inline-flex shrink-0 items-center gap-1.5 rounded-md px-3 py-1.5 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800;
}
.tab-active {
    @apply bg-white text-slate-900 shadow-sm ring-1 ring-slate-200 hover:bg-white dark:bg-slate-800 dark:text-white dark:ring-slate-700;
}
.tab-count {
    @apply rounded-full bg-slate-200 px-1.5 text-xs tabular-nums text-slate-600 dark:bg-slate-700 dark:text-slate-300;
}
</style>
