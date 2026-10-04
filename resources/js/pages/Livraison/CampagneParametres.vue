<!-- resources/js/pages/Livraison/CampagneParametres.vue -->
<!--
    Page Inertia « Paramètres de la campagne » (03/10/2026) — voir
    CampagnesController::parametres() et CampagneParametres.vue. Retour : même
    gabarit que les autres pages (lien plein bg-ink).
-->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import CampagneParametres from '../../components/livraison/campagnes/CampagneParametres.vue';
import type { LigneEquipe } from '../../components/livraison/campagnes/EquipeMembresQueue.vue';
import type { Campagne } from '../../components/livraison/shared/types';

defineProps<{
    campagne: Campagne;
    onglet: 'hq' | 'journees' | 'equipes';
    googlePlacesKey: string;
    updateUrl: string;
    ajouterJourneeUrl: string;
    lignesEquipe: LigneEquipe[];
    ajouterEquipeUrl: string;
    retirerEquipeUrlTemplate: string;
    retourUrl: string;
}>();
</script>

<template>
    <Head title="Paramètres de la campagne — AMANA Familles" />

    <div class="max-w-4xl mx-auto py-8">
        <Link :href="retourUrl"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
        ← Retour à la campagne
        </Link>

        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Paramètres de la campagne</h1>

        <CampagneParametres :campagne="campagne" :onglet="onglet" :google-places-key="googlePlacesKey" :update-url="updateUrl"
            :ajouter-journee-url="ajouterJourneeUrl" :lignes-equipe="lignesEquipe" :ajouter-equipe-url="ajouterEquipeUrl"
            :retirer-equipe-url-template="retirerEquipeUrlTemplate" />
    </div>
</template>
