<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

const open = ref(false);

const GROUPS = [
    {
        title: 'En cualquier pantalla',
        keys: [
            [['?'], 'Ver esta ayuda'],
            [['g', 'p'], 'Ir a Pendientes'],
            [['g', 'f'], 'Ir a Facturas'],
            [['g', 's'], 'Ir a Subir'],
            [['/'], 'Buscar facturas'],
        ],
    },
    {
        title: 'Pendientes',
        keys: [[['R'], 'Empezar a revisar por la primera']],
    },
    {
        title: 'Revisando una factura',
        keys: [
            [['Ctrl', '↵'], 'Aprobar'],
            [['Alt', 'N'], 'No es factura'],
            [['Alt', 'D'], 'Duplicado'],
            [['Alt', '→'], 'Saltar a la siguiente'],
            [['Esc'], 'Volver a la lista'],
        ],
    },
];

defineExpose({ show: () => (open.value = true) });

function onKey(event) {
    if (event.key === 'Escape' && open.value) {
        event.stopImmediatePropagation();
        open.value = false;
    }
}

onMounted(() => window.addEventListener('keydown', onKey, true));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey, true));
</script>

<template>
    <Transition name="dialog">
    <div v-if="open" class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/40 p-4 backdrop-blur-sm" @click.self="open = false">
        <div class="card w-full max-w-md p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="shortcuts-title">
            <div class="mb-4 flex items-center">
                <h2 id="shortcuts-title" class="text-lg font-semibold">Atajos de teclado</h2>
                <button type="button" class="btn btn-ghost btn-sm ml-auto" @click="open = false">Cerrar <kbd>Esc</kbd></button>
            </div>
            <div v-for="group in GROUPS" :key="group.title" class="mb-4 last:mb-0">
                <p class="label">{{ group.title }}</p>
                <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    <div v-for="[keys, label] in group.keys" :key="label" class="flex items-center justify-between py-1.5">
                        <dt class="text-slate-600 dark:text-slate-300">{{ label }}</dt>
                        <dd class="flex gap-1"><kbd v-for="k in keys" :key="k" class="!text-xs">{{ k }}</kbd></dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
    </Transition>
</template>
