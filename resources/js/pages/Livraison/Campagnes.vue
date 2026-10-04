<!-- resources/js/pages/Livraison/Campagnes.vue -->
<!--
    Page Inertia "Campagnes" (liste seule depuis le 03/10/2026 : la création
    est sur CampagneCreer.vue, bouton en haut à droite) — Section E4 du refactor
    (16/09/2026, troisième chunk du domaine livraison), remplace
    resources/views/livraison/campagnes.blade.php (supprimée dans ce
    même chunk, plus aucun consommateur une fois
    CampagnesController::index() converti en Inertia::render()).

    Cette page ne porte que le titre que portait la Blade ; tout le
    reste (liste + formulaire de création) vit dans CampagnesIndex.vue,
    désormais enfant Vue normal plutôt qu'îlot monté par app.ts.

    Les liens "Modifier" vers une campagne et la navigation après
    création (CampagnesIndex.vue, creerCampagne()) restent des URLs
    brutes (<a href>/window.location.href), inchangées par ce chunk même
    si campagne-detail.blade.php est depuis devenue une page Inertia
    (chunk du 16/09/2026, cinquième du domaine livraison) — passer ces
    deux liens en <Link>/router.get() serait cohérent maintenant que la
    cible l'est aussi, mais reste un choix délibéré à faire plutôt qu'un
    changement silencieux ici.
-->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import CampagnesIndex from '../../components/livraison/campagnes/CampagnesIndex.vue';
import type { Campagne } from '../../components/livraison/shared/types';

defineProps<{
    campagnes: Campagne[];
    creerUrl: string;
    apercuUrlTemplate: string;
    resumeSuppressionUrlTemplate: string;
    destroyUrlTemplate: string;
}>();
</script>

<template>
    <Head title="Campagnes — AMANA Familles" />

    <div class="max-w-3xl mx-auto py-8">
        <div class="flex items-center justify-between gap-3 mb-6">
            <h1 class="font-heading text-xl font-semibold text-ink">Campagnes</h1>
            <!-- Création sur sa propre page (03/10/2026) — voir CampagneCreer.vue -->
            <Link :href="creerUrl"
                class="inline-flex items-center gap-1.5 px-4 py-2 bg-accent hover:bg-accent-dark text-white text-[13px] font-semibold rounded-lg transition-colors active:scale-95 no-underline">
                ➕ Nouvelle campagne
            </Link>
        </div>

        <CampagnesIndex :campagnes="campagnes" :apercu-url-template="apercuUrlTemplate"
            :resume-suppression-url-template="resumeSuppressionUrlTemplate" :destroy-url-template="destroyUrlTemplate" />
    </div>
</template>
