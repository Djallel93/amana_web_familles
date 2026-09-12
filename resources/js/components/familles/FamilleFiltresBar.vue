<!-- resources/js/components/familles/FamilleFiltresBar.vue -->
<!--
    Enveloppe montée sur #vue-familles-filtres (familles/index.blade.php
    et nouvelles.blade.php) — Section A3 du refactor (10/09/2026).
    Remplace le <form method="GET"> hand-roulé (+ script vanilla JS
    d'autocomplétion) par le même FamilleFilterPanel.vue que le domaine
    livraison, pour n'avoir plus qu'UNE implémentation du panneau de
    filtres (voir son docblock).

    Volontairement PAS un écran réactif "fetch-driven" comme les pages
    livraison : "Filtrer"/une suggestion choisie déclenchent une
    navigation plein-page classique (window.location.href), exactement
    comme le <form method="GET"> d'origine — Dossier Familles reste une
    page Blade/pagination-serveur ordinaire. Rendre cet écran réactif
    sans rechargement est le travail de la migration Inertia.js (Section
    E du refactor), fait à part plutôt qu'anticipé ici en douce.

    Lit son propre point de montage (#vue-familles-filtres) via
    data-attributes plutôt que des props — même pattern que
    DetailPanel.vue/ReverseSyncPanel.vue (voir app.ts, mountIfPresent() ne
    passe aucune prop au montage) :
    - data-villes / data-secteurs / data-quartiers / data-organisations : JSON
    - data-valeurs : JSON de FamilleFiltres, valeurs actuelles (depuis
      request(), voir familles/index.blade.php et nouvelles.blade.php)
    - data-avec-statut : "true"/"false"
    - data-etats-disponibles / data-etat-couleurs : JSON (uniquement si
      avec-statut, sinon absents/vides)
    - data-avec-autocompletion : "true"/"false"
    - data-suggestions-url : uniquement si avec-autocompletion
    - data-ouvert-par-defaut : "true"/"false"
    - data-route-index : URL de base vers laquelle naviguer (sans query
      string) — familles.index ou familles.nouvelles
    - data-per-page : valeur courante du sélecteur "lignes par page", à
      préserver au clic Filtrer (même correctif que le 12/08/2026 sur le
      <form> d'origine)
-->
<script setup lang="ts">
import { onMounted, ref } from 'vue';
import FamilleFilterPanel from '../livraison/shared/FamilleFilterPanel.vue';
import { buildQuery } from '../livraison/shared/api';
import type { FamilleFiltres, Organisation, Quartier, Secteur, Ville } from '../livraison/shared/types';

const villes = ref<Ville[]>([]);
const secteurs = ref<Secteur[]>([]);
const quartiers = ref<Quartier[]>([]);
const organisations = ref<Organisation[]>([]);
const filtres = ref<FamilleFiltres>({});
const avecStatut = ref(false);
const etatsDisponibles = ref<string[]>([]);
const etatCouleurs = ref<Record<string, string>>({});
const avecAutocompletion = ref(false);
const suggestionsUrl = ref('');
const ouvertParDefaut = ref(false);
let routeIndex = '';
let perPage = '';

function parseJson<T>(brut: string | undefined, defaut: T): T {
    try {
        return brut ? JSON.parse(brut) as T : defaut;
    } catch {
        return defaut;
    }
}

onMounted(() => {
    const el = document.getElementById('vue-familles-filtres');
    if (!el) return;

    villes.value = parseJson(el.dataset.villes, []);
    secteurs.value = parseJson(el.dataset.secteurs, []);
    quartiers.value = parseJson(el.dataset.quartiers, []);
    organisations.value = parseJson(el.dataset.organisations, []);
    filtres.value = parseJson(el.dataset.valeurs, {});
    avecStatut.value = el.dataset.avecStatut === 'true';
    etatsDisponibles.value = parseJson(el.dataset.etatsDisponibles, []);
    etatCouleurs.value = parseJson(el.dataset.etatCouleurs, {});
    avecAutocompletion.value = el.dataset.avecAutocompletion === 'true';
    suggestionsUrl.value = el.dataset.suggestionsUrl ?? '';
    ouvertParDefaut.value = el.dataset.ouvertParDefaut === 'true';
    routeIndex = el.dataset.routeIndex ?? '';
    perPage = el.dataset.perPage ?? '';
});

function naviguer() {
    // etat_dossier traité à part : buildQuery() omet les chaînes vides,
    // or une chaîne vide EXPLICITE est ici significative ("Tous" plutôt
    // que le défaut "Validé" côté serveur, voir
    // FamillesController::appliquerFiltreStatut()) — voir
    // reinitialiser() dans FamilleFilterPanel.vue.
    const { etat_dossier, ...reste } = filtres.value;
    let url = routeIndex + buildQuery({ ...reste, per_page: perPage });

    if (avecStatut.value) {
        const separateur = url.includes('?') ? '&' : '?';
        url += `${separateur}etat_dossier=${encodeURIComponent(etat_dossier ?? '')}`;
    }

    window.location.href = url;
}

function ouvrirFiche(id: number) {
    // "remplir, filtrer puis ouvrir" (13/08/2026) : navigue directement
    // avec le filtre nom/téléphone déjà résolu sur l'id exact +
    // ?ouvrir=<id> pour que le panneau de détail s'ouvre automatiquement
    // une fois la page rechargée (voir l'IIFE en bas de
    // familles/index.blade.php qui écoute ce paramètre).
    const { etat_dossier, ...reste } = filtres.value;
    let url = routeIndex + buildQuery({ ...reste, per_page: perPage, ouvrir: id });

    if (avecStatut.value) {
        const separateur = url.includes('?') ? '&' : '?';
        url += `${separateur}etat_dossier=${encodeURIComponent(etat_dossier ?? '')}`;
    }

    window.location.href = url;
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
        :etats-disponibles="etatsDisponibles"
        :etat-couleurs="etatCouleurs"
        :avec-autocompletion="avecAutocompletion"
        :suggestions-url="suggestionsUrl"
        :ouvert-par-defaut="ouvertParDefaut"
        @filtrer="naviguer"
        @selection="ouvrirFiche"
    />
</template>
