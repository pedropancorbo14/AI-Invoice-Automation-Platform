<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import { api } from '../api.js';

const boot = inject('boot');
const isAdmin = computed(() => boot.user.role === 'admin');

const companies = ref([]);
const error = ref(null);
const message = ref(null);
const busy = ref(false);

const now = new Date();
const mode = ref('month');
const month = ref(new Date(now.getFullYear(), now.getMonth() - 1, 1).toISOString().slice(0, 7));
const year = ref(now.getFullYear());
const quarter = ref(Math.floor(now.getMonth() / 3) || 4);
const format = ref('xlsx');
const email = ref('');
const keys = ref({});

const period = computed(() => (mode.value === 'month' ? month.value : `${year.value}-T${quarter.value}`));
const downloadUrl = (company) => `/api/companies/${company.id}/export?period=${period.value}&format=${format.value}`;

async function load() {
    companies.value = (await api('companies')).data;
}

async function run(action, success) {
    busy.value = true;
    error.value = null;
    message.value = null;
    try {
        const result = await action();
        message.value = success(result);
    } catch (e) {
        error.value = e.body?.errors ? Object.values(e.body.errors).flat()[0] : e.message;
    } finally {
        busy.value = false;
    }
}

function send(company) {
    return run(
        () => api(`companies/${company.id}/export/email`, { method: 'POST', body: { period: period.value, format: format.value, email: email.value || null } }),
        (r) => `Enviado a ${r.data.sent_to}: ${r.data.filename} (${r.data.rows} líneas).`,
    );
}

function connect(company) {
    return run(async () => {
        await api(`companies/${company.id}/holded`, { method: 'PUT', body: { api_key: keys.value[company.id] } });
        keys.value[company.id] = '';
        await load();
    }, () => `${company.name} conectada con Holded: las facturas recibidas se exportarán solas al validarse.`);
}

function disconnect(company) {
    return run(async () => {
        await api(`companies/${company.id}/holded`, { method: 'DELETE' });
        await load();
    }, () => `${company.name} desconectada de Holded.`);
}

onMounted(() => load().catch((e) => (error.value = e.message)));
</script>

<template>
    <div class="mb-5">
        <h1 class="text-xl font-semibold">Exportar</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">
            Las facturas validadas van solas a Holded si el cliente está conectado. Aquí también puedes sacar el libro de facturas en Excel o CSV.
        </p>
    </div>

    <div class="card mb-4 flex flex-wrap items-end gap-4 p-4">
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

    <p v-if="message" class="card mb-4 border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200">{{ message }}</p>
    <p v-if="error" class="card mb-4 p-3 text-sm text-red-600">{{ error }}</p>

    <div class="card divide-y divide-slate-100 dark:divide-slate-800">
        <div v-for="company in companies" :key="company.id" class="flex flex-wrap items-center gap-4 p-4">
            <div class="min-w-48 flex-1">
                <div class="font-medium">{{ company.name }}</div>
                <div class="text-xs text-slate-500">{{ company.tax_id }}</div>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span v-if="company.holded_connected" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200">Holded conectado</span>
                <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">Sin Holded</span>

                <template v-if="isAdmin">
                    <button v-if="company.holded_connected" type="button" class="text-xs text-slate-500 hover:text-red-600" :disabled="busy" @click="disconnect(company)">Desconectar</button>
                    <form v-else class="flex gap-2" @submit.prevent="connect(company)">
                        <input v-model="keys[company.id]" type="password" autocomplete="off" class="input w-48" placeholder="API key de Holded" :aria-label="`API key de Holded de ${company.name}`" />
                        <button type="submit" class="btn btn-secondary" :disabled="busy || !keys[company.id]">Conectar</button>
                    </form>
                </template>
            </div>

            <div class="flex gap-2">
                <a :href="downloadUrl(company)" class="btn btn-secondary">Descargar</a>
                <button type="button" class="btn btn-primary" :disabled="busy" @click="send(company)">Enviar por email</button>
            </div>
        </div>
        <p v-if="!companies.length" class="p-6 text-center text-sm text-slate-500">Todavía no hay clientes.</p>
    </div>
</template>
