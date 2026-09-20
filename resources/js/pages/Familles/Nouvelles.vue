<!-- resources/js/pages/Familles/Nouvelles.vue -->
<!--
    Page Inertia "Nouvelles demandes" — Section E4 du refactor (chunk 4,
    12/09/2026), remplace resources/views/familles/nouvelles.blade.php
    (supprimée dans ce même chunk, plus aucun consommateur une fois
    FamillesController::nouvelles() converti en Inertia::render()).

    Volontairement plus simple que Familles/Index.vue : pas de puces de
    filtres actifs, pas de bouton export/sync Google Contacts, pas de
    gestion ?ouvrir= (pas d'autocomplétion Nom/Téléphone sur cette vue —
    voir FamillesController::valeursFiltres(), avecAutocompletion: false)
    — reprend exactement le même périmètre que l'ancienne vue Blade.
-->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import FamilleFiltresBar from '../../components/familles/FamilleFiltresBar.vue';
import FamillesTable, { type FamilleLigne } from '../../components/familles/FamillesTable.vue';
import DetailPanel from '../../components/familles/DetailPanel.vue';
import { useLiveDossiers } from '../../components/familles/useLiveDossiers';
import type { FamilleFiltres, Organisation, Quartier, Secteur, Ville } from '../../components/livraison/shared/types';

interface ListeOption {
    id: number;
    code: string;
    libelle_fr: string;
    libelle_ar: string;
    libelle_en: string;
}

interface FamillesPaginees {
    data: FamilleLigne[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
}

const props = defineProps<{
    familles: FamillesPaginees;
    villes: Ville[];
    secteurs: Secteur[];
    quartiers: Quartier[];
    organisations: Organisation[];
    secteursActivite: ListeOption[];
    organismesAide: ListeOption[];
    valeursFiltres: FamilleFiltres;
    triActuel: string | null;
    directionActuelle: 'asc' | 'desc';
    aFiltresActifs: boolean;
    showUrlTemplate: string;
    updateUrlTemplate: string;
    deverrouillerUrlTemplate: string;
    forcerDeverrouillageUrlTemplate: string;
    uploadUrlTemplate: string;
    downloadUrlTemplate: string;
    deleteDocUrlTemplate: string;
    initialGooglePlacesKey: string;
    initialGoogleEmbedKey: string;
}>();

// Rechargement automatique du tableau (Scénario 5 du chantier "polling live") —
// voir useLiveDossiers.ts pour le raisonnement et la règle de cohérence.
useLiveDossiers();

const baseUrl = window.location.pathname;

const currentQuery = computed(() => ({
    ...props.valeursFiltres,
    tri: props.triActuel ?? undefined,
    direction: props.directionActuelle,
    per_page: props.familles.per_page,
}));
</script>

<template>
    <Head title="Nouvelles demandes — AMANA Familles" />

    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Nouvelles demandes</h1>
            <p class="text-[13px] text-ink-muted mt-1">
                {{ familles.total }} demande{{ familles.total !== 1 ? 's' : '' }} pas encore ouverte{{ familles.total !== 1 ? 's' : '' }},
                triées de la plus ancienne à la plus récente
            </p>
        </div>
    </div>

    <FamilleFiltresBar
        :villes="villes"
        :secteurs="secteurs"
        :quartiers="quartiers"
        :organisations="organisations"
        :valeurs-filtres="valeursFiltres"
        :avec-statut="false"
        :avec-autocompletion="false"
        :ouvert-par-defaut="false"
        :base-url="baseUrl"
        :per-page="familles.per_page"
    />

    <FamillesTable
        :familles="familles"
        :base-url="baseUrl"
        :current-query="currentQuery"
        :tri-actuel="triActuel"
        :direction-actuelle="directionActuelle"
        vide-icone="📭"
        vide-titre="Aucune nouvelle demande"
        :a-filtres-actifs="aFiltresActifs"
        vide-message-base="Tout est à jour — aucune soumission en attente d'ouverture."
        vide-message-filtre="Aucun résultat pour ces filtres."
        :vide-lien-reinitialisation="null"
    />

    <DetailPanel
        :show-url-template="showUrlTemplate"
        :update-url-template="updateUrlTemplate"
        :deverrouiller-url-template="deverrouillerUrlTemplate"
        :forcer-deverrouillage-url-template="forcerDeverrouillageUrlTemplate"
        :upload-url-template="uploadUrlTemplate"
        :download-url-template="downloadUrlTemplate"
        :delete-doc-url-template="deleteDocUrlTemplate"
        :initial-google-places-key="initialGooglePlacesKey"
        :initial-google-embed-key="initialGoogleEmbedKey"
        :initial-secteurs-activite-disponibles="secteursActivite"
        :initial-organismes-aide-disponibles="organismesAide"
    />
</template>
