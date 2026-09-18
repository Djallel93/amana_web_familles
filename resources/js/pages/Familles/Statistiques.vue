<!-- resources/js/pages/Familles/Statistiques.vue -->
<!--
    Page Inertia "Statistiques" (dossiers familles) — Section E4 du
    refactor (16/09/2026, chunk imports+FamillesStatistiques), remplace
    resources/views/familles/statistiques.blade.php (supprimée dans ce
    même chunk, plus aucun consommateur une fois
    StatistiquesFamillesController::index() converti en
    Inertia::render()).

    Cette page ne porte que le titre/sous-titre que portait la Blade ;
    tout le reste (cartes + graphiques Chart.js) vit dans
    FamillesStatistiques.vue, désormais enfant Vue normal plutôt qu'îlot
    monté par app.ts.
-->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import FamillesStatistiques from '../../components/familles/FamillesStatistiques.vue';

defineProps<{
    dataUrl: string;
}>();
</script>

<template>
    <Head title="Statistiques — AMANA Familles" />

    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Statistiques</h1>
            <p class="text-[13px] text-ink-muted mt-1">
                Vue d'ensemble des dossiers familles — répartition, éligibilité, criticité, géographie
            </p>
        </div>
    </div>

    <FamillesStatistiques :data-url="dataUrl" />
</template>
