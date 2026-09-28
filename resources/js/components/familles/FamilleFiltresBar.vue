<!-- resources/js/components/familles/FamilleFiltresBar.vue -->
<!--
    Enveloppe montée sur #vue-familles-filtres (familles/index.blade.php
    et nouvelles.blade.php) — Section A3 du refactor (10/09/2026).
    Remplace le <form method="GET"> hand-roulé (+ script vanilla JS
    d'autocomplétion) par le même FamilleFilterPanel.vue que le domaine
    livraison, pour n'avoir plus qu'UNE implémentation du panneau de
    filtres (voir son docblock).

    Convertie en composant Inertia recevant des props (Section E4 du
    refactor, chunk 4, 12/09/2026) — remplace l'ancien montage
    createApp().mount() + lecture de dataset : familles/index.blade.php
    et familles/nouvelles.blade.php sont les DEUX seuls consommateurs de
    ce composant (vérifié — aucun partage avec le domaine livraison,
    contrairement à DetailPanel.vue), donc pas besoin d'un pont
    double-mode ici, conversion complète directe.

    "Filtrer"/une suggestion choisie déclenchent désormais un
    router.get() (Inertia, preserveState/preserveScroll) plutôt qu'un
    window.location.href — c'est le "vrai" objectif de cette section
    (voir l'ancien docblock qui pointait justement vers "la migration
    Inertia.js (Section E)" pour rendre cet écran réactif sans
    rechargement).
-->
<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import FamilleFilterPanel from '../livraison/shared/FamilleFilterPanel.vue';
import type { FamilleFiltres, Organisation, Quartier, Secteur, Ville } from '../livraison/shared/types';

const props = defineProps<{
    villes: Ville[];
    secteurs: Secteur[];
    quartiers: Quartier[];
    organisations: Organisation[];
    valeursFiltres: FamilleFiltres;
    avecStatut: boolean;
    etatsDisponibles?: string[];
    etatCouleurs?: Record<string, string>;
    avecAutocompletion: boolean;
    suggestionsUrl?: string;
    ouvertParDefaut: boolean;
    baseUrl: string;
    perPage: string | number;
}>();

// Copie locale plutôt qu'un defineModel() vers la page parente
// (Familles/Index.vue, Familles/Nouvelles.vue) : ces pages n'ont besoin
// que de la valeur INITIALE des filtres (valeursFiltres, résolue
// côté serveur depuis la query string) pour peupler ce panneau — pas
// d'un lien bidirectionnel réactif à chaque frappe, exactement le même
// besoin que l'ancien montage autonome (qui lisait sa propre valeur
// initiale depuis dataset une seule fois, voir l'historique de ce
// fichier).
const filtres = ref<FamilleFiltres>({ ...props.valeursFiltres });

function visiter(donnees: Record<string, string | number | boolean | number[] | undefined>) {
    router.get(props.baseUrl, donnees, { preserveState: true, preserveScroll: true, replace: true });
}

function naviguer() {
    // etat_dossier traité à part : les valeurs vides sont omises par
    // Inertia lors de la sérialisation des query params côté GET
    // (comportement équivalent à l'ancien buildQuery()), or une chaîne
    // vide EXPLICITE est ici significative ("Tous" plutôt que le défaut
    // "Validé" côté serveur, voir FamillesController::appliquerFiltreStatut())
    // — voir reinitialiser() dans FamilleFilterPanel.vue.
    const { etat_dossier, ...reste } = filtres.value;
    visiter({ ...reste, per_page: props.perPage, ...(props.avecStatut ? { etat_dossier: etat_dossier ?? '' } : {}) });
}

function ouvrirFiche(id: number) {
    // "remplir, filtrer puis ouvrir" (13/08/2026) : navigue directement
    // avec le filtre nom/téléphone déjà résolu sur l'id exact + ouvrir=<id>
    // pour que le panneau de détail s'ouvre automatiquement une fois la
    // page (re)chargée. Sous Inertia (Section E4 du refactor), ouvrirId
    // est lu réactivement depuis les props de la page (voir
    // Familles/Index.vue) plutôt que par l'ancienne IIFE de sondage sur
    // window.location.search + window.openFamilleDetail.
    const { etat_dossier, ...reste } = filtres.value;
    visiter({ ...reste, per_page: props.perPage, ouvrir: id, ...(props.avecStatut ? { etat_dossier: etat_dossier ?? '' } : {}) });
}
</script>

<template>
    <FamilleFilterPanel
        v-model="filtres"
        :villes="villes"
        :secteurs="secteurs"
        :quartiers="quartiers"
        :organisations="organisations"
        :avec-statut="avecStatut"
        :etats-disponibles="etatsDisponibles ?? []"
        :etat-couleurs="etatCouleurs ?? {}"
        :avec-autocompletion="avecAutocompletion"
        :suggestions-url="suggestionsUrl ?? ''"
        :ouvert-par-defaut="ouvertParDefaut"
        @filtrer="naviguer"
        @selection="ouvrirFiche"
    />
</template>
