<script setup>
import { provide } from 'vue';
import { RouterLink, RouterView } from 'vue-router';

const props = defineProps({ boot: { type: Object, required: true } });
provide('boot', props.boot);

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <div v-if="boot.demo" class="bg-sky-600 px-4 py-1.5 text-center text-xs text-white">
            Demo con facturas ficticias · los datos se reinician cada noche
        </div>
        <header class="sticky top-0 z-10 border-b border-slate-200 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90">
            <div class="mx-auto flex max-w-[1600px] items-center gap-3 px-4 py-3 sm:gap-6">
                <span class="hidden font-semibold sm:inline">Facturas IA</span>
                <nav class="-mx-1 flex min-w-0 gap-1 overflow-x-auto px-1 text-sm">
                    <RouterLink to="/revisar" class="nav-link" active-class="nav-link-active">Pendientes</RouterLink>
                    <RouterLink to="/metricas" class="nav-link" active-class="nav-link-active">Métricas</RouterLink>
                    <RouterLink to="/exportar" class="nav-link" active-class="nav-link-active">Exportar</RouterLink>
                    <a href="/subir" class="nav-link">Subir</a>
                </nav>
                <div class="ml-auto flex shrink-0 items-center gap-4 text-sm text-slate-500 dark:text-slate-400">
                    <span class="hidden sm:inline">{{ boot.user.name }} · {{ boot.account.name }}</span>
                    <form method="post" action="/logout">
                        <input type="hidden" name="_token" :value="csrf()" />
                        <button type="submit" class="hover:text-slate-900 dark:hover:text-white">Salir</button>
                    </form>
                </div>
            </div>
        </header>
        <main class="mx-auto max-w-[1600px] px-4 py-6">
            <RouterView />
        </main>
    </div>
</template>

<style>
@reference '../../css/app.css';

.nav-link {
    @apply rounded-md px-3 py-1.5 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800;
}
.nav-link-active {
    @apply bg-slate-900 text-white hover:bg-slate-900 dark:bg-white dark:text-slate-900 dark:hover:bg-white;
}
.btn {
    @apply inline-flex items-center justify-center gap-2 rounded-md px-3.5 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50;
}
.btn-primary {
    @apply bg-emerald-600 text-white hover:bg-emerald-700;
}
.btn-secondary {
    @apply border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800;
}
.btn-danger {
    @apply bg-amber-600 text-white hover:bg-amber-700;
}
.input {
    @apply w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm tabular-nums outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30 dark:border-slate-700 dark:bg-slate-900;
}
.input-doubtful {
    @apply border-amber-400 bg-amber-50 dark:border-amber-500 dark:bg-amber-950/40;
}
.label {
    @apply mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400;
}
.card {
    @apply rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900;
}
.chip {
    @apply inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/50 dark:text-amber-200;
}
kbd {
    @apply rounded border border-slate-300 bg-slate-100 px-1 font-sans text-[10px] text-slate-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-400;
}
</style>
