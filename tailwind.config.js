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
                // Identité visuelle propre à Familles — ambre/terracotta chaud
                // (seule différence de thème avec Planning ; surface/ink/font/
                // radius/shadow/spacing/keyframes viennent tous du préréglage
                // partagé @amana/shared-ui/tailwind-preset).
                accent: {
                    DEFAULT: '#b45309',
                    dark: '#c2703a',
                    light: '#d97706',
                },
                sidebar: {
                    DEFAULT: '#2e1a0f',
                    2: '#3a2214',
                },
            },
            boxShadow: {
                glow: '0 0 0 3px rgba(180,83,9,0.25)',
            },
        },
    },

    plugins: [
        require('@tailwindcss/forms'),
    ],
};
