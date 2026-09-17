<!-- resources/js/pages/Livraison/Statistiques.vue -->
<!--
    Page Inertia "Statistiques livraison" — Section E4 du refactor
    (16/09/2026, quatrième chunk du domaine livraison), remplace
    resources/views/livraison/statistiques.blade.php (supprimée dans ce
    même chunk, plus aucun consommateur une fois
    StatistiquesController::index() converti en Inertia::render()).

    Le titre et le tableau de comparaison historique (auparavant rendus
    directement en Blade, voir le commentaire de l'ancienne vue) vivent
    ici ; le sélecteur + stats live restent dans LivraisonStatistiques.vue,
    désormais enfant Vue normal plutôt qu'îlot monté par app.ts.

    Le tableau historique est un port ligne à ligne de l'ancien
    @forelse Blade — même colonnes, même fallback '—', formatage déjà
    fait côté serveur (voir StatistiquesController::index()) plutôt que
    recalculé ici, pour rester fidèle à l'original plutôt que réécrit.
-->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import LivraisonStatistiques from '../../components/livraison/statistiques/LivraisonStatistiques.vue';
import type { Campagne } from '../../components/livraison/shared/types';

interface LigneHistorique {
    id: number;
    label: string;
    nombre_menages: number | null;
    poids_collecte_kg: number | null;
    taux_livraison_pourcentage: number | null;
}

defineProps<{
    campagnes: Campagne[];
    campagneSelectionneeId: number | null;
    donneesUrlTemplate: string;
    snapshotUrlTemplate: string;
    peutSnapshotter: boolean;
    historique: LigneHistorique[];
}>();
</script>

<template>
    <Head title="Statistiques livraison — AMANA Familles" />

    <div class="max-w-3xl mx-auto py-8">
        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Statistiques livraison</h1>

        <LivraisonStatistiques :campagnes="campagnes" :campagne-selectionnee-id="campagneSelectionneeId"
            :donnees-url-template="donneesUrlTemplate" :snapshot-url-template="snapshotUrlTemplate"
            :peut-snapshotter="peutSnapshotter" />

        <div class="bg-surface border border-surface-border rounded-xl p-5 mt-8">
            <h2 class="text-[14px] font-medium text-ink mb-4">Comparaison historique</h2>
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left text-ink-muted border-b border-surface-border">
                        <th class="py-2">Campagne</th>
                        <th>Ménages (donateurs)</th>
                        <th>Poids collecté</th>
                        <th>Taux livraison</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="historique.length === 0">
                        <td colspan="4" class="py-4 text-ink-muted">Aucun instantané enregistré pour le moment.</td>
                    </tr>
                    <tr v-for="ligne in historique" :key="ligne.id" class="border-b border-surface-border">
                        <td class="py-2">{{ ligne.label }}</td>
                        <td>{{ ligne.nombre_menages ?? '—' }}</td>
                        <td>{{ ligne.poids_collecte_kg ?? '—' }} kg</td>
                        <td>{{ ligne.taux_livraison_pourcentage !== null ? `${ligne.taux_livraison_pourcentage}%` : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
