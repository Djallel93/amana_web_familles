/** @type {import('tailwindcss').Config} */
import amanaPreset from '@amana/shared-ui/tailwind-preset';

export default {
    presets: [amanaPreset],
    darkMode: 'class',

    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{js,ts,vue}',
        './node_modules/@amana/shared-ui/src/**/*.{js,ts,vue}',
        './vendor/amana/shared/resources/views/**/*.blade.php',
        '../amana_shared/resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            colors: {
                // Identité visuelle propre à Familles — pilotée par des
                // variables CSS (voir public/css/custom.css) depuis le
                // 13/08/2026, même mécanisme que surface/ink dans le
                // préréglage partagé, pour que du JS qui ne peut pas lire
                // de classes Tailwind (Chart.js dans
                // FamillesStatistiques.vue) puisse lire la même couleur via
                // getComputedStyle() au lieu de dupliquer les hex à la main.
                accent: {
                    DEFAULT: 'rgb(var(--color-accent) / <alpha-value>)',
                    dark: 'rgb(var(--color-accent-dark) / <alpha-value>)',
                    light: 'rgb(var(--color-accent-light) / <alpha-value>)',
                },
                sidebar: { DEFAULT: '#0c2321', 2: '#123330' },
            },
            boxShadow: {
                glow: 'rgba(15,118,110,0.25)',
            },
        },
    },

    plugins: [
        require('@tailwindcss/forms'),
    ],
};
