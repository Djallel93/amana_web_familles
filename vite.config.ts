// vite.config.ts
// Point de configuration Vite pour AMANA Familles.
// Compile à la fois le CSS (Tailwind) et le JS/TS (Vue 3).
// Identique en structure à celui de amana_web_planning.

import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        vue(),

        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.ts',
            ],
            refresh: true,
        }),
    ],

    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
});
