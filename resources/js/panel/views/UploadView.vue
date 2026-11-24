<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useSingleCompany } from '../account.js';
import { api } from '../api.js';
import { refreshCounts, usePolling } from '../polling.js';
import { isInProgress } from '../status.js';
import { errorMessage, toast, update } from '../toast.js';
import Icon from '../components/Icon.vue';
import StatusBadge from '../components/StatusBadge.vue';

const BATCH = 20; // Máximo de ficheros por petición en la API.
const ACCEPT = '.pdf,.jpg,.jpeg,.png,.heic,application/pdf,image/jpeg,image/png,image/heic';

const single = useSingleCompany();
const me = ref(null);
const companies = ref([]);
const companyId = ref('');
const over = ref(false);
const uploading = ref(0);
const input = ref(null);
// Lo subido en esta visita, lo último arriba: { key, filename, kind, doc?, documentId?, reason? }
const results = ref([]);

const tracking = computed(() => results.value.some((r) => r.kind === 'created' && isInProgress(r.doc.status)));

async function upload(fileList) {
    const files = [...fileList];
    if (!files.length) return;

    uploading.value = files.length;
    let created = 0;
    let failed = false;
    const notice = toast(`Subiendo ${files.length} ${files.length === 1 ? 'fichero' : 'ficheros'}…`, { type: 'loading' });
    for (let i = 0; i < files.length; i += BATCH) {
        const body = new FormData();
        files.slice(i, i + BATCH).forEach((f) => body.append('files[]', f));
        if (companyId.value) body.append('company_id', companyId.value);

        try {
            const { data } = await api('documents', { method: 'POST', body });
            const rows = [
                ...data.created.map((doc) => ({ key: `d${doc.id}`, filename: doc.original_filename, kind: 'created', doc })),
                ...data.duplicates.map((d) => ({ key: `dup${d.document_id}-${d.filename}`, filename: d.filename, kind: 'duplicate', documentId: d.document_id })),
                ...data.rejected.map((d, n) => ({ key: `r${Date.now()}-${n}`, filename: d.filename, kind: 'rejected', reason: d.reason })),
            ];
            results.value = [...rows, ...results.value];
            created += data.created.length;
        } catch (e) {
            failed = true;
            update(notice, 'No se pudo subir', { type: 'error', detail: errorMessage(e) });
        }
    }
    uploading.value = 0;
    refreshCounts();

    if (failed) return;
    const skipped = files.length - created;
    if (created) {
        update(notice, created === 1 ? 'Recibida: la IA ya la está leyendo' : `${created} recibidas: la IA ya las está leyendo`, {
            detail: skipped ? `${skipped} no se ${skipped === 1 ? 'ha subido' : 'han subido'}: mira el motivo en la lista.` : undefined,
        });
    } else {
        update(notice, 'No se ha subido nada nuevo', { type: 'info', detail: 'Mira el motivo de cada fichero en la lista.' });
    }
}

// Sigue el estado de lo recién subido hasta que la IA termina con cada una.
async function track() {
    const pending = results.value.filter((r) => r.kind === 'created' && isInProgress(r.doc.status));
    await Promise.all(pending.map(async (row) => {
        const before = row.doc.status;
        row.doc = (await api(`documents/${row.doc.id}`, { background: true })).data;
        // Avisa cuando la IA termina con una, aunque se esté mirando otra cosa de la pantalla.
        if (isInProgress(before) && !isInProgress(row.doc.status)) {
            const name = row.doc.extraction?.data?.supplier?.name ?? row.filename;
            if (row.doc.status === 'validated' || row.doc.status === 'exported') toast(`${name}: validada sola`);
            else if (row.doc.status === 'needs_review') toast(`${name}: necesita revisión`, { type: 'info', detail: row.doc.review_reasons.map((r) => r.label).join(' · ') });
            else toast(`${name}: ${row.doc.status_label.toLowerCase()}`, { type: row.doc.status === 'failed' ? 'error' : 'info', detail: row.doc.status_reason });
        }
    }));
}
usePolling(track, () => (tracking.value ? 3000 : 15000));

function pick(event) {
    upload(event.target.files);
    event.target.value = '';
}

function drop(event) {
    over.value = false;
    upload(event.dataTransfer?.files ?? []);
}

// Pegar con Ctrl+V una captura o un fichero copiado también sube.
function onPaste(event) {
    const files = [...(event.clipboardData?.files ?? [])];
    if (files.length) {
        event.preventDefault();
        upload(files);
    }
}

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast('Dirección copiada');
    } catch {
        toast('No se pudo copiar: selecciónala y cópiala a mano.', { type: 'error' });
    }
}

onMounted(async () => {
    window.addEventListener('paste', onPaste);
    const [meResponse, companiesResponse] = await Promise.all([api('me'), api('companies')]);
    me.value = meResponse.data;
    companies.value = companiesResponse.data;
});
onBeforeUnmount(() => window.removeEventListener('paste', onPaste));
</script>

