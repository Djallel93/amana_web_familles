<!-- resources/js/pages/Livraison/CampagneCreer.vue -->
<!--
    Page Inertia « Nouvelle campagne » (03/10/2026) — le formulaire qui
    vivait sous la liste des campagnes, à part entière : voir
    CampagnesController::creer() et CampagneCreerForm.vue.

    Bouton de retour : même gabarit que les autres pages (lien plein bg-ink,
    cf. Livraison/Contacts.vue).
-->
<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import CampagneCreerForm from "../../components/livraison/campagnes/CampagneCreerForm.vue";

defineProps<{
    storeUrl: string;
    retourUrl: string;
    livraisonsMaxParTourneeDefaut: string;
    googlePlacesKey: string;
    hqGlobalDefaut: { lat: number; lng: number } | null;
}>();
</script>

<template>
    <Head title="Nouvelle campagne — AMANA Familles" />

    <div class="max-w-3xl mx-auto py-8">
        <a
            :href="retourUrl"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90"
        >
            ← Retour aux campagnes
        </a>

        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Nouvelle campagne</h1>

        <CampagneCreerForm
            :store-url="storeUrl"
            :livraisons-max-par-tournee-defaut="livraisonsMaxParTourneeDefaut"
            :google-places-key="googlePlacesKey"
            :hq-global-defaut="hqGlobalDefaut"
        />
    </div>
</template>
