<!-- resources/js/components/livraison/tableau-de-bord/ShortfallPanel.vue -->
<!--
    Livraisons confirmées jamais couvertes — panneau purement informatif,
    voir le prompt §3.3 point 7 ("do not silently drop anyone — raise a
    visible admin-board item"). C'est cette même liste qui alimente le
    picker "ajouter une livraison" de RoutesPanel.vue et le picker de
    livraisons de BuildRouteFlow.vue (voir LiveBoard.vue, qui passe
    non-couvertes en prop aux trois).
-->
<script setup lang="ts">
import type { Livraison } from '../shared/types';

defineProps<{
    livraisons: Livraison[];
    chargement: boolean;
    erreur: boolean;
}>();
</script>

<template>
    <div class="bg-surface border border-surface-border rounded-xl p-5">
        <h2 class="text-[14px] font-medium text-ink mb-3">Livraisons confirmées jamais couvertes</h2>
        <p v-if="chargement" class="text-[13px] text-ink-muted">Chargement…</p>
        <p v-else-if="erreur" class="text-[13px] text-rose-600">Impossible de charger cette liste.</p>
        <p v-else-if="livraisons.length === 0" class="text-[13px] text-ink-muted">Aucune.</p>
        <ul v-else class="text-[13px] text-ink-muted space-y-1">
            <li v-for="l in livraisons" :key="l.id">
                #{{ l.id }} — {{ l.famille.prenom }} {{ l.famille.nom }} ({{ l.famille.adresse }})
            </li>
        </ul>
    </div>
</template>