<template>
    <div class="mx-auto max-w-4xl">
        <div class="mb-6">
            <h1 class="page-title">Subir facturas</h1>
            <p class="page-subtitle">Lo normal es que lleguen solas por email; esto es para las que no. Puedes arrastrarlas, elegirlas o pegarlas con <kbd>Ctrl</kbd> <kbd>V</kbd>.</p>
        </div>

        <div class="card p-5">
            <div v-if="!single" class="mb-4 max-w-sm">
                <label class="label" for="company">Cliente</label>
                <select id="company" v-model="companyId" class="input">
                    <option value="">Sin asignar (lo decides después)</option>
                    <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }} · {{ c.tax_id }}</option>
                </select>
            </div>

            <button
                type="button"
                class="flex w-full flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed px-6 py-12 text-center transition-colors"
                :class="over
                    ? 'border-brand-500 bg-brand-50 dark:bg-brand-950/40'
                    : 'border-slate-300 hover:border-brand-400 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/40'"
                :disabled="uploading > 0"
                @click="input.click()"
                @dragenter.prevent="over = true"
                @dragover.prevent="over = true"
                @dragleave.prevent="over = false"
                @drop.prevent="drop"
            >
                <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-950/60 dark:text-brand-300">
                    <Icon :name="uploading ? 'refresh' : 'upload'" :size="22" :class="uploading ? 'animate-spin' : ''" />
                </span>
                <span class="text-base font-semibold">
                    {{ uploading ? `Subiendo ${uploading} ${uploading === 1 ? 'fichero' : 'ficheros'}…` : 'Suelta aquí las facturas' }}
                </span>
                <span class="text-sm text-slate-500 dark:text-slate-400">o haz clic para elegirlas · PDF, JPG, PNG o HEIC · las que quieras a la vez</span>
            </button>
            <input ref="input" type="file" multiple :accept="ACCEPT" class="hidden" @change="pick" />

            <TransitionGroup v-if="results.length" tag="ul" name="list" class="mt-5 divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800" aria-live="polite">
                <li v-for="r in results" :key="r.key" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-lg"
                        :class="{
                            'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-300': r.kind === 'created',
                            'bg-amber-50 text-amber-600 dark:bg-amber-950/60 dark:text-amber-300': r.kind === 'duplicate',
                            'bg-red-50 text-red-600 dark:bg-red-950/60 dark:text-red-300': r.kind === 'rejected',
                        }"
                    >
                        <Icon :name="r.kind === 'created' ? 'files' : r.kind === 'duplicate' ? 'copy' : 'x'" :size="15" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium" :title="r.filename">{{ r.filename }}</p>
                        <p v-if="r.kind === 'created'" class="truncate text-xs text-slate-500">
                            <template v-if="isInProgress(r.doc.status)">La IA la está leyendo…</template>
                            <template v-else>{{ r.doc.extraction?.data?.supplier?.name ?? r.doc.status_reason }}</template>
                        </p>
                        <p v-else-if="r.kind === 'duplicate'" class="text-xs text-slate-500">Ya estaba subida: no se ha duplicado.</p>
                        <p v-else class="text-xs text-red-600 dark:text-red-400">{{ r.reason }}</p>
                    </div>
                    <Transition name="fade" mode="out-in">
                        <StatusBadge v-if="r.kind === 'created'" :key="r.doc.status" :status="r.doc.status" :label="r.doc.status_label" />
                    </Transition>
                    <RouterLink
                        v-if="r.kind !== 'rejected'"
                        :to="{ name: 'review', params: { id: r.kind === 'created' ? r.doc.id : r.documentId } }"
                        class="btn btn-ghost btn-sm"
                    >
                        {{ r.kind === 'created' && r.doc.status === 'needs_review' ? 'Revisar' : 'Ver' }} <Icon name="arrow-right" :size="13" />
                    </RouterLink>
                </li>
            </TransitionGroup>
        </div>

        <div class="mt-8 mb-3">
            <h2 class="text-lg font-semibold tracking-tight">{{ single ? 'Tu dirección de facturas' : 'Direcciones de reenvío' }}</h2>
            <p class="page-subtitle">
                {{ single
                    ? 'Reenvía aquí las facturas que te lleguen por email (o pon una regla en Gmail u Outlook) y entran solas.'
                    : 'Cada cliente reenvía sus facturas a su dirección y entran solas, ya asignadas.' }}
            </p>
        </div>
        <div class="card divide-y divide-slate-100 dark:divide-slate-800">
            <div v-for="c in companies" :key="c.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                <div class="min-w-40 flex-1">
                    <p class="font-medium">{{ c.name }}</p>
                    <p class="text-xs text-slate-500">{{ c.tax_id }}</p>
                </div>
                <code class="rounded-lg bg-slate-100 px-2.5 py-1 font-mono text-xs break-all dark:bg-slate-800">{{ c.inbox_address }}</code>
                <button type="button" class="btn btn-ghost btn-sm" title="Copiar" @click="copy(c.inbox_address)"><Icon name="copy" :size="14" /></button>
            </div>
            <div v-if="me && !single" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                <div class="min-w-40 flex-1">
                    <p class="font-medium">Sin cliente concreto</p>
                    <p class="text-xs text-slate-500">Van a "Sin asignar" y eliges el cliente al revisar</p>
                </div>
                <code class="rounded-lg bg-slate-100 px-2.5 py-1 font-mono text-xs break-all dark:bg-slate-800">{{ me.account.inbox_address }}</code>
                <button type="button" class="btn btn-ghost btn-sm" title="Copiar" @click="copy(me.account.inbox_address)"><Icon name="copy" :size="14" /></button>
            </div>
        </div>
    </div>
</template>
