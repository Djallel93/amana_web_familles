<!-- resources/js/components/livraison/tableau-de-bord/ShortfallPanel.vue -->
<!--
    Livraisons confirmées jamais couvertes — panneau purement informatif,
    voir le prompt §3.3 point 7 ("do not silently drop anyone — raise a
    visible admin-board item"). C'est cette même liste qui alimente le
    picker "ajouter une livraison" de RoutesPanel.vue et le picker de
    livraisons de BuildRouteFlow.vue (voir LiveBoard.vue, qui passe
    non-couvertes en prop aux trois).

    30/09/2026 : section repliable (repliée par défaut) avec deux pills
    toujours visibles dans l'en-tête — total « Se déplace au QG » et total
    des livraisons réellement non assignées. Les familles se_deplace n'ont
    pas de tournée (elles viennent chercher leur colis au QG) : elles portent
    un pill et passent en bas de liste, au cas où elles ne pourraient finalement
    pas venir et qu'une tournée personnalisée serait à créer pour elles.
    Les compteurs se rafraîchissent avec le polling de LiveBoard.vue.
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Livraison } from '../shared/types';

const props = defineProps<{
    livraisons: Livraison[];
    chargement: boolean;
    erreur: boolean;
}>();

const ouvert = ref(false);

// Non assignées en premier, se_deplace en bas (tri stable : l'ordre serveur
// est conservé à l'intérieur de chaque groupe).
const triees = computed(() => [...props.livraisons].sort((a, b) => Number(a.se_deplace) - Number(b.se_deplace)));
const totalSeDeplace = computed(() => props.livraisons.filter((l) => l.se_deplace).length);
const totalNonAssignees = computed(() => props.livraisons.length - totalSeDeplace.value);
</script>

<template>
    <div class="bg-surface border border-surface-border rounded-xl p-5">
        <button type="button" class="w-full flex items-center justify-between gap-3 text-left"
            :aria-expanded="ouvert" @click="ouvert = !ouvert">
            <h2 class="text-[14px] font-medium text-ink">Livraisons confirmées jamais couvertes</h2>
            <span class="flex items-center gap-2 shrink-0">
                <template v-if="!chargement && !erreur">
                    <span class="text-[12px] px-2.5 py-0.5 rounded-full bg-sky-100 text-sky-700"
                        title="Familles qui viennent chercher leur colis au QG">
                        Se déplace au QG : {{ totalSeDeplace }}
                    </span>
                    <span class="text-[12px] px-2.5 py-0.5 rounded-full"
                        :class="totalNonAssignees > 0 ? 'bg-amber-100 text-amber-700' : 'bg-stone-100 text-stone-600'"
                        title="Livraisons confirmées sans tournée">
                        Non assignées : {{ totalNonAssignees }}
                    </span>
                </template>
                <span class="text-ink-muted text-[12px]" aria-hidden="true">{{ ouvert ? '▲' : '▼' }}</span>
            </span>
        </button>

        <div v-if="ouvert" class="mt-3">
            <p v-if="chargement" class="text-[13px] text-ink-muted">Chargement…</p>
            <p v-else-if="erreur" class="text-[13px] text-rose-600">Impossible de charger cette liste.</p>
            <p v-else-if="livraisons.length === 0" class="text-[13px] text-ink-muted">Aucune.</p>
            <ul v-else class="text-[13px] text-ink-muted space-y-1">
                <li v-for="l in triees" :key="l.id" class="flex items-center gap-2 flex-wrap">
                    <span>#{{ l.id }} — {{ l.famille.prenom }} {{ l.famille.nom }} ({{ l.famille.adresse }})</span>
                    <span v-if="l.se_deplace" class="text-[11px] px-2 py-0.5 rounded-full bg-sky-100 text-sky-700">
                        Se déplace au QG
                    </span>
                </li>
            </ul>
        </div>
    </div>
</template>
