<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    document: { type: Object, required: true },
    links: { type: Array, default: () => [] },
    needsFile: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['attach']);

const url = computed(() => `/api/documents/${props.document.id}/file`);
const isPdf = computed(() => props.document.mime_type === 'application/pdf');
const isImage = computed(() => ['image/jpeg', 'image/png'].includes(props.document.mime_type));
const over = ref(false);
const input = ref(null);

function drop(event) {
    over.value = false;
    const file = event.dataTransfer?.files?.[0];
    if (file) emit('attach', file);
}

function pick(event) {
    const file = event.target.files?.[0];
    if (file) emit('attach', file);
}
</script>

<template>
    <div class="relative bg-slate-100 dark:bg-slate-950">
        <iframe v-if="isPdf" :src="url" title="Documento" class="absolute inset-0 h-full w-full" />

        <div v-else-if="isImage" class="absolute inset-0 overflow-auto p-4">
            <img :src="url" alt="Documento" class="mx-auto max-w-full rounded shadow" />
        </div>

        <div
            v-else-if="needsFile"
            class="absolute inset-0 flex flex-col items-center justify-center gap-4 p-8 text-center"
            :class="over ? 'bg-sky-50 dark:bg-sky-950/40' : ''"
            @dragover.prevent="over = true"
            @dragleave="over = false"
            @drop.prevent="drop"
        >
            <p class="text-lg font-medium">Factura por enlace</p>
            <ul v-if="links.length" class="space-y-1 text-sm">
                <li v-for="link in links" :key="link">
                    <a :href="link" target="_blank" rel="noopener noreferrer" class="break-all text-sky-600 hover:underline">{{ link }}</a>
                </li>
            </ul>
            <button type="button" class="btn btn-primary" :disabled="busy" @click="input.click()">Subir la factura</button>
            <p class="text-xs text-slate-500">o arrástrala aquí · PDF, JPG, PNG o HEIC</p>
            <input ref="input" type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.heic" @change="pick" />
        </div>

        <div v-else class="absolute inset-0 flex items-center justify-center p-8 text-center text-sm text-slate-500">
            No se puede mostrar este tipo de fichero aquí. Usa «Descargar».
        </div>
    </div>
</template>
