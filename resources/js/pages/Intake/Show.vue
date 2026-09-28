<!-- resources/js/pages/Intake/Show.vue -->
<!--
    Page Inertia "Demande d'aide" — Section E4 du refactor (16/09/2026),
    remplace resources/views/intake/show.blade.php (supprimée dans ce
    même chunk, plus aucun consommateur une fois
    IntakeController::showForm() converti en Inertia::render()).

    Même forme que resources/js/pages/Benevole/Show.vue : aucun chrome
    propre, le shell entier (logo, sélecteur de langue, pied de page) vit
    dans resources/views/app-public.blade.php (racine Inertia publique,
    créée dans le chunk précédent). Cette page ne fait que passer les
    props à IntakeForm.vue, désormais enfant Vue normal plutôt qu'îlot
    monté par app.ts.

    Pas de <Head> ici, pour la même raison que Benevole/Show.vue :
    app-public.blade.php fixe déjà <title> via withViewData()/$titre
    côté serveur.
-->
<script setup lang="ts">
import IntakeForm from '../../components/intake/IntakeForm.vue';

interface ListeOption {
    id: number;
    code: string;
    libelle_fr: string;
    libelle_ar: string;
    libelle_en: string;
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
    secteursActivite: ListeOption[];
    organismesAide: ListeOption[];
    organisations: OrganisationOption[];
    googlePlacesApiKey: string;
}>();
</script>

<template>
    <IntakeForm :langue="langue" :store-url="storeUrl" :refus-url="refusUrl" :secteurs-activite="secteursActivite"
        :organismes-aide="organismesAide" :organisations="organisations" :google-places-api-key="googlePlacesApiKey" />
</template>
