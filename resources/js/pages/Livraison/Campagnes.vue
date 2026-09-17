<!-- resources/js/pages/Livraison/Campagnes.vue -->
<!--
    Page Inertia "Campagnes" (liste + création) — Section E4 du refactor
    (16/09/2026, troisième chunk du domaine livraison), remplace
    resources/views/livraison/campagnes.blade.php (supprimée dans ce
    même chunk, plus aucun consommateur une fois
    CampagnesController::index() converti en Inertia::render()).

    Cette page ne porte que le titre que portait la Blade ; tout le
    reste (liste + formulaire de création) vit dans CampagnesIndex.vue,
    désormais enfant Vue normal plutôt qu'îlot monté par app.ts.

    Les liens "Modifier" vers une campagne et la navigation après
    création (CampagnesIndex.vue, creerCampagne()) restent des URLs
    brutes : campagne-detail.blade.php n'est pas encore migrée (chunk
    ultérieur), ce ne serait pas cohérent d'y router.get() avant.
-->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CampagnesIndex from '../../components/livraison/campagnes/CampagnesIndex.vue';
import type { Campagne } from '../../components/livraison/shared/types';

defineProps<{
    campagnes: Campagne[];
    storeUrl: string;
    resumeSuppressionUrlTemplate: string;
    destroyUrlTemplate: string;
    livraisonsMaxParTourneeDefaut: string;
    googlePlacesKey: string;
    hqGlobalDefaut: { lat: number; lng: number } | null;
}>();
</script>

<template>
    <Head title="Campagnes — AMANA Familles" />

    <div class="max-w-3xl mx-auto py-8">
        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Campagnes</h1>

        <CampagnesIndex :campagnes="campagnes" :store-url="storeUrl"
            :resume-suppression-url-template="resumeSuppressionUrlTemplate" :destroy-url-template="destroyUrlTemplate"
            :livraisons-max-par-tournee-defaut="livraisonsMaxParTourneeDefaut" :google-places-key="googlePlacesKey"
            :hq-global-defaut="hqGlobalDefaut" />
    </div>
</template>
