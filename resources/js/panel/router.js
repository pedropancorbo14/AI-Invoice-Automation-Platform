import { createRouter, createWebHistory } from 'vue-router';
import InboxView from './views/InboxView.vue';
import ReviewView from './views/ReviewView.vue';
import MetricsView from './views/MetricsView.vue';
import ExportView from './views/ExportView.vue';
import DocumentsView from './views/DocumentsView.vue';
import UploadView from './views/UploadView.vue';
import TaxesView from './views/TaxesView.vue';

// El título de la pestaña lo pone App.vue (con el número de pendientes).
export const router = createRouter({
    history: createWebHistory('/panel/'),
    routes: [
        { path: '/', redirect: '/revisar' },
        { path: '/revisar', name: 'inbox', component: InboxView, meta: { title: 'Pendientes de revisar' } },
        { path: '/revisar/:id(\\d+)', name: 'review', component: ReviewView, props: (r) => ({ id: Number(r.params.id) }), meta: { title: 'Revisar' } },
        { path: '/facturas', name: 'documents', component: DocumentsView, meta: { title: 'Facturas' } },
        { path: '/subir', name: 'upload', component: UploadView, meta: { title: 'Subir facturas' } },
        { path: '/metricas', name: 'metrics', component: MetricsView, meta: { title: 'Métricas' } },
        { path: '/impuestos', name: 'taxes', component: TaxesView, meta: { title: 'Impuestos' } },
        { path: '/exportar', name: 'export', component: ExportView, meta: { title: 'Exportar' } },
        { path: '/:pathMatch(.*)*', redirect: '/revisar' },
    ],
    // Al volver atrás, cada lista recupera su scroll.
    scrollBehavior: (to, from, saved) => saved ?? { top: 0 },
});
