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
//     attributs multi-lignes) ; déléguée à Prettier (`npm run format`,
//     voir .prettierrc.json) plutôt qu'à ESLint, qui se concentre ici sur
//     la correction plutôt que le style visuel.
import eslint from '@eslint/js';
import eslintPluginVue from 'eslint-plugin-vue';
import tseslint from 'typescript-eslint';
import vueParser from 'vue-eslint-parser';
import eslintConfigPrettier from 'eslint-config-prettier/flat';

export default tseslint.config(
    { ignores: ['node_modules/**', 'public/build/**', 'vendor/**'] },

    eslint.configs.recommended,
    ...tseslint.configs.recommended,
    ...eslintPluginVue.configs['flat/essential'],

    {
        // `no-undef` désactivée sur TS/Vue (recommandation typescript-eslint) :
        // vue-tsc vérifie déjà les identifiants, et la règle ne connaît pas les
        // types DOM (NodeListOf, HTMLMetaElement…). Auparavant gérée par
        // @vue/eslint-config-typescript.
        files: ['resources/js/**/*.{ts,vue}'],
        rules: { 'no-undef': 'off' },
    },

    {
        // Remplace @vue/eslint-config-typescript (14/10/2026, décision D12) : sa
        // chaîne de dépendances (fast-glob → micromatch → braces@3.0.3) laissait
        // 4 vulnérabilités « high » dans `npm audit`. Même arrangement que
        // amana_web_planning : vue-eslint-parser pour le <template>, parseur
        // TypeScript pour les blocs <script>. On re-précise `parser: vueParser`
        // car tseslint.configs.recommended pose le parseur TypeScript sur tous
        // les fichiers, .vue compris.
        files: ['resources/js/**/*.vue'],
        languageOptions: {
            parser: vueParser,
            parserOptions: {
                parser: tseslint.parser,
                ecmaVersion: 'latest',
                sourceType: 'module',
                extraFileExtensions: ['.vue'],
            },
        },
    },

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

    {
        // Pages Inertia (Section E4 du refactor, 12/09/2026) — nommées
        // d'après l'action du contrôleur (Familles/Index.vue,
        // Familles/Nouvelles.vue, voir Inertia::render()), jamais
        // utilisées par nom de balise dans un template (aucun risque de
        // collision avec un élément HTML natif, seule raison d'être de
        // cette règle) : désactivée uniquement ici plutôt que
        // globalement, pour ne pas perdre le garde-fou sur les
        // composants réutilisables sous resources/js/components/.
        files: ['resources/js/pages/**/*.vue'],
        rules: {
            'vue/multi-word-component-names': 'off',
        },
    },

    // Toujours en DERNIER : coupe les règles ESLint de mise en forme qui
    // entreraient en conflit avec Prettier (.prettierrc.json). La mise en
    // forme est déléguée à Prettier (`npm run format`), ESLint ne garde que
    // la correction du code — même arrangement que amana_web_planning.
    eslintConfigPrettier,
);
