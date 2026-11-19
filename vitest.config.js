import { defineConfig } from 'vitest/config';

// Configuración propia para los tests: sin el plugin de Laravel, que no
// arranca en CI (solo sirve para el servidor de desarrollo y el build).
export default defineConfig({
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.js'],
    },
});
