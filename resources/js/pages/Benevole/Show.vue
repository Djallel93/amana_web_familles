<!-- resources/js/pages/Benevole/Show.vue -->
<!--
    Page Inertia "Candidature bénévole" — Section E4 du refactor
    (16/09/2026), remplace resources/views/benevole/show.blade.php
    (supprimée dans ce même chunk, plus aucun consommateur une fois
    BenevoleIntakeController::showForm() converti en Inertia::render()).

    Contrairement aux pages du domaine livraison, cette page n'ajoute
    aucun chrome propre (pas de titre, pas de lien de retour) : le shell
    entier (logo, sélecteur de langue, pied de page) vit désormais dans
    resources/views/app-public.blade.php, la racine Inertia dédiée aux
    pages publiques (voir son docblock) — cette page ne fait que passer
    les props à BenevoleForm.vue, désormais enfant Vue normal plutôt
    qu'îlot monté par app.ts.

    Pas de <Head> ici : app-public.blade.php fixe déjà <title> via
    withViewData()/$titre côté serveur (une page publique unique par
    route, pas de titre par sous-vue à gérer comme sur les écrans
    staff).
-->
<script setup lang="ts">
import BenevoleForm from '../../components/benevole/BenevoleForm.vue';

interface Secteur {
    id: number;
    libelle: string;
}

interface OrganisationOption {
    id: number;
    code: string;
    nom: string;
}

defineProps<{
    langue: 'fr' | 'ar' | 'en';
    storeUrl: string;
    refusUrl: string;
    secteurs: Secteur[];
    organisations: OrganisationOption[];
}>();
</script>

<template>
    <BenevoleForm :langue="langue" :store-url="storeUrl" :refus-url="refusUrl" :secteurs="secteurs"
        :organisations="organisations" />
</template>
