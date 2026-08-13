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
                // Identité visuelle propre à Familles
                accent: { DEFAULT: '#0f766e', dark: '#0d9488', light: '#14b8a6' },
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
