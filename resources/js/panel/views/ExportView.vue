<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import { api, download } from '../api.js';
import { confirm } from '../confirm.js';
import { track } from '../toast.js';
import Icon from '../components/Icon.vue';

const boot = inject('boot');
const isAdmin = computed(() => boot.user.role === 'admin');

const companies = ref([]);
const error = ref(null);
// Qué se está haciendo ahora: "download:3", "email:3", "holded:3"... (un botón con su indicador de carga).
const busy = ref(null);

const now = new Date();
const mode = ref('month');
const month = ref(new Date(now.getFullYear(), now.getMonth() - 1, 1).toISOString().slice(0, 7));
const year = ref(now.getFullYear());
const quarter = ref(Math.floor(now.getMonth() / 3) || 4);
const format = ref('xlsx');
const email = ref('');
const keys = ref({});

const period = computed(() => (mode.value === 'month' ? month.value : `${year.value}-T${quarter.value}`));
const periodLabel = computed(() => {
    if (mode.value !== 'month') return `${quarter.value}T ${year.value}`;
    const [y, m] = month.value.split('-').map(Number);

    return new Date(y, m - 1, 1).toLocaleDateString('es-ES', { month: 'long', year: 'numeric' });
});
const formatLabel = computed(() => (format.value === 'xlsx' ? 'Excel' : 'CSV'));
const isBusy = (action, company) => busy.value === `${action}:${company.id}`;

async function load() {
    companies.value = (await api('companies')).data;
}

async function run(key, action, messages) {
    if (busy.value) return;
    busy.value = key;
    try {
        await track(action, messages);
    } catch {
        // El aviso ya enseña el error.
    } finally {
        busy.value = null;
    }
}

function downloadLedger(company) {
    return run(`download:${company.id}`, () => download(`companies/${company.id}/export`, { period: period.value, format: format.value }), {
        loading: `Generando el libro de ${company.name} (${periodLabel.value}, ${formatLabel.value})…`,
        success: (filename) => `Descargado: ${filename}`,
        error: `No se pudo generar el libro de ${company.name}`,
    });
}

function send(company) {
    const to = email.value || 'tu email';

    return run(`email:${company.id}`, () => api(`companies/${company.id}/export/email`, {
        method: 'POST',
        body: { period: period.value, format: format.value, email: email.value || null },
    }), {
        loading: `Enviando el libro de ${company.name} a ${to}…`,
        success: (r) => `Enviado a ${r.data.sent_to}: ${r.data.filename} (${r.data.rows} ${r.data.rows === 1 ? 'línea' : 'líneas'})`,
        error: `No se pudo enviar el libro de ${company.name}`,
    });
}

function connect(company) {
    return run(`holded:${company.id}`, async () => {
        await api(`companies/${company.id}/holded`, { method: 'PUT', body: { api_key: keys.value[company.id] } });
        keys.value[company.id] = '';
        await load();
    }, {
        loading: `Conectando ${company.name} con Holded…`,
        success: `${company.name} conectada con Holded: las facturas recibidas se exportarán solas al validarse.`,
        error: 'No se pudo conectar con Holded',
    });
}

async function disconnect(company) {
    const ok = await confirm({
        title: `¿Desconectar ${company.name} de Holded?`,
        message: 'Las facturas de este cliente dejarán de exportarse solas. Podrás volver a conectarla con su API key.',
        confirm: 'Desconectar',
        danger: true,
    });
    if (!ok) return;

    return run(`holded:${company.id}`, async () => {
        await api(`companies/${company.id}/holded`, { method: 'DELETE' });
        await load();
    }, {
        loading: `Desconectando ${company.name} de Holded…`,
        success: `${company.name} desconectada de Holded.`,
        error: 'No se pudo desconectar de Holded',
    });
}

onMounted(() => load().catch((e) => (error.value = e.message)));
</script>

