import { createRouter, createWebHistory } from 'vue-router';
import InboxView from './views/InboxView.vue';
import ReviewView from './views/ReviewView.vue';
import MetricsView from './views/MetricsView.vue';

export const router = createRouter({
    history: createWebHistory('/panel/'),
    routes: [
        { path: '/', redirect: '/revisar' },
        { path: '/revisar', name: 'inbox', component: InboxView, meta: { title: 'Pendientes de revisar' } },
        { path: '/revisar/:id(\\d+)', name: 'review', component: ReviewView, props: (r) => ({ id: Number(r.params.id) }), meta: { title: 'Revisar' } },
        { path: '/metricas', name: 'metrics', component: MetricsView, meta: { title: 'Métricas' } },
        { path: '/:pathMatch(.*)*', redirect: '/revisar' },
    ],
});

router.afterEach((to) => {
    document.title = `${to.meta.title ?? 'Panel'} · Facturas IA`;
});
