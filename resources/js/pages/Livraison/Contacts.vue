<!-- resources/js/pages/Livraison/Contacts.vue -->
<!--
    Page Inertia "Suivi des contacts" — Section E4 du refactor
    (16/09/2026, sixième chunk du domaine livraison), remplace
    resources/views/livraison/contacts.blade.php (supprimée dans ce même
    chunk, plus aucun consommateur une fois
    ContactTrackingController::index() converti en Inertia::render()).

    Cette page ne porte que le lien de retour ; le tableau de suivi vit
    dans ContactsQueue.vue, désormais enfant Vue normal plutôt qu'îlot
    monté par app.ts. DetailPanel.vue est également monté ici en enfant
    direct — dernier écran à faire cette conversion (voir le docblock de
    ContactTrackingController::index()) : l'ancien pont double-mode de
    DetailPanel.vue (props optionnelles + repli dataset) est retiré dans
    ce même chunk, ce composant n'a donc plus qu'un seul mode.

    Le lien de retour reste un <a href> classique vers la liste des
    campagnes (pas de ?id_campagne= côté serveur ici — c'est
    ContactsQueue.vue qui lit ce paramètre depuis window.location.search
    au montage pour présélectionner le filtre campagne, comportement
    inchangé) ; un petit script réécrit son href vers la campagne précise
    quand ce paramètre est présent, exactement comme le faisait l'ancienne
    Blade.
-->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import ContactsQueue from '../../components/livraison/contacts/ContactsQueue.vue';
import DetailPanel from '../../components/familles/DetailPanel.vue';
import type { Campagne, Organisation, Quartier, Secteur, Ville } from '../../components/livraison/shared/types';

interface ListeOption {
    id: number;
    code: string;
    libelle_fr: string;
    libelle_ar: string;
    libelle_en: string;
}

const props = defineProps<{
    campagnes: Campagne[];
    villes: Ville[];
    secteurs: Secteur[];
    quartiers: Quartier[];
    organisations: Organisation[];
    queueUrl: string;
    statistiquesUrl: string;
    assignerUrlTemplate: string;
    assignerLotUrl: string;
    contacterManuelUrlTemplate: string;
    retourUrl: string;
    showUrlTemplate: string;
    updateUrlTemplate: string;
    deverrouillerUrlTemplate: string;
    renouvelerVerrouUrlTemplate: string;
    forcerDeverrouillageUrlTemplate: string;
    uploadUrlTemplate: string;
    downloadUrlTemplate: string;
    deleteDocUrlTemplate: string;
    initialGooglePlacesKey: string;
    initialGoogleEmbedKey: string;
    secteursActivite: ListeOption[];
    organismesAide: ListeOption[];
}>();

// Repris tel quel de l'ancienne Blade (contacts.blade.php) : le lien de
// retour pointe vers la campagne précise quand l'écran a été ouvert
// depuis CampagneDetail.vue (?id_campagne=…) plutôt que vers la liste.
const retourHref = ref(props.retourUrl);
onMounted(() => {
    const idCampagneRetour = new URLSearchParams(window.location.search).get('id_campagne');
    if (idCampagneRetour) {
        retourHref.value = `/livraison/campagnes/${idCampagneRetour}`;
    }
});
</script>

<template>
    <Head title="Suivi des contacts — AMANA Familles" />

    <div class="max-w-5xl mx-auto py-8">
        <a :href="retourHref"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
            ← Retour à la campagne
        </a>

        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Suivi des contacts</h1>

        <ContactsQueue :campagnes="campagnes" :villes="villes" :secteurs="secteurs" :quartiers="quartiers"
            :organisations="organisations" :queue-url="queueUrl" :statistiques-url="statistiquesUrl"
            :assigner-url-template="assignerUrlTemplate" :assigner-lot-url="assignerLotUrl"
            :contacter-manuel-url-template="contacterManuelUrlTemplate" />
    </div>

    <DetailPanel :show-url-template="showUrlTemplate" :update-url-template="updateUrlTemplate"
        :deverrouiller-url-template="deverrouillerUrlTemplate"
        :renouveler-verrou-url-template="renouvelerVerrouUrlTemplate"
        :forcer-deverrouillage-url-template="forcerDeverrouillageUrlTemplate"
        :upload-url-template="uploadUrlTemplate" :download-url-template="downloadUrlTemplate"
        :delete-doc-url-template="deleteDocUrlTemplate" :initial-google-places-key="initialGooglePlacesKey"
        :initial-google-embed-key="initialGoogleEmbedKey" :initial-secteurs-activite-disponibles="secteursActivite"
        :initial-organismes-aide-disponibles="organismesAide" />
</template>
