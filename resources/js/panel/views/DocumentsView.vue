<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api.js';
import { ago, date, dateTime, money } from '../format.js';
import { refreshCounts, usePolling } from '../polling.js';
import { canReprocess, isInProgress } from '../status.js';
import { track } from '../toast.js';
import EmptyState from '../components/EmptyState.vue';
import Icon from '../components/Icon.vue';
import StatusBadge from '../components/StatusBadge.vue';
import TableSkeleton from '../components/TableSkeleton.vue';

const route = useRoute();
const router = useRouter();
const documents = ref([]);
const companies = ref([]);
const counts = ref({});
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const error = ref(null);
const busyId = ref(null);
const refreshing = ref(false);
const searchInput = ref(null);

// Filtros en la URL para poder volver atrás o compartir el enlace.
const status = computed(() => route.query.estado ?? '');
const companyId = computed(() => route.query.cliente ?? '');
const page = computed(() => Number(route.query.pagina ?? 1));
const search = ref(route.query.buscar ?? '');

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

const total = computed(() => Object.values(counts.value).reduce((sum, n) => sum + n, 0));
// Las pestañas sin documentos no se muestran (salvo la activa), para no llenar la barra de ceros.
const tabs = computed(() => STATUSES.filter((s) => (counts.value[s.value] ?? 0) > 0 || s.value === status.value));
const filtered = computed(() => status.value !== '' || companyId.value !== '' || (route.query.buscar ?? '') !== '');
const processing = computed(() => documents.value.some((d) => isInProgress(d.status)));

async function load({ silent = false } = {}) {
    if (!silent) loading.value = true;
    error.value = null;
    try {
        if (!silent && documents.value.length) refreshing.value = true;
        const response = await api('documents', {
            background: silent,
            query: {
                status: status.value,
                company_id: companyId.value === 'sin' ? '' : companyId.value,
                unassigned: companyId.value === 'sin' ? 1 : '',
                q: route.query.buscar ?? '',
                page: page.value,
            },
        });
        documents.value = response.data;
        counts.value = response.meta.status_counts ?? {};
        pagination.value = response.meta;
    } catch (e) {
        if (!silent) error.value = e.message;
    } finally {
        loading.value = false;
        refreshing.value = false;
    }
}

function filter(changes) {
    const query = { ...route.query, ...changes };
    if (!('pagina' in changes)) delete query.pagina;
    delete query.foco;
    Object.keys(query).forEach((key) => (query[key] === '' || query[key] === 1) && delete query[key]);
    router.push({ query });
}

// Busca mientras se escribe, sin lanzar una petición por tecla.
let debounce = null;
watch(search, (value) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => filter({ buscar: value.trim() }), 300);
});

function clearFilters() {
    search.value = '';
    router.push({ query: {} });
}

function open(doc) {
    router.push({ name: 'review', params: { id: doc.id } });
}

function detail(doc) {
    if (doc.review_reasons.length > 0) return null;
    if (['failed', 'ignored', 'needs_review'].includes(doc.status)) return doc.status_reason;
    if (isInProgress(doc.status)) return 'La IA la está leyendo…';

    return null;
}

async function reprocess(doc) {
    busyId.value = doc.id;
    try {
        await track(() => api(`documents/${doc.id}/reprocess`, { method: 'POST' }), {
            loading: `Mandando #${doc.id} de nuevo a la IA…`,
            success: `#${doc.id} vuelve a la IA: en unos segundos tendrá su estado nuevo.`,
        });
        await load({ silent: true });
        refreshCounts();
    } catch {
        // El aviso ya enseña el error.
    } finally {
        busyId.value = null;
    }
}

// Mientras la IA procesa algo de la lista, cada 4 s; si no, cada 30 s.
usePolling(load, () => (processing.value ? 4000 : 30000));

onMounted(async () => {
    companies.value = (await api('companies')).data;
    await load();
    if (route.query.foco === 'buscar') searchInput.value?.focus();
});

// Solo al cambiar los filtros de esta vista, no al salir de ella.
watch(() => route.query, async (query) => {
    if (route.name !== 'documents') return;
    if ((query.buscar ?? '') !== search.value.trim()) search.value = query.buscar ?? '';
    await load();
    if (query.foco === 'buscar') {
        await nextTick();
        searchInput.value?.focus();
    }
});
</script>