<template>
    <div class="mb-6">
        <h1 class="page-title">Exportar</h1>
        <p class="page-subtitle">
            Las facturas validadas van solas a Holded si el cliente está conectado. Aquí también puedes sacar el libro de facturas en Excel o CSV.
        </p>
    </div>

    <div class="card mb-4 flex flex-wrap items-end gap-4 p-5">
        <div>
            <span class="label">Periodo</span>
            <div class="flex gap-2">
                <div class="w-32">
                    <select v-model="mode" class="input" aria-label="Tipo de periodo">
                        <option value="month">Mes</option>
                        <option value="quarter">Trimestre</option>
                    </select>
                </div>
                <div v-if="mode === 'month'" class="w-44"><input v-model="month" type="month" class="input" aria-label="Mes" /></div>
                <template v-else>
                    <div class="w-24"><input v-model.number="year" type="number" class="input" aria-label="Año" /></div>
                    <div class="w-24">
                        <select v-model.number="quarter" class="input" aria-label="Trimestre">
                            <option v-for="q in 4" :key="q" :value="q">{{ q }}T</option>
                        </select>
                    </div>
                </template>
            </div>
        </div>
        <div class="w-32">
            <span class="label">Formato</span>
            <select v-model="format" class="input" aria-label="Formato">
                <option value="xlsx">Excel</option>
                <option value="csv">CSV</option>
            </select>
        </div>
        <div class="min-w-56 flex-1">
            <span class="label">Enviar a (opcional)</span>
            <input v-model="email" type="email" class="input" placeholder="Por defecto, a tu email" aria-label="Email" />
        </div>
    </div>

    <p v-if="error" class="card mb-4 flex items-center gap-2 p-4 text-sm text-red-600"><Icon name="alert" /> {{ error }}</p>

    <div class="card divide-y divide-slate-100 dark:divide-slate-800">
        <div v-for="company in companies" :key="company.id" class="flex flex-wrap items-center gap-4 px-5 py-4 transition-colors" :class="{ 'bg-brand-50/50 dark:bg-brand-950/20': busy?.endsWith(`:${company.id}`) }">
            <div class="flex min-w-48 flex-1 items-center gap-3">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400"><Icon name="building" /></span>
                <div>
                    <div class="font-medium">{{ company.name }}</div>
                    <div class="text-xs text-slate-500">{{ company.tax_id }}</div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span v-if="company.holded_connected" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800 ring-1 ring-emerald-200 ring-inset dark:bg-emerald-950/50 dark:text-emerald-200 dark:ring-emerald-800"><span class="size-1.5 rounded-full bg-emerald-500" /> Holded conectado</span>
                <span v-else class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 ring-1 ring-slate-200 ring-inset dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700"><span class="size-1.5 rounded-full bg-slate-400" /> Sin Holded</span>

                <template v-if="isAdmin">
                    <button v-if="company.holded_connected" type="button" class="btn btn-ghost btn-sm hover:!text-red-600" :disabled="!!busy" :aria-busy="isBusy('holded', company)" @click="disconnect(company)">
                        <Icon v-if="isBusy('holded', company)" name="loader" :size="13" class="animate-spin" />
                        {{ isBusy('holded', company) ? 'Desconectando…' : 'Desconectar' }}
                    </button>
                    <form v-else class="flex gap-2" @submit.prevent="connect(company)">
                        <input v-model="keys[company.id]" type="password" autocomplete="off" class="input w-48" placeholder="API key de Holded" :aria-label="`API key de Holded de ${company.name}`" />
                        <button type="submit" class="btn btn-secondary" :disabled="!!busy || !keys[company.id]" :aria-busy="isBusy('holded', company)">
                            <Icon v-if="isBusy('holded', company)" name="loader" class="animate-spin" />
                            {{ isBusy('holded', company) ? 'Conectando…' : 'Conectar' }}
                        </button>
                    </form>
                </template>
            </div>

            <div class="flex gap-2">
                <button type="button" class="btn btn-secondary min-w-32" :disabled="!!busy" :aria-busy="isBusy('download', company)" @click="downloadLedger(company)">
                    <Icon :name="isBusy('download', company) ? 'loader' : 'download'" :class="{ 'animate-spin': isBusy('download', company) }" />
                    {{ isBusy('download', company) ? 'Generando…' : 'Descargar' }}
                </button>
                <button type="button" class="btn btn-primary min-w-40" :disabled="!!busy" :aria-busy="isBusy('email', company)" @click="send(company)">
                    <Icon :name="isBusy('email', company) ? 'loader' : 'mail'" :class="{ 'animate-spin': isBusy('email', company) }" />
                    {{ isBusy('email', company) ? 'Enviando…' : 'Enviar por email' }}
                </button>
            </div>
        </div>
        <p v-if="!companies.length" class="p-8 text-center text-sm text-slate-500">Todavía no hay clientes.</p>
    </div>
</template>
