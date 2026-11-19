<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, ApiError } from '../api.js';
import { checkTotal, expectedTax, formatCents, toPayload } from '../invoice.js';
import { date } from '../format.js';
import DocumentViewer from '../components/DocumentViewer.vue';

const props = defineProps({ id: { type: Number, required: true } });
const router = useRouter();

const doc = ref(null);
const form = ref(null);
const supplierMemory = ref({ ledger_account: '', category: '' });
const companies = ref([]);
const companyId = ref('');
const queue = ref([]);
const busy = ref(false);
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
    ...failures.value.filter((f) => f.field === field && f.rule !== 'confidence').map((f) => f.message),
].join(' ');

const totals = computed(() => (form.value ? checkTotal(form.value) : null));
const position = computed(() => queue.value.indexOf(props.id));
const nextId = computed(() => queue.value.find((id) => id !== props.id && queue.value.indexOf(id) > position.value) ?? queue.value.find((id) => id !== props.id));
const needsFile = computed(() => doc.value?.mime_type === 'message/rfc822');
const pending = computed(() => doc.value?.status === 'needs_review');

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
    companyId.value = doc.value.company?.id ?? '';

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
    setTimeout(() => router.push(next ? { name: 'review', params: { id: next } } : { name: 'inbox' }), 700);
}

async function run(action) {
    busy.value = true;
    error.value = null;
    try {
        await action();
    } catch (e) {
        if (e instanceof ApiError && e.body?.needs_confirmation) {
            warnings.value = e.body.warnings;
        } else {
            error.value = e.message;
        }
    } finally {
        busy.value = false;
    }
}

function approve(force = false) {
    if (!pending.value || busy.value) return;

    return run(async () => {
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
    return run(async () => {
        await api(`documents/${props.id}/not-invoice`, { method: 'POST' });
        await afterAction('Marcado como no factura');
    });
}

function duplicate() {
    if (!pending.value || busy.value) return;
    const original = failures.value.find((f) => f.rule === 'duplicate')?.message.match(/#(\d+)/)?.[1];
    return run(async () => {
        await api(`documents/${props.id}/duplicate`, { method: 'POST', body: { of_document_id: original ? Number(original) : null } });
        await afterAction('Marcado como duplicado');
    });
}

function attach(file) {
    const body = new FormData();
    body.append('file', file);
    return run(async () => {
        await api(`documents/${props.id}/file`, { method: 'POST', body });
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
    <div v-if="!doc" class="card p-8 text-center text-sm text-slate-500">{{ error ?? 'Cargando…' }}</div>

    <div v-else class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_480px]">
        <!-- Documento -->
        <section class="card flex min-h-[70vh] flex-col overflow-hidden">
            <div class="flex items-center gap-3 border-b border-slate-200 px-4 py-2.5 text-sm dark:border-slate-800">
                <RouterLink to="/revisar" class="text-slate-500 hover:text-slate-900 dark:hover:text-white">← Pendientes</RouterLink>
                <span class="truncate font-medium">{{ doc.original_filename }}</span>
                <span v-if="position >= 0" class="ml-auto text-xs text-slate-500">{{ position + 1 }} de {{ queue.length }}</span>
                <a :href="`/api/documents/${doc.id}/file?download=1`" class="text-xs text-sky-600 hover:underline">Descargar</a>
            </div>
            <DocumentViewer :document="doc" :links="links" :needs-file="needsFile" :busy="busy" class="flex-1" @attach="attach" />
        </section>

        <!-- Datos -->
        <section ref="formEl" class="space-y-4">
            <p v-if="error && !form" class="card p-4 text-sm text-red-600">{{ error }}</p>

            <div v-if="done" class="card border-emerald-300 bg-emerald-50 p-4 text-sm font-medium text-emerald-800 dark:border-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-200">
                ✓ {{ done }}
            </div>

            <div v-else-if="!pending" class="card p-4 text-sm">
                Este documento ya no está pendiente (estado: <strong>{{ doc.status_label }}</strong>).
            </div>

            <div class="card p-4">
                <div class="mb-2 flex flex-wrap gap-1">
                    <span v-for="r in doc.review_reasons" :key="r.code" class="chip">{{ r.label }}</span>
                </div>
                <p class="text-sm text-slate-700 dark:text-slate-300">{{ doc.status_reason }}</p>
            </div>

            <form v-if="form && !needsFile" class="card space-y-4 p-4" @submit.prevent="approve(false)">
                <div v-if="!doc.company" class="rounded-md bg-sky-50 p-3 dark:bg-sky-950/40">
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
                        <select id="document_type" v-model="form.document_type" class="input">
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
                        <button type="button" class="ml-auto text-xs text-sky-600 hover:underline" @click="addRow">+ Añadir tipo</button>
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

                <div v-if="warnings.length" class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-600 dark:bg-amber-950/40">
                    <p class="mb-1 font-medium">Revisa antes de aprobar:</p>
                    <ul class="list-inside list-disc space-y-0.5">
                        <li v-for="w in warnings" :key="w.message">{{ w.message }}</li>
                    </ul>
                </div>
                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                <div class="flex flex-wrap items-center gap-2 pt-1">
                    <button v-if="!warnings.length" type="submit" class="btn btn-primary" :disabled="busy || !pending">
                        Aprobar <kbd class="!border-emerald-400 !bg-emerald-500 !text-emerald-50">Ctrl ↵</kbd>
                    </button>
                    <button v-else type="button" class="btn btn-danger" :disabled="busy || !pending" @click="approve(true)">
                        Aprobar igualmente <kbd>Ctrl ↵</kbd>
                    </button>
                    <button type="button" class="btn btn-secondary" :disabled="busy || !pending" @click="notInvoice">No es factura <kbd>Alt N</kbd></button>
                    <button type="button" class="btn btn-secondary" :disabled="busy || !pending" @click="duplicate">Duplicado <kbd>Alt D</kbd></button>
                    <RouterLink v-if="nextId" :to="{ name: 'review', params: { id: nextId } }" class="ml-auto text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white">Saltar →</RouterLink>
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

            <div v-if="doc.exports?.length" class="card p-4 text-sm">
                <p v-for="e in doc.exports" :key="e.target">
                    <strong class="capitalize">{{ e.target }}</strong>:
                    {{ { exported: 'exportada', skipped: 'no aplica', failed: 'error', pending: 'pendiente' }[e.status] ?? e.status }}
                    <span v-if="e.external_id" class="text-slate-500">({{ e.external_id }})</span>
                    <span v-if="e.error" class="block text-xs text-slate-500">{{ e.error }}</span>
                </p>
            </div>

            <details class="card p-4 text-sm">
                <summary class="cursor-pointer text-slate-500">Historial ({{ doc.events.length }})</summary>
                <ol class="mt-3 space-y-1.5 text-xs text-slate-600 dark:text-slate-400">
                    <li v-for="e in doc.events" :key="e.id">
                        <span class="tabular-nums">{{ date(e.created_at) }} {{ e.created_at.slice(11, 16) }}</span> ·
                        <template v-if="e.type === 'status_changed'">{{ e.from_status }} → <strong>{{ e.to_status }}</strong></template>
                        <template v-else-if="e.type === 'corrected'">corregido {{ e.payload.field }}: {{ e.payload.before }} → {{ e.payload.after }}</template>
                        <template v-else>{{ e.type }}</template>
                        <span v-if="e.user_id"> (persona)</span>
                    </li>
                </ol>
            </details>
        </section>
    </div>
</template>
