import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router.js';

const root = document.getElementById('panel');
const app = createApp(App, { boot: JSON.parse(root.dataset.boot) });

app.use(router).mount(root);
