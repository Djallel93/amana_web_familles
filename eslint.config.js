// eslint.config.js
//
// Ajouté le 10/09/2026 (Section E1 du refactor) — la codebase n'avait
// jusqu'ici aucun linter JS/TS, seul vue-tsc (voir "type-check" dans
// package.json) pour les types. Configuration volontairement alignée sur
// le style déjà en usage plutôt qu'un preset générique imposé de
// l'extérieur :
//   - guillemets simples + point-virgules dans les <script setup> (voir
//     n'importe quel composant sous resources/js/components/) — mais
//     app.ts/vite.config.ts utilisent déjà des guillemets doubles ;
//     plutôt que de faire échouer le lint sur ces 2 fichiers historiques
//     dès le premier lancement, la règle `quotes` reste désactivée pour
//     l'instant (voir plus bas) — à activer plus tard une fois ces 2
//     fichiers alignés, séparément de ce patch.
//   - noms de variables/fonctions en français dans toute la logique
//     métier (camelCase) — @typescript-eslint/naming-convention n'est
//     PAS activée : elle imposerait de choisir entre bloquer les noms
//     français (qui ne suivent aucune convention anglaise standard côté
//     règles ESLint) ou une configuration si permissive qu'elle
//     n'apporterait rien.
//   - 4 espaces d'indentation partout (voir tsconfig.json, vite.config.ts,
//     tous les composants) — la règle `indent` d'ESLint est notoirement
//     fragile sur les templates Vue (faux positifs fréquents sur les
//     attributs multi-lignes) ; laissée à un futur Prettier/formatage
//     dédié plutôt qu'à ESLint, qui se concentre ici sur la correction
//     plutôt que le style visuel.
import eslint from '@eslint/js';
import eslintPluginVue from 'eslint-plugin-vue';
import tseslint from 'typescript-eslint';
import vueTsEslintConfig from '@vue/eslint-config-typescript';

export default tseslint.config(
    { ignores: ['node_modules/**', 'public/build/**', 'vendor/**'] },

    eslint.configs.recommended,
    ...tseslint.configs.recommended,
    ...eslintPluginVue.configs['flat/essential'],
    ...vueTsEslintConfig(),

    {
        rules: {
            // Désactivées volontairement — voir docblock de fichier.
            quotes: 'off',
            indent: 'off',
            '@typescript-eslint/naming-convention': 'off',

            // Un $el?.dataset.xxx ?? '' non résolu (typo de data-attribute,
            // route mal câblée) casse silencieusement à l'exécution plutôt
            // qu'à la compilation — voir tous les composants montés sur un
            // point d'ancrage Blade (DetailPanel.vue, FamilleFiltresBar.vue,
            // CampagnesIndex.vue, etc.). 'warn' plutôt que 'error' : cette
            // codebase déclare intentionnellement des refs non utilisées
            // par le template dans quelques composants (voir
            // ContactsQueue.vue) pour des raisons de lisibilité du <script
            // setup> — 'error' casserait le premier lint sans qu'aucune de
            // ces occurrences ne soit un vrai bug.
            '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],

            // Cohérent avec le choix du projet lui-même : tsconfig.json a
            // `"strict": true` mais laisse `any` implicite passer là où
            // le typage d'une lib externe (Chart.js, Google Maps JS) ne
            // suit pas — voir HqCoordinatesAutocomplete.vue et
            // LivraisonStatistiques.vue. Interdire `any` explicite serait
            // plus strict que ce que le projet impose déjà lui-même via
            // tsconfig.
            '@typescript-eslint/no-explicit-any': 'off',

            // Les composants Vue de ce projet n'utilisent jamais
            // PascalCase pour les événements (@filtrer, @selection, en
            // français, minuscule) — cette règle imposerait une
            // convention absente du code existant.
            'vue/attribute-hyphenation': 'off',
        },
    },
);
