<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api.js';
import { ago, money } from '../format.js';

const router = useRouter();
const documents = ref([]);
const companies = ref([]);
const loading = ref(true);
const error = ref(null);
const companyId = ref('');
const reason = ref('');

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const response = await api('documents', {
            query: { status: 'needs_review', company_id: companyId.value, review_code: reason.value },
        });
        documents.value = response.data;
    } catch (e) {
        error.value = e.message;
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

function open(doc) {
    router.push({ name: 'review', params: { id: doc.id } });
}

onMounted(async () => {
    companies.value = (await api('companies')).data;
    await load();
});

watch([companyId, reason], load);
</script>

<template>
    <div class="mb-5 flex flex-wrap items-end gap-4">
        <div>
            <h1 class="text-xl font-semibold">Pendientes de revisar</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">Lo que el sistema no ha podido validar solo. Por orden de llegada.</p>
        </div>
        <div class="ml-auto flex flex-wrap gap-3">
            <div class="w-56"><select v-model="companyId" class="input" aria-label="Cliente">
                <option value="">Todos los clientes</option>
                <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select></div>
            <div class="w-56"><select v-model="reason" class="input" aria-label="Motivo">
                <option value="">Todos los motivos</option>
                <option v-for="r in reasons" :key="r.code" :value="r.code">{{ r.label }} ({{ r.count }})</option>
                <option v-if="reason && !reasons.some((r) => r.code === reason)" :value="reason">{{ reason }}</option>
            </select></div>
        </div>
    </div>

    <p v-if="error" class="card p-4 text-sm text-red-600">{{ error }}</p>

    <div v-else-if="loading" class="card p-8 text-center text-sm text-slate-500">Cargando…</div>

    <div v-else-if="documents.length === 0" class="card p-12 text-center">
        <p class="text-lg font-medium">Todo al día</p>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">No hay nada pendiente de revisar.</p>
    </div>

    <div v-else class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                <tr>
                    <th class="px-4 py-2.5 font-medium">Proveedor</th>
                    <th class="hidden px-4 py-2.5 font-medium md:table-cell">Cliente</th>
                    <th class="px-4 py-2.5 text-right font-medium">Total</th>
                    <th class="px-4 py-2.5 font-medium">Motivo</th>
                    <th class="hidden px-4 py-2.5 text-right font-medium sm:table-cell">Recibido</th>
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
                        <div class="text-xs text-slate-500">{{ doc.extraction?.data?.invoice_number ?? 'Sin datos' }}</div>
                    </td>
                    <td class="hidden px-4 py-3 md:table-cell">{{ doc.company?.name ?? 'Sin asignar' }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ money(doc.extraction?.data?.total, doc.extraction?.data?.currency ?? 'EUR') }}</td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-1">
                            <span v-for="r in doc.review_reasons" :key="r.code" class="chip">{{ r.label }}</span>
                        </div>
                    </td>
                    <td class="hidden px-4 py-3 text-right text-slate-500 sm:table-cell">{{ ago(doc.created_at) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
