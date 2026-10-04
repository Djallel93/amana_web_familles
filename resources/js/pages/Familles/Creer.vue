<!-- resources/js/pages/Familles/Creer.vue -->
<!--
    Page Inertia « Créer une famille » (03/10/2026) — bouton en haut à
    droite de Dossiers familles. Réutilise l'assistant du formulaire public
    (IntakeForm.vue) en mode staff : pas de consentement RGPD, dossier créé
    directement en « En attente » et rouvert dans le panneau de la liste —
    voir FamilleCreationController.

    Bouton de retour : même gabarit que les autres pages (lien plein
    bg-ink, cf. Livraison/Contacts.vue).
-->
<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import IntakeForm from "../../components/intake/IntakeForm.vue";

interface ListeOption {
    id: number;
    code: string;
    libelle_fr: string;
    libelle_ar: string;
    libelle_en: string;
}

defineProps<{
    langue: "fr" | "ar" | "en";
    storeUrl: string;
    retourUrl: string;
    secteursActivite: ListeOption[];
    organismesAide: ListeOption[];
    organisations: { id: number; code: string; nom: string }[];
    googlePlacesApiKey: string;
}>();
</script>

<template>
    <Head title="Créer une famille — AMANA Familles" />

    <div class="max-w-3xl mx-auto py-8">
        <a
            :href="retourUrl"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90"
        >
            ← Retour aux dossiers
        </a>

        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Créer une famille</h1>

        <IntakeForm
            :mode-staff="true"
            :langue="langue"
            :store-url="storeUrl"
            refus-url=""
            :secteurs-activite="secteursActivite"
            :organismes-aide="organismesAide"
            :organisations="organisations"
            :google-places-api-key="googlePlacesApiKey"
        />
    </div>
</template>
