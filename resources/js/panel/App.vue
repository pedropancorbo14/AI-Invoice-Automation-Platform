<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import Icon from './components/Icon.vue';
import ConfirmDialog from './components/ConfirmDialog.vue';
import ShortcutsDialog from './components/ShortcutsDialog.vue';
import Toaster from './components/Toaster.vue';
import { counts, refreshCounts, usePolling } from './polling.js';
import { inFlight } from './api.js';

const props = defineProps({ boot: { type: Object, required: true } });
provide('boot', props.boot);

const route = useRoute();
const router = useRouter();
const shortcuts = ref(null);

const pending = computed(() => counts.value.needs_review ?? 0);
const initials = computed(() => props.boot.user.name.split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase());

const NAV = [
    { to: '/revisar', label: 'Pendientes', icon: 'inbox', badge: true },
    { to: '/facturas', label: 'Facturas', icon: 'files' },
    { to: '/subir', label: 'Subir', icon: 'upload' },
    { to: '/metricas', label: 'Métricas', icon: 'chart' },
    { to: '/exportar', label: 'Exportar', icon: 'download' },
];

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

// Barra de progreso arriba mientras se espera al servidor. Solo aparece si la
// espera pasa de 150 ms, para que no parpadee en las peticiones rápidas.
const loading = ref(false);
let loadingTimer = null;
watch(inFlight, (n) => {
    clearTimeout(loadingTimer);
    if (n > 0) loadingTimer = setTimeout(() => (loading.value = true), 150);
    else loading.value = false;
});

// El número de pendientes también en la pestaña del navegador.
watch([pending, () => route.meta.title], ([n, title]) => {
    document.title = `${n ? `(${n}) ` : ''}${title ?? 'Panel'} · Facturas IA`;
}, { immediate: true });

// Cada vez que se cambia de pantalla (p. ej. tras aprobar) y cada minuto.
watch(() => route.fullPath, refreshCounts);
usePolling(refreshCounts, () => 60000);

// Atajos globales: "g" + letra para navegar, "/" para buscar, "?" para la ayuda.
let pendingG = 0;
function onKey(event) {
    const target = event.target;
    if (event.ctrlKey || event.metaKey || event.altKey) return;
    if (target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;

    if (event.key === '?') {
        event.preventDefault();
        shortcuts.value?.show();
    } else if (event.key === '/') {
        event.preventDefault();
        if (route.name === 'documents') {
            document.getElementById('search')?.focus();
        } else {
            router.push({ name: 'documents', query: { foco: 'buscar' } });
        }
    } else if (event.key === 'g') {
        pendingG = Date.now();
    } else if (Date.now() - pendingG < 1000) {
        const to = { p: '/revisar', f: '/facturas', s: '/subir', m: '/metricas', e: '/exportar' }[event.key];
        pendingG = 0;
        if (to) {
            event.preventDefault();
            router.push(to);
        }
    }
}

onMounted(() => {
    refreshCounts();
    window.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <div class="min-h-screen">
        <Transition name="fade">
            <div v-if="loading" class="fixed inset-x-0 top-0 z-50 h-0.5 overflow-hidden bg-brand-100/60 dark:bg-brand-950/60" role="progressbar" aria-label="Cargando">
                <div class="h-full w-1/3 animate-indeterminate rounded-full bg-brand-500" />
            </div>
        </Transition>
        <div v-if="boot.demo" class="bg-brand-600 px-4 py-1.5 text-center text-xs text-white">
            Demo con facturas ficticias · los datos se reinician cada noche
        </div>
        <header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/85 backdrop-blur-md dark:border-slate-800 dark:bg-slate-900/85">
            <div class="mx-auto flex h-14 max-w-[1600px] items-center gap-3 px-4 sm:gap-6">
                <RouterLink to="/revisar" class="flex shrink-0 items-center gap-2 font-semibold tracking-tight">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-sm">
                        <Icon name="receipt" :size="17" />
                    </span>
                    <span class="hidden lg:inline">Facturas IA</span>
                </RouterLink>

                <nav class="-mx-1 flex min-w-0 items-center gap-1 overflow-x-auto px-1 text-sm">
                    <RouterLink
                        v-for="item in NAV"
                        :key="item.to"
                        :to="item.to"
                        class="flex shrink-0 items-center gap-2 rounded-lg px-3 py-1.5 font-medium text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
                        active-class="!bg-brand-50 !text-brand-700 dark:!bg-brand-950/60 dark:!text-brand-200"
                        :title="item.label"
                    >
                        <Icon :name="item.icon" />
                        <span class="hidden sm:inline">{{ item.label }}</span>
                        <span
                            v-if="item.badge && pending"
                            class="rounded-full bg-amber-500 px-1.5 text-[11px] leading-4 font-semibold text-white tabular-nums"
                            :aria-label="`${pending} pendientes`"
                        >{{ pending }}</span>
                    </RouterLink>
                </nav>

                <div class="ml-auto flex shrink-0 items-center gap-1">
                    <button type="button" class="btn btn-ghost hidden !px-2 md:inline-flex" title="Atajos de teclado (?)" @click="shortcuts?.show()">
                        <Icon name="keyboard" :size="18" />
                    </button>
                    <div class="hidden items-center gap-2.5 pl-2 sm:flex">
                        <span class="flex size-8 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-200">{{ initials }}</span>
                        <div class="hidden text-xs leading-tight xl:block">
                            <div class="font-medium">{{ boot.user.name }}</div>
                            <div class="text-slate-500 dark:text-slate-400">{{ boot.account.name }}</div>
                        </div>
                    </div>
                    <form method="post" action="/logout">
                        <input type="hidden" name="_token" :value="csrf()" />
                        <button type="submit" class="btn btn-ghost !px-2" title="Salir">
                            <Icon name="logout" :size="18" />
                        </button>
                    </form>
                </div>
            </div>
        </header>
        <main class="mx-auto max-w-[1600px] px-4 py-6 sm:py-8">
            <!-- Cambio de pantalla con un fundido breve. Revisar una factura tras otra no anima: es la misma pantalla. -->
            <RouterView v-slot="{ Component, route: current }">
                <Transition name="page" mode="out-in">
                    <div :key="current.name">
                        <component :is="Component" />
                    </div>
                </Transition>
            </RouterView>
        </main>
        <ShortcutsDialog ref="shortcuts" />
        <ConfirmDialog />
        <Toaster />
    </div>
</template>
