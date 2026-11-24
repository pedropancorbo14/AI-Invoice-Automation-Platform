<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useSingleCompany } from '../account.js';
import { api, ApiError, download } from '../api.js';
import { checkTotal, expectedTax, formatCents, toPayload } from '../invoice.js';
import { dateTime } from '../format.js';
import { refreshCounts } from '../polling.js';
import { canReprocess, describeEvent } from '../status.js';
import { errorMessage, toast, track } from '../toast.js';
import DocumentViewer from '../components/DocumentViewer.vue';
import Icon from '../components/Icon.vue';
import StatusBadge from '../components/StatusBadge.vue';

const props = defineProps({ id: { type: Number, required: true } });
const router = useRouter();
const single = useSingleCompany();

const doc = ref(null);
const form = ref(null);
const supplierMemory = ref({ ledger_account: '', category: '' });
const companies = ref([]);
const companyId = ref('');
const queue = ref([]);
const busy = ref(false);
const busyAction = ref(null);
const error = ref(null);
const warnings = ref([]);
const done = ref(null);
const formEl = ref(null);

const emptyData = () => ({
    document_type: 'invoice',
    supplier: { name: '', tax_id: '' },
    customer: { name: '', tax_id: '' },
    invoice_number: '',
    issue_date: '',
    due_date: '',
    currency: 'EUR',
    tax_breakdown: [{ rate: 21, base: '', tax_amount: '', surcharge_rate: null, surcharge_amount: null }],
    withholding: { rate: null, amount: null },
    total: '',
    lines: [],
});

// Último paso a revisión: trae los fallos de las reglas y los enlaces (factura por enlace).
const reviewEvent = computed(() => [...(doc.value?.events ?? [])].reverse().find((e) => e.to_status === 'needs_review'));
const failures = computed(() => reviewEvent.value?.payload?.failures ?? []);
const links = computed(() => reviewEvent.value?.payload?.links ?? []);
const notes = computed(() => doc.value?.extraction?.notes ?? []);

// Campos a resaltar: los que la IA no tiene claros y los que fallan en alguna regla.
const doubtful = computed(() => new Set([
    ...(doc.value?.extraction?.doubtful_fields ?? []),
    ...failures.value.map((f) => f.field).filter(Boolean),
]));
const isDoubtful = (field) => doubtful.value.has(field);
// Nota de la IA y fallos de reglas del campo (el de "dato dudoso" ya repite la nota).
const noteFor = (field) => [
    ...notes.value.filter((n) => n.field === field).map((n) => n.note.replace(/\.?$/, '.')),
    // Bajo cada campo, solo los errores de ese dato: los avisos generales (proveedor nuevo,
    // posible duplicado) ya están arriba y la duda de la IA repite su propia nota.
    ...failures.value.filter((f) => f.field === field && !['confidence', 'new_supplier', 'duplicate'].includes(f.rule)).map((f) => f.message),
].join(' ');

const totals = computed(() => (form.value ? checkTotal(form.value) : null));
const position = computed(() => queue.value.indexOf(props.id));
const nextId = computed(() => queue.value.find((id) => id !== props.id && queue.value.indexOf(id) > position.value) ?? queue.value.find((id) => id !== props.id));
const needsFile = computed(() => doc.value?.mime_type === 'message/rfc822');
const pending = computed(() => doc.value?.status === 'needs_review');
const EXPORT_LABELS = { exported: 'Exportada', skipped: 'No aplica', failed: 'Error', pending: 'Pendiente' };
const APPROVABLE_TYPES = ['invoice', 'simplified_invoice', 'credit_note'];
const TYPE_LABELS = { delivery_note: 'Albarán: elige el tipo…', quote: 'Presupuesto: elige el tipo…', other: 'Otro: elige el tipo…' };

// "Volver" lleva a donde se estaba (Pendientes o Facturas con sus filtros), no siempre a Pendientes.
const cameFromPanel = typeof window.history.state?.back === 'string';
const backLabel = computed(() => (cameFromPanel && !window.history.state.back.startsWith('/revisar') ? 'Volver' : 'Pendientes'));

function goBack() {
    if (cameFromPanel) router.back();
    else router.push({ name: 'inbox' });
}