<template>
    <div class="mb-6 flex flex-wrap items-end gap-4">
        <div>
            <h1 class="page-title">Facturas</h1>
            <p class="page-subtitle">Todo lo recibido, en cualquier estado. Lo último primero.</p>
        </div>
        <div class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
            <div class="relative w-full sm:w-64">
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><Icon name="search" /></span>
                <input
                    id="search"
                    ref="searchInput"
                    v-model="search"
                    type="search"
                    class="input !pl-9"
                    placeholder="Proveedor, número, CIF, #id…"
                    aria-label="Buscar facturas"
                    @keydown.esc="search = ''; $event.target.blur()"
                />
                <kbd v-if="!search" class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2">/</kbd>
            </div>
            <select :value="companyId" class="input sm:!w-52" aria-label="Cliente" @change="filter({ cliente: $event.target.value })">
                <option value="">Todos los clientes</option>
                <option value="sin">Sin asignar</option>
                <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
        </div>
    </div>

    <nav class="mb-4 flex gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1 text-sm dark:bg-slate-800/60" aria-label="Estado">
        <button
            v-for="s in [{ value: '', label: 'Todas' }, ...tabs]"
            :key="s.value"
            type="button"
            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 font-medium transition-colors"
            :class="status === s.value
                ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white'
                : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
            :aria-pressed="status === s.value"
            @click="filter({ estado: s.value })"
        >
            {{ s.label }}
            <span class="rounded-full px-1.5 text-xs tabular-nums" :class="s.value === 'needs_review' && counts.needs_review ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200' : 'bg-slate-200/80 text-slate-600 dark:bg-slate-700 dark:text-slate-300'">
                {{ s.value === '' ? total : counts[s.value] ?? 0 }}
            </span>
        </button>
    </nav>

    <p v-if="error" class="card flex items-center gap-2 p-4 text-sm text-red-600"><Icon name="alert" /> {{ error }}</p>

    <TableSkeleton v-else-if="loading && !refreshing" />

    <EmptyState v-else-if="documents.length === 0 && filtered" icon="search" title="Nada con estos filtros">
        No hay facturas que coincidan. Prueba con otra búsqueda u otro estado.
        <template #actions>
            <button type="button" class="btn btn-secondary" @click="clearFilters">Quitar filtros</button>
        </template>
    </EmptyState>

    <EmptyState v-else-if="documents.length === 0" icon="files" title="Todavía no hay facturas">
        Llegan solas cuando se reenvían por email a la dirección de cada cliente. También puedes subirlas a mano.
        <template #actions>
            <RouterLink to="/subir" class="btn btn-primary"><Icon name="upload" /> Subir facturas</RouterLink>
        </template>
    </EmptyState>

    <template v-else>
        <div class="card overflow-x-auto transition-opacity" :class="{ 'pointer-events-none opacity-60': refreshing }">
            <table class="w-full text-sm">
                <thead class="table-head">
                    <tr>
                        <th class="px-4 py-3">Proveedor</th>
                        <th class="hidden px-4 py-3 md:table-cell">Cliente</th>
                        <th class="hidden px-4 py-3 lg:table-cell">Fecha</th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th class="hidden px-4 py-3 sm:table-cell">Estado</th>
                        <th class="hidden px-4 py-3 text-right sm:table-cell">Recibida</th>
                    </tr>
                </thead>
                <TransitionGroup tag="tbody" name="list" class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="doc in documents" :key="doc.id" class="table-row" tabindex="0" @click="open(doc)" @keydown.enter="open(doc)">
                        <td class="px-4 py-3.5">
                            <div class="font-medium">{{ doc.extraction?.data?.supplier?.name ?? doc.original_filename }}</div>
                            <div class="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500">
                                <Icon :name="doc.source === 'upload' ? 'upload' : 'mail'" :size="12" />
                                <span class="tabular-nums">#{{ doc.id }}</span> · {{ doc.extraction?.data?.invoice_number ?? 'Sin datos' }}
                            </div>
                            <div class="mt-2 sm:hidden"><StatusBadge :status="doc.status" :label="doc.status_label" /></div>
                        </td>
                        <td class="hidden px-4 py-3.5 md:table-cell">
                            <span v-if="doc.company">{{ doc.company.name }}</span>
                            <span v-else class="inline-flex items-center gap-1 text-brand-600 dark:text-brand-400"><Icon name="building" :size="13" /> Sin asignar</span>
                        </td>
                        <td class="hidden px-4 py-3.5 tabular-nums lg:table-cell">{{ date(doc.extraction?.data?.issue_date) }}</td>
                        <td class="px-4 py-3.5 text-right font-medium tabular-nums">{{ money(doc.extraction?.data?.total, doc.extraction?.data?.currency ?? 'EUR') }}</td>
                        <td class="hidden px-4 py-3.5 sm:table-cell">
                            <div class="flex items-center gap-2">
                                <StatusBadge :status="doc.status" :label="doc.status_label" />
                                <button
                                    v-if="canReprocess(doc)"
                                    type="button"
                                    class="btn btn-ghost btn-sm !px-1.5"
                                    :disabled="busyId === doc.id"
                                    title="Volver a procesar con la IA"
                                    @click.stop="reprocess(doc)"
                                >
                                    <Icon name="refresh" :size="14" :class="busyId === doc.id ? 'animate-spin' : ''" />
                                </button>
                            </div>
                            <div v-if="doc.review_reasons.length" class="mt-1.5 flex flex-wrap gap-1">
                                <span v-for="r in doc.review_reasons" :key="r.code" class="chip">{{ r.label }}</span>
                            </div>
                            <p v-else-if="detail(doc)" class="mt-1 max-w-xs truncate text-xs text-slate-500" :title="detail(doc)">{{ detail(doc) }}</p>
                        </td>
                        <td class="hidden px-4 py-3.5 text-right whitespace-nowrap text-slate-500 sm:table-cell" :title="dateTime(doc.created_at)">{{ ago(doc.created_at) }}</td>
                    </tr>
                </TransitionGroup>
            </table>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-500">
            <span>
                {{ pagination.total }} {{ pagination.total === 1 ? 'factura' : 'facturas' }}
                <template v-if="pagination.last_page > 1"> · página {{ pagination.current_page }} de {{ pagination.last_page }}</template>
                <span v-if="processing" class="ml-2 inline-flex items-center gap-1 text-brand-600 dark:text-brand-400"><Icon name="refresh" :size="12" class="animate-spin" /> actualizando</span>
            </span>
            <div v-if="pagination.last_page > 1" class="flex gap-2">
                <button type="button" class="btn btn-secondary btn-sm" :disabled="page <= 1" @click="filter({ pagina: page - 1 })"><Icon name="chevron-left" :size="14" /> Anterior</button>
                <button type="button" class="btn btn-secondary btn-sm" :disabled="page >= pagination.last_page" @click="filter({ pagina: page + 1 })">Siguiente <Icon name="chevron-right" :size="14" /></button>
            </div>
        </div>
    </template>
</template>
