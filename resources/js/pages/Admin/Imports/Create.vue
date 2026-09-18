<!-- resources/js/pages/Admin/Imports/Create.vue -->
<!--
    Page Inertia "Nouvel import" — Section E4 du refactor (16/09/2026),
    remplace resources/views/admin/imports/create.blade.php (supprimée
    dans ce même chunk, plus aucun consommateur une fois
    ImportsController::create() converti en Inertia::render()).

    Reprend le formulaire CSV et son texte d'aide tels quels — seul le
    <script> vanilla qui désactivait le bouton et déclenchait l'overlay
    au submit est devenu un handler @submit Vue (onSubmitCsv) : un
    <script> littéral ne peut pas vivre dans un <template> Vue, cette
    traduction est mécanique, pas un choix de comportement. Le <form>
    lui-même reste une soumission multipart classique (redirection pleine
    page à la fin, voir ImportsController::storeCsv()) — Inertia
    n'intercepte que <Link>/router.*, jamais un <form> natif, donc rien
    d'autre à adapter ici.

    Message d'erreur de validation (ex. fichier CSV vide/illisible) : lu
    depuis usePage().props.errors plutôt que la variable Blade $errors
    (invisible depuis un composant Vue) — errors est partagé
    automatiquement par le middleware Inertia de base sur toute réponse
    qui suit un retour de session avec des erreurs de validation, sans
    rien à ajouter côté HandleInertiaRequests::share() pour ce cas précis.

    ImportManualGrid.vue et ImportOverlay.vue sont désormais des enfants
    Vue normaux plutôt que des îlots séparés montés par app.ts.
-->
<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ImportManualGrid from '../../../components/imports/ImportManualGrid.vue';
import ImportOverlay from '../../../components/imports/ImportOverlay.vue';

defineProps<{
    retourUrl: string;
    storeCsvUrl: string;
    storeManuelUrl: string;
}>();

const page = usePage<{ errors: Record<string, string> }>();
const erreurFichier = computed(() => page.props.errors?.fichier);

// Le formulaire CSV reste une soumission multipart classique (voir
// docblock ci-dessus) : le jeton CSRF est lu depuis la balise
// <meta name="csrf-token"> déjà posée par la racine Blade, comme le
// fait déjà shared/api.ts pour les requêtes XHR de l'app.
const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

const csvSoumission = ref(false);
function onSubmitCsv() {
    csvSoumission.value = true;
    window.showImportOverlay?.();
}
</script>

<template>
    <Head title="Nouvel import — AMANA Familles" />

    <div class="mb-7">
        <Link :href="retourUrl" class="text-[13px] text-ink-muted hover:text-accent transition-colors no-underline">←
        Retour aux imports</Link>
        <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight mt-2">Nouvel import</h1>
        <p class="text-[13px] text-ink-muted mt-1">Ajout ou mise à jour de plusieurs dossiers — les deux modes
            utilisent le même traitement (dédup par email/téléphone+nom, résolution géographique automatique).</p>
    </div>

    <div class="grid lg:grid-cols-2 gap-5 mb-8">

        <!-- Upload CSV -->
        <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-6">
            <div class="flex items-center gap-2.5 mb-4">
                <div class="w-8 h-8 bg-sky-50 rounded-md flex items-center justify-center text-sm flex-shrink-0">📄
                </div>
                <h2 class="font-heading text-[15px] font-semibold text-ink">Fichier CSV</h2>
            </div>

            <div v-if="erreurFichier" class="mb-4 px-3 py-2.5 rounded-md bg-rose-50 border border-rose-200 text-rose-800 text-[12.5px]">
                {{ erreurFichier }}
            </div>

            <form :action="storeCsvUrl" method="POST" enctype="multipart/form-data" @submit="onSubmitCsv">
                <input type="hidden" name="_token" :value="csrfToken">
                <input type="file" name="fichier" accept=".csv,.txt" required
                    class="w-full text-[12.5px] text-ink-muted mb-3 file:mr-3 file:px-3 file:py-1.5 file:rounded-md file:border-0 file:bg-accent/10 file:text-accent-dark file:text-[12px] file:font-semibold">
                <button type="submit" :disabled="csvSoumission"
                    class="w-full min-h-[44px] px-4 py-2.5 bg-accent hover:bg-accent-dark disabled:opacity-50 disabled:cursor-not-allowed text-white text-[13px] font-semibold rounded-lg transition-colors cursor-pointer">
                    Importer le fichier
                </button>
            </form>

            <details class="mt-4 text-[12px] text-ink-muted">
                <summary class="cursor-pointer font-semibold text-ink">Format attendu</summary>
                <p class="mt-2 leading-relaxed">
                    En-tête avec (au minimum) : <code class="bg-surface-2 px-1 rounded">nom</code>,
                    <code class="bg-surface-2 px-1 rounded">prenom</code>,
                    <code class="bg-surface-2 px-1 rounded">telephone</code>. Colonnes optionnelles :
                    email, telephone_bis, adresse, code_postal, ville, nombre_adulte, nombre_enfant,
                    zakat_el_fitr, sadaqa, se_deplace, criticite, langue, etat_dossier, commentaire_dossier.
                    Séparateur <code class="bg-surface-2 px-1 rounded">;</code> ou
                    <code class="bg-surface-2 px-1 rounded">,</code> détecté automatiquement.
                </p>
            </details>
        </div>

        <!-- Saisie manuelle -->
        <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-6">
            <div class="flex items-center gap-2.5 mb-4">
                <div class="w-8 h-8 bg-amber-50 rounded-md flex items-center justify-center text-sm flex-shrink-0">
                    ✍️</div>
                <h2 class="font-heading text-[15px] font-semibold text-ink">Saisie manuelle</h2>
            </div>
            <p class="text-[12.5px] text-ink-muted mb-3">Utilisez plutôt le CSV si vous avez beaucoup de dossiers à
                saisir — ce tableau reste pratique pour quelques lignes.</p>
        </div>

    </div>

    <!-- La grille manuelle prend toute la largeur : plus lisible avec autant de colonnes -->
    <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-6">
        <ImportManualGrid :store-url="storeManuelUrl" />
    </div>

    <ImportOverlay />
</template>
