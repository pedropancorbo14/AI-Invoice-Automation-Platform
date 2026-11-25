<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useSingleCompany } from '../account.js';
import { api, download } from '../api.js';
import { money } from '../format.js';
import { currentQuarter, defaultQuarter, parseQuarter, quarterLabel, shiftQuarter } from '../quarter.js';
import { track } from '../toast.js';
import Icon from '../components/Icon.vue';

const route = useRoute();
const router = useRouter();
const single = useSingleCompany();

const companies = ref([]);
const companyId = ref(Number(route.query.cliente) || null);
const period = ref(parseQuarter(route.query.periodo) ? route.query.periodo : defaultQuarter());
const summary = ref(null);
const loading = ref(true);
const downloading = ref(false);
const error = ref(null);

const company = computed(() => companies.value.find((c) => c.id === companyId.value) ?? null);
// Las claves ("2026-T4") se ordenan bien como texto.
const isLatest = computed(() => period.value >= currentQuarter());
const result = computed(() => summary.value?.result);
const resultLabel = computed(() => ({ to_pay: 'A ingresar', to_offset: 'A compensar o devolver', zero: 'Sin resultado' })[result.value?.kind] ?? '');
const empty = computed(() => summary.value && !summary.value.issued.documents && !summary.value.received.documents);

const rate = (r) => `${r.toLocaleString('es-ES')} %`;
const abs = (amount) => String(amount).replace(/^-/, '');
const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

