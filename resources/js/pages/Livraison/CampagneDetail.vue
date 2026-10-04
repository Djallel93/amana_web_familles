<!-- resources/js/pages/Livraison/CampagneDetail.vue -->
<!--
    Page Inertia « Campagne » — hub de la campagne depuis le 03/10/2026 (voir
    CampagneDetail.vue et CampagnesController::show()). Historique : Section
    E4 du refactor (16/09/2026) l'avait convertie depuis la Blade
    campagne-detail ; elle ne porte toujours que le lien de retour, tout le
    reste vit dans le composant.

    Retour : même gabarit que les autres pages (lien plein bg-ink).
-->
<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import CampagneDetail from "../../components/livraison/campagnes/CampagneDetail.vue";
import type { Campagne } from "../../components/livraison/shared/types";

defineProps<{
    campagne: Campagne;
    retourUrl: string;
    avancementUrl: string;
    clotureUrl: string;
    terminerUrl: string;
    rouvrirUrl: string;
    forcerIncidentsUrl: string;
    urls: {
        statistiques: string;
        parametres: string;
        familles: string;
        contacts: string;
        benevoles: string;
        reception: string;
        pesee: string;
        packaging: string;
        chargement: string;
        retraitHq: string;
        suiviLivraison: string;
        incidents: string;
    };
}>();
</script>

<template>
    <Head title="Campagne — AMANA Familles" />

    <div class="max-w-4xl mx-auto py-8">
        <Link
            :href="retourUrl"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90"
        >
            ← Retour aux campagnes
        </Link>

        <CampagneDetail
            :campagne="campagne"
            :avancement-url="avancementUrl"
            :cloture-url="clotureUrl"
            :terminer-url="terminerUrl"
            :rouvrir-url="rouvrirUrl"
            :forcer-incidents-url="forcerIncidentsUrl"
            :urls="urls"
        />
    </div>
</template>