async function load() {
    error.value = null;
    warnings.value = [];
    done.value = null;
    doc.value = null;

    const [response, companiesResponse, queueResponse] = await Promise.all([
        api(`documents/${props.id}`),
        companies.value.length ? Promise.resolve({ data: companies.value }) : api('companies'),
        api('documents', { query: { status: 'needs_review' } }),
    ]);

    doc.value = response.data;
    companies.value = companiesResponse.data;
    queue.value = queueResponse.data.map((d) => d.id);
    // Empresa única: si quedó algo sin asignar (de antes del modo), es de su empresa.
    companyId.value = doc.value.company?.id ?? (single ? companies.value[0]?.id ?? '' : '');

    // Copia profunda (structuredClone no admite los proxies reactivos de Vue).
    const data = doc.value.extraction?.data ? JSON.parse(JSON.stringify(doc.value.extraction.data)) : emptyData();
    data.supplier ??= { name: '', tax_id: '' };
    data.customer ??= { name: '', tax_id: '' };
    data.withholding ??= { rate: null, amount: null };
    data.tax_breakdown = (data.tax_breakdown ?? []).map((row) => ({ ...row, rate: row.rate === null ? null : Number(row.rate) }));
    form.value = data;
    supplierMemory.value = {
        ledger_account: doc.value.supplier?.ledger_account ?? '',
        category: doc.value.supplier?.category ?? '',
    };

    // El cursor empieza en el primer dato dudoso: suele ser lo único que hay que tocar.
    await nextTick();
    (formEl.value?.querySelector('.input-doubtful') ?? formEl.value?.querySelector('input'))?.focus();
}

function addRow() {
    form.value.tax_breakdown.push({ rate: 21, base: '', tax_amount: '', surcharge_rate: null, surcharge_amount: null });
}

function suggestTax(row) {
    if (row.tax_amount === '' || row.tax_amount === null) {
        const value = expectedTax(row);
        if (value !== null) row.tax_amount = (value / 100).toFixed(2);
    }
}

async function afterAction(message) {
    done.value = message;
    const next = nextId.value;
    refreshCounts();
    toast(next ? `${message}. Vamos con la siguiente.` : `${message}. ¡No queda nada pendiente!`);
    setTimeout(() => router.push(next ? { name: 'review', params: { id: next } } : { name: 'inbox' }), 700);
}

function reprocess() {
    if (busy.value) return;
    return run('reprocess', async () => {
        await track(() => api(`documents/${props.id}/reprocess`, { method: 'POST' }), {
            loading: 'Mandándola de nuevo a la IA…',
            success: 'Vuelve a la IA: en unos segundos tendrá su estado nuevo.',
        });
        refreshCounts();
        await load();
    });
}

function downloadFile() {
    return track(() => download(`documents/${props.id}/file`, { download: 1 }), {
        loading: `Descargando ${doc.value.original_filename}…`,
        success: (filename) => `Descargado: ${filename}`,
        error: 'No se pudo descargar el documento',
    }).catch(() => {});
}

async function run(name, action) {
    busy.value = true;
    busyAction.value = name;
    error.value = null;
    try {
        await action();
    } catch (e) {
        if (e instanceof ApiError && e.body?.needs_confirmation) {
            warnings.value = e.body.warnings;
            toast('Antes de aprobar, revisa los avisos', { type: 'info', detail: 'Si está bien así, pulsa «Aprobar igualmente».' });
        } else {
            error.value = errorMessage(e);
            // track() ya ha enseñado su propio aviso; el resto de errores, aquí.
            if (name !== 'reprocess') toast(error.value, { type: 'error' });
        }
    } finally {
        busy.value = false;
        busyAction.value = null;
    }
}

function approve(force = false) {
    if (!pending.value || busy.value) return;

    return run('approve', async () => {
        if (!doc.value.company && companyId.value) {
            await api(`documents/${props.id}/company`, { method: 'PUT', body: { company_id: Number(companyId.value) } });
        }
        await api(`documents/${props.id}/approve`, {
            method: 'POST',
            body: { data: toPayload(form.value), supplier: supplierMemory.value, force },
        });
        await afterAction('Aprobada');
    });
}

function notInvoice() {
    if (!pending.value || busy.value) return;
    return run('not-invoice', async () => {
        await api(`documents/${props.id}/not-invoice`, { method: 'POST' });
        await afterAction('Marcado como no factura');
    });
}