async function load() {
    if (!companyId.value) {
        loading.value = false;

        return;
    }
    loading.value = true;
    error.value = null;
    try {
        summary.value = (await api(`companies/${companyId.value}/vat-summary`, { query: { period: period.value } })).data;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function downloadLedger() {
    if (downloading.value || !company.value) return;
    downloading.value = true;
    track(() => download(`companies/${company.value.id}/export`, { period: period.value, format: 'xlsx' }), {
        loading: `Generando el libro del ${quarterLabel(period.value)}…`,
        success: (filename) => `Descargado: ${filename}`,
        error: 'No se pudo generar el libro de facturas',
    }).catch(() => {}).finally(() => (downloading.value = false));
}

// El trimestre y el cliente van en la URL: se puede volver o compartir el enlace.
watch([period, companyId], () => {
    router.replace({ query: { ...route.query, periodo: period.value, cliente: single ? undefined : companyId.value ?? undefined } });
    load();
});

onMounted(async () => {
    try {
        companies.value = (await api('companies')).data;
    } catch (e) {
        error.value = e.message;
        loading.value = false;

        return;
    }
    if (!company.value) companyId.value = companies.value[0]?.id ?? null;
    if (companyId.value === null) loading.value = false;
    else await load();
});
</script>

<template>
    <div class="mb-6 flex flex-wrap items-end gap-4">
        <div>
            <h1 class="page-title">Impuestos</h1>
            <p class="page-subtitle">IVA y retenciones del trimestre, a partir de las facturas validadas: la base para los modelos 303, 111 y 130.</p>
        </div>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            <select v-if="!single && companies.length" v-model="companyId" class="input sm:!w-56" aria-label="Cliente">
                <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <div class="flex items-center gap-1">
                <button type="button" class="btn btn-secondary !px-2" aria-label="Trimestre anterior" @click="period = shiftQuarter(period, -1)"><Icon name="chevron-left" /></button>
                <span class="btn btn-secondary pointer-events-none min-w-48" aria-live="polite">{{ quarterLabel(period) }}</span>
                <button type="button" class="btn btn-secondary !px-2" aria-label="Trimestre siguiente" :disabled="isLatest" @click="period = shiftQuarter(period, 1)"><Icon name="chevron-right" /></button>
            </div>
        </div>
    </div>

    <p v-if="error" class="card flex items-center gap-2 p-4 text-sm text-red-600"><Icon name="alert" /> {{ error }}</p>

    <p v-else-if="!loading && !companies.length" class="card p-8 text-center text-sm text-slate-500">
        {{ single ? 'Tu empresa todavía no está dada de alta.' : 'Todavía no hay clientes.' }}
    </p>

    <div v-else-if="loading && !summary" class="grid gap-4 sm:grid-cols-3">
        <div v-for="i in 3" :key="i" class="card space-y-3 p-5"><div class="skeleton h-3 w-1/2" /><div class="skeleton h-8 w-1/3" /></div>
    </div>

    <template v-else-if="summary">
        <div class="space-y-4" :class="{ 'opacity-60': loading }">
            <RouterLink v-if="summary.warnings.pending" to="/revisar" class="card flex items-start gap-3 border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 hover:border-amber-300 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                <Icon name="alert" class="mt-0.5 shrink-0" />
                <span class="flex-1">
                    {{ plural(summary.warnings.pending, 'factura de este trimestre está', 'facturas de este trimestre están') }} sin validar,
                    así que el resumen aún no es definitivo.
                    <span class="font-medium underline">Revisar pendientes →</span>
                </span>
            </RouterLink>
            <p v-if="summary.warnings.other_currency" class="card flex items-start gap-3 p-4 text-sm text-slate-600 dark:text-slate-300">
                <Icon name="info" class="mt-0.5 shrink-0" />
                {{ plural(summary.warnings.other_currency, 'factura en otra moneda no se suma', 'facturas en otra moneda no se suman') }}: hay que pasarlas a euros a mano.
            </p>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="card p-5">
                    <p class="text-sm text-slate-500 dark:text-slate-400">IVA repercutido</p>
                    <p class="mt-2 text-3xl font-semibold tracking-tight tabular-nums">{{ money(summary.issued.tax) }}</p>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ plural(summary.issued.documents, 'factura emitida', 'facturas emitidas') }}
                        <template v-if="Number(summary.issued.surcharge)"> · {{ money(summary.issued.surcharge) }} de recargo</template>
                    </p>
                </div>
                <div class="card p-5">
                    <p class="text-sm text-slate-500 dark:text-slate-400">IVA soportado deducible</p>
                    <p class="mt-2 text-3xl font-semibold tracking-tight tabular-nums">{{ money(summary.received.tax) }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ plural(summary.received.documents, 'factura recibida', 'facturas recibidas') }}</p>
                </div>
                <div class="card p-5" :class="result.kind === 'to_pay' ? 'border-brand-200 dark:border-brand-800' : ''">
                    <p class="text-sm text-slate-500 dark:text-slate-400">Resultado del 303 (estimado)</p>
                    <p class="mt-2 text-3xl font-semibold tracking-tight tabular-nums" :class="{ 'text-brand-700 dark:text-brand-300': result.kind === 'to_pay', 'text-emerald-600 dark:text-emerald-400': result.kind === 'to_offset' }">
                        {{ money(abs(result.amount)) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-500">{{ resultLabel }}</p>
                </div>
            </div>

            <p v-if="empty" class="card p-8 text-center text-sm text-slate-500">No hay facturas validadas con fecha de este trimestre.</p>

            <div v-else class="grid gap-4 lg:grid-cols-2">
                <div v-for="side in [
                    { key: 'issued', title: 'IVA repercutido (ventas)', book: summary.issued, surchargeNote: 'Recargo de equivalencia repercutido: se ingresa con el IVA.' },
                    { key: 'received', title: 'IVA soportado (compras y gastos)', book: summary.received, surchargeNote: 'El recargo de equivalencia soportado no se puede deducir.' },
                ]" :key="side.key" class="card overflow-hidden">
                    <h2 class="px-5 pt-5 pb-3 font-semibold">{{ side.title }}</h2>
                    <p v-if="!side.book.rates.length" class="px-5 pb-5 text-sm text-slate-500">Nada en este trimestre.</p>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm tabular-nums">
                            <thead class="text-xs text-slate-500 dark:text-slate-400">
                                <tr class="border-y border-slate-100 dark:border-slate-800">
                                    <th class="px-5 py-2 text-left font-medium">Tipo</th>
                                    <th class="px-3 py-2 text-right font-medium">Base</th>
                                    <th class="px-3 py-2 text-right font-medium">Cuota</th>
                                    <th v-if="Number(side.book.surcharge)" class="px-5 py-2 text-right font-medium">Recargo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="r in side.book.rates" :key="r.rate">
                                    <td class="px-5 py-2">{{ rate(r.rate) }}</td>
                                    <td class="px-3 py-2 text-right">{{ money(r.base) }}</td>
                                    <td class="px-3 py-2 text-right">{{ money(r.tax) }}</td>
                                    <td v-if="Number(side.book.surcharge)" class="px-5 py-2 text-right">{{ Number(r.surcharge) ? money(r.surcharge) : '—' }}</td>
                                </tr>
                            </tbody>
                            <tfoot class="border-t border-slate-200 font-semibold dark:border-slate-700">
                                <tr>
                                    <td class="px-5 py-2">Total</td>
                                    <td class="px-3 py-2 text-right">{{ money(side.book.base) }}</td>
                                    <td class="px-3 py-2 text-right">{{ money(side.book.tax) }}</td>
                                    <td v-if="Number(side.book.surcharge)" class="px-5 py-2 text-right">{{ money(side.book.surcharge) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p v-if="Number(side.book.surcharge)" class="px-5 pt-3 text-xs text-slate-500">{{ side.surchargeNote }}</p>
                    <p v-if="side.key === 'received' && side.book.tickets" class="px-5 pt-3 text-xs text-slate-500">
                        Incluye {{ plural(side.book.tickets, 'ticket', 'tickets') }}: su IVA solo se puede deducir si llevan el NIF de la empresa.
                    </p>
                    <div class="h-5" />
                </div>
            </div>

            <div class="card p-5">
                <h2 class="mb-4 font-semibold">Retenciones de IRPF</h2>
                <div class="grid gap-4 text-sm sm:grid-cols-2">
                    <div v-for="w in [
                        { key: 'received', title: 'Practicadas a profesionales', model: 'Modelo 111', data: summary.withholdings.received, unit: ['factura recibida', 'facturas recibidas'] },
                        { key: 'issued', title: 'Que te han practicado tus clientes', model: 'Se descuentan en el modelo 130', data: summary.withholdings.issued, unit: ['factura emitida', 'facturas emitidas'] },
                    ]" :key="w.key" class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/50">
                        <p class="text-slate-500 dark:text-slate-400">{{ w.title }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums">{{ money(w.data.amount) }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ w.model }} ·
                            <template v-if="w.data.documents">{{ plural(w.data.documents, ...w.unit) }}, base {{ money(w.data.base) }}</template>
                            <template v-else>ninguna</template>
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <p class="flex flex-1 items-start gap-2 text-xs text-slate-500">
                    <Icon name="info" :size="14" class="mt-px shrink-0" />
                    Orientativo: suma las facturas validadas por su fecha de emisión. No aplica prorrata, regularizaciones,
                    bienes de inversión ni operaciones con inversión del sujeto pasivo; revísalo antes de presentar.
                </p>
                <button type="button" class="btn btn-secondary" :disabled="downloading" :aria-busy="downloading" @click="downloadLedger">
                    <Icon :name="downloading ? 'loader' : 'download'" :class="{ 'animate-spin': downloading }" />
                    {{ downloading ? 'Generando…' : 'Libro del trimestre (Excel)' }}
                </button>
            </div>
        </div>
    </template>
</template>
