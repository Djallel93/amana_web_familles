<!-- resources/js/pages/Livraison/SuiviLivraison.vue -->
<!--
    Page Inertia "Suivi livraison" — Section E4 du refactor (16/09/2026,
    septième et dernier chunk du domaine livraison), remplace
    resources/views/livraison/suivi-livraison.blade.php (supprimée dans
    ce même chunk, plus aucun consommateur une fois
    LiveBoardController::index() converti en Inertia::render()).

    Cette page ne porte que le lien de retour que portait la Blade ; tout
    le reste (sélecteur de campagne, cartes statistiques, incidents,
    tournées, non-couvertes, construction de tournée personnalisée) vit
    dans LiveBoard.vue et ses quatre panneaux, désormais enfants Vue
    normaux plutôt qu'un îlot monté par app.ts.

    retourUrl est calculé côté serveur exactement comme le faisait
    l'ancienne Blade (campagne connue → sa page détail, sinon la liste),
    voir le docblock de LiveBoardController::index().
-->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import LiveBoard from '../../components/livraison/tableau-de-bord/LiveBoard.vue';
import type { Campagne, Organisation, Quartier, Secteur, Ville } from '../../components/livraison/shared/types';

defineProps<{
    campagnes: Campagne[];
    campagneSelectionneeId: number | null;
    quartiers: Quartier[];
    villes: Ville[];
    secteurs: Secteur[];
    organisations: Organisation[];
    retourUrl: string;
    urls: Record<string, string>;
}>();
</script>

<template>
    <Head title="Suivi livraison — AMANA Familles" />

    <div class="max-w-5xl mx-auto py-8">
        <Link :href="retourUrl"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
        ← Retour à la campagne
        </Link>

        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Suivi livraison</h1>

        <LiveBoard :campagnes="campagnes" :campagne-selectionnee-id="campagneSelectionneeId" :quartiers="quartiers"
            :villes="villes" :secteurs="secteurs" :organisations="organisations" :urls="urls" />
    </div>
</template>