function duplicate() {
    if (!pending.value || busy.value) return;
    const original = failures.value.find((f) => f.rule === 'duplicate')?.message.match(/#(\d+)/)?.[1];
    return run('duplicate', async () => {
        await api(`documents/${props.id}/duplicate`, { method: 'POST', body: { of_document_id: original ? Number(original) : null } });
        await afterAction('Marcado como duplicado');
    });
}

function attach(file) {
    const body = new FormData();
    body.append('file', file);
    return run('attach', async () => {
        await track(() => api(`documents/${props.id}/file`, { method: 'POST', body }), {
            loading: `Subiendo ${file.name}…`,
            success: 'Fichero adjuntado',
            error: 'No se pudo adjuntar el fichero',
        });
        await afterAction('Fichero adjuntado: la IA lo procesa de nuevo');
    });
}

function onKey(event) {
    const mod = event.metaKey || event.ctrlKey;
    if (mod && event.key === 'Enter') {
        event.preventDefault();
        approve(warnings.value.length > 0);
    } else if (event.altKey && event.key.toLowerCase() === 'n') {
        event.preventDefault();
        notInvoice();
    } else if (event.altKey && event.key.toLowerCase() === 'd') {
        event.preventDefault();
        duplicate();
    } else if (event.altKey && event.key === 'ArrowRight' && nextId.value) {
        event.preventDefault();
        router.push({ name: 'review', params: { id: nextId.value } });
    } else if (event.key === 'Escape' && !event.defaultPrevented) {
        // Esc dentro de un campo solo lo suelta; fuera, vuelve a la lista.
        if (document.activeElement instanceof HTMLElement && document.activeElement !== document.body) {
            document.activeElement.blur();
        } else {
            goBack();
        }
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKey);
    load().catch((e) => (error.value = e.message));
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
watch(() => props.id, () => load().catch((e) => (error.value = e.message)));
</script>

<template>
    <div v-if="!doc" class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_480px]" aria-busy="true">
        <div v-if="error" class="card flex items-center gap-2 p-6 text-sm text-red-600 lg:col-span-2"><Icon name="alert" /> {{ error }}</div>
        <template v-else>
            <div class="card skeleton min-h-[70vh] !rounded-2xl" />
            <div class="space-y-4">
                <div class="card space-y-3 p-4"><div class="skeleton h-4 w-1/3" /><div class="skeleton h-3 w-full" /><div class="skeleton h-3 w-2/3" /></div>
                <div class="card space-y-4 p-4"><div v-for="i in 6" :key="i" class="skeleton h-9" /></div>
            </div>
        </template>
    </div>

    <div v-else class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_480px]">
        <!-- Documento -->
        <section class="card flex min-h-[70vh] flex-col overflow-hidden lg:sticky lg:top-20 lg:h-[calc(100vh-7rem)]">
            <div class="flex items-center gap-3 border-b border-slate-200 px-3 py-2 text-sm dark:border-slate-800">
                <button type="button" class="btn btn-ghost btn-sm" title="Volver (Esc)" @click="goBack"><Icon name="arrow-left" :size="14" /> {{ backLabel }}</button>
                <span class="truncate font-medium" :title="doc.original_filename">{{ doc.original_filename }}</span>
                <span v-if="pending && position >= 0" class="ml-auto shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 tabular-nums dark:bg-slate-800 dark:text-slate-300">{{ position + 1 }} de {{ queue.length }}</span>
                <span v-else class="ml-auto" />
                <a v-if="!needsFile" :href="`/api/documents/${doc.id}/file`" target="_blank" rel="noopener" class="btn btn-ghost btn-sm !px-1.5" title="Abrir en una pestaña nueva"><Icon name="external" :size="15" /></a>
                <button type="button" class="btn btn-ghost btn-sm !px-1.5" title="Descargar" @click="downloadFile"><Icon name="download" :size="15" /></button>
            </div>
            <DocumentViewer :document="doc" :links="links" :needs-file="needsFile" :busy="busy" class="flex-1" @attach="attach" />
        </section>

        <!-- Datos -->
        <section ref="formEl" class="space-y-4">
            <p v-if="error && !form" class="card flex items-center gap-2 p-4 text-sm text-red-600"><Icon name="alert" /> {{ error }}</p>

            <Transition name="list">
                <div v-if="done" class="card flex items-center gap-2 border-emerald-300 bg-emerald-50 p-4 text-sm font-medium text-emerald-800 dark:border-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-200">
                    <Icon name="check-circle" :size="18" class="animate-pop" /> {{ done }}
                </div>
            </Transition>

            <div class="card p-4">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <StatusBadge :status="doc.status" :label="doc.status_label" />
                    <span class="text-xs text-slate-500 tabular-nums">#{{ doc.id }}</span>
                    <span v-if="doc.company && !single" class="ml-auto inline-flex items-center gap-1 text-xs text-slate-500"><Icon name="building" :size="13" /> {{ doc.company.name }}</span>
                </div>
                <div v-if="doc.review_reasons.length" class="mb-2 flex flex-wrap gap-1">
                    <span v-for="r in doc.review_reasons" :key="r.code" class="chip">{{ r.label }}</span>
                </div>
                <p v-if="doc.status_reason" class="text-sm text-slate-700 dark:text-slate-300">{{ doc.status_reason }}</p>
                <div v-if="canReprocess(doc)" class="mt-3 flex flex-wrap items-center gap-3 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800/50">
                    <p class="flex-1 text-slate-600 dark:text-slate-300">¿La IA se equivocó o hubo un error? Puedes pedirle que la lea otra vez.</p>
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="busy" :aria-busy="busyAction === 'reprocess'" @click="reprocess">
                        <Icon :name="busyAction === 'reprocess' ? 'loader' : 'refresh'" :size="14" :class="{ 'animate-spin': busyAction === 'reprocess' }" />
                        {{ busyAction === 'reprocess' ? 'Enviando…' : 'Volver a procesar' }}
                    </button>
                </div>
            </div>

            <p v-if="form && !needsFile && !pending && !done" class="flex items-center gap-1.5 px-1 text-xs text-slate-500">
                <Icon name="alert" :size="13" /> Solo lectura: esta factura ya no está pendiente de revisar.
            </p>
            <form v-if="form && !needsFile" class="card space-y-4 p-4" :class="{ 'opacity-75': !pending }" :inert="!pending || undefined" @submit.prevent="approve(false)">
                <div v-if="!doc.company && !(single && companyId)" class="rounded-xl bg-brand-50 p-3 dark:bg-brand-950/40">
                    <label class="label" for="company">Cliente (sin asignar)</label>
                    <select id="company" v-model="companyId" class="input">
                        <option value="">Elige el cliente…</option>
                        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }} · {{ c.tax_id }}</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2">
                        <label class="label" for="supplier_name">Proveedor</label>
                        <input id="supplier_name" v-model="form.supplier.name" class="input" :class="{ 'input-doubtful': isDoubtful('supplier_name') }" />
                    </div>
                    <div>
                        <label class="label" for="supplier_tax_id">CIF del proveedor</label>
                        <input id="supplier_tax_id" v-model="form.supplier.tax_id" class="input" :class="{ 'input-doubtful': isDoubtful('supplier_tax_id') }" />
                        <p v-if="noteFor('supplier_tax_id')" class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ noteFor('supplier_tax_id') }}</p>
                    </div>
                    <div>
                        <label class="label" for="customer_tax_id">CIF del cliente</label>
                        <input id="customer_tax_id" v-model="form.customer.tax_id" class="input" :class="{ 'input-doubtful': isDoubtful('customer_tax_id') }" />
                        <p v-if="noteFor('customer_tax_id')" class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ noteFor('customer_tax_id') }}</p>
                    </div>
                    <div>
                        <label class="label" for="invoice_number">Número</label>
                        <input id="invoice_number" v-model="form.invoice_number" class="input" :class="{ 'input-doubtful': isDoubtful('invoice_number') }" />
                    </div>
                    <div>
                        <label class="label" for="document_type">Tipo</label>
                        <select id="document_type" v-model="form.document_type" class="input" :class="{ 'input-doubtful': !APPROVABLE_TYPES.includes(form.document_type) }">
                            <!-- Un albarán o presupuesto no se puede aprobar como tal: hay que elegir qué es. -->
                            <option v-if="!APPROVABLE_TYPES.includes(form.document_type)" :value="form.document_type" disabled>
                                {{ TYPE_LABELS[form.document_type] ?? 'Elige el tipo…' }}
                            </option>
                            <option value="invoice">Factura</option>
                            <option value="simplified_invoice">Ticket / simplificada</option>
                            <option value="credit_note">Rectificativa</option>
                        </select>
                    </div>
                    <div>
                        <label class="label" for="issue_date">Fecha</label>
                        <input id="issue_date" v-model="form.issue_date" type="date" class="input" :class="{ 'input-doubtful': isDoubtful('issue_date') }" />
                        <p v-if="noteFor('issue_date')" class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ noteFor('issue_date') }}</p>
                    </div>
                    <div>
                        <label class="label" for="due_date">Vencimiento</label>
                        <input id="due_date" v-model="form.due_date" type="date" class="input" :class="{ 'input-doubtful': isDoubtful('due_date') }" />
                    </div>
                </div>

                <div>
                    <div class="mb-1 flex items-center">
                        <span class="label mb-0">Desglose de IVA</span>
                        <button type="button" class="link ml-auto text-xs" @click="addRow">+ Añadir tipo</button>
                    </div>
                    <div class="space-y-2 rounded-md p-2" :class="isDoubtful('tax_breakdown') ? 'bg-amber-50 ring-1 ring-amber-300 dark:bg-amber-950/30 dark:ring-amber-600' : 'bg-slate-50 dark:bg-slate-800/40'">
                        <div class="grid grid-cols-[70px_1fr_1fr_24px] gap-2 text-xs text-slate-500">
                            <span>% IVA</span><span>Base</span><span>Cuota</span><span></span>
                        </div>
                        <div v-for="(row, i) in form.tax_breakdown" :key="i" class="grid grid-cols-[70px_1fr_1fr_24px] items-center gap-2">
                            <select v-model="row.rate" class="input" :aria-label="`Tipo de IVA ${i + 1}`">
                                <option v-for="rate in [21, 10, 4, 0]" :key="rate" :value="rate">{{ rate }}</option>
                                <option v-if="![21, 10, 4, 0].includes(Number(row.rate))" :value="row.rate">{{ row.rate }}</option>
                            </select>
                            <input v-model="row.base" inputmode="decimal" class="input text-right" :aria-label="`Base ${i + 1}`" @blur="suggestTax(row)" />
                            <input v-model="row.tax_amount" inputmode="decimal" class="input text-right" :aria-label="`Cuota ${i + 1}`" />
                            <button type="button" class="text-slate-400 hover:text-red-600" title="Quitar" @click="form.tax_breakdown.splice(i, 1)">×</button>
                        </div>
                        <p v-if="noteFor('tax_breakdown')" class="text-xs text-amber-700 dark:text-amber-300">{{ noteFor('tax_breakdown') }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="withholding">Retención IRPF</label>
                        <input id="withholding" v-model="form.withholding.amount" inputmode="decimal" class="input text-right" :class="{ 'input-doubtful': isDoubtful('withholding') }" placeholder="0,00" />
                    </div>
                    <div>
                        <label class="label" for="total">Total</label>
                        <input id="total" v-model="form.total" inputmode="decimal" class="input text-right font-semibold" :class="{ 'input-doubtful': isDoubtful('total') }" />
                        <p v-if="noteFor('total')" class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ noteFor('total') }}</p>
                    </div>
                </div>

                <div v-if="totals" class="rounded-md px-3 py-2 text-sm tabular-nums" :class="totals.ok ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200' : 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-200'">
                    {{ formatCents(totals.bases) }} + {{ formatCents(totals.taxes) }}<template v-if="totals.surcharges"> + {{ formatCents(totals.surcharges) }}</template><template v-if="totals.withholding"> − {{ formatCents(totals.withholding) }}</template>
                    = {{ formatCents(totals.computed) }}
                    <strong>{{ totals.ok ? '✓ cuadra' : `≠ ${formatCents(totals.total)}` }}</strong>
                </div>

                <details class="text-sm" :open="!doc.supplier?.ledger_account">
                    <summary class="cursor-pointer text-slate-500">Memoria del proveedor{{ doc.supplier ? ` (${doc.supplier.validated_count} validadas)` : ' (nuevo)' }}</summary>
                    <div class="mt-2 grid grid-cols-2 gap-3">
                        <div>
                            <label class="label" for="ledger_account">Cuenta contable</label>
                            <input id="ledger_account" v-model="supplierMemory.ledger_account" class="input" placeholder="628000" />
                        </div>
                        <div>
                            <label class="label" for="category">Categoría</label>
                            <input id="category" v-model="supplierMemory.category" class="input" placeholder="Suministros" />
                        </div>
                    </div>
                </details>

                <div v-if="warnings.length" class="animate-slide-up rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-600 dark:bg-amber-950/40">
                    <p class="mb-1 font-medium">Revisa antes de aprobar:</p>
                    <ul class="list-inside list-disc space-y-0.5">
                        <li v-for="w in warnings" :key="w.message">{{ w.message }}</li>
                    </ul>
                </div>
                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                <div v-if="pending" class="sticky bottom-0 -mx-4 -mb-4 flex flex-wrap items-center gap-2 rounded-b-2xl border-t border-slate-100 bg-white/95 px-4 py-3 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
                    <button v-if="!warnings.length" type="submit" class="btn btn-success min-w-32" :disabled="busy" :aria-busy="busyAction === 'approve'">
                        <Icon :name="busyAction === 'approve' ? 'loader' : 'check'" :size="15" :class="{ 'animate-spin': busyAction === 'approve' }" />
                        {{ busyAction === 'approve' ? 'Aprobando…' : 'Aprobar' }}
                        <kbd v-if="busyAction !== 'approve'" class="!border-emerald-400 !bg-emerald-500 !text-emerald-50">Ctrl ↵</kbd>
                    </button>
                    <button v-else type="button" class="btn btn-danger" :disabled="busy" :aria-busy="busyAction === 'approve'" @click="approve(true)">
                        <Icon v-if="busyAction === 'approve'" name="loader" :size="15" class="animate-spin" />
                        {{ busyAction === 'approve' ? 'Aprobando…' : 'Aprobar igualmente' }} <kbd v-if="busyAction !== 'approve'">Ctrl ↵</kbd>
                    </button>
                    <button type="button" class="btn btn-secondary !px-3" :disabled="busy" :aria-busy="busyAction === 'not-invoice'" title="No es factura (Alt N)" @click="notInvoice">
                        <Icon v-if="busyAction === 'not-invoice'" name="loader" :size="14" class="animate-spin" /> No es factura
                    </button>
                    <button type="button" class="btn btn-secondary !px-3" :disabled="busy" :aria-busy="busyAction === 'duplicate'" title="Duplicado (Alt D)" @click="duplicate">
                        <Icon v-if="busyAction === 'duplicate'" name="loader" :size="14" class="animate-spin" /> Duplicado
                    </button>
                    <RouterLink v-if="nextId" :to="{ name: 'review', params: { id: nextId } }" class="btn btn-ghost btn-sm ml-auto" title="Saltar (Alt →)">Saltar <Icon name="arrow-right" :size="14" /></RouterLink>
                </div>
            </form>

            <div v-else-if="needsFile" class="card space-y-3 p-4 text-sm">
                <p>Este correo no traía la factura adjunta. Descárgala del enlace y súbela aquí (o arrástrala sobre el documento).</p>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-secondary" :disabled="busy || !pending" @click="notInvoice">No es factura</button>
                    <button type="button" class="btn btn-secondary" :disabled="busy || !pending" @click="duplicate">Duplicado</button>
                </div>
                <p v-if="error" class="text-red-600">{{ error }}</p>
            </div>

            <div v-if="doc.exports?.length" class="card space-y-2 p-4 text-sm">
                <p class="label">Exportación</p>
                <div v-for="e in doc.exports" :key="e.target" class="flex flex-wrap items-center gap-2">
                    <strong class="capitalize">{{ e.target }}</strong>
                    <StatusBadge :status="e.status === 'exported' ? 'exported' : e.status === 'failed' ? 'failed' : 'ignored'" :label="EXPORT_LABELS[e.status] ?? e.status" />
                    <span v-if="e.external_id" class="text-xs text-slate-500">{{ e.external_id }}</span>
                    <span v-if="e.error" class="w-full text-xs text-slate-500">{{ e.error }}</span>
                </div>
            </div>

            <details class="card group p-4 text-sm">
                <summary class="flex cursor-pointer list-none items-center gap-2 text-slate-500 hover:text-slate-900 dark:hover:text-white">
                    <Icon name="clock" :size="15" /> Historial ({{ doc.events.length }})
                    <Icon name="chevron-right" :size="14" class="ml-auto transition-transform group-open:rotate-90" />
                </summary>
                <ol class="relative mt-4 ml-1.5 space-y-3 border-l border-slate-200 pl-5 dark:border-slate-700">
                    <li v-for="e in doc.events" :key="e.id" class="relative">
                        <span class="absolute top-1.5 -left-[25px] size-2 rounded-full ring-4 ring-white dark:ring-slate-900" :class="e.user_id ? 'bg-brand-500' : 'bg-slate-300 dark:bg-slate-600'" />
                        <p class="font-medium">{{ describeEvent(e) }}</p>
                        <p v-if="e.reason && e.type === 'status_changed'" class="text-xs text-slate-600 dark:text-slate-400">{{ e.reason }}</p>
                        <p class="text-xs text-slate-500" :title="dateTime(e.created_at)">
                            {{ dateTime(e.created_at) }} · {{ e.user_name ?? (e.user_id ? 'una persona' : 'automático') }}
                        </p>
                    </li>
                </ol>
            </details>
        </section>
    </div>
</template>
