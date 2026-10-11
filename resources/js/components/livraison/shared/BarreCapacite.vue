<!-- resources/js/components/livraison/shared/BarreCapacite.vue -->
<!--
    Barre de remplissage « charge / capacité » (10/10/2026) — assistant de génération des
    routes : mode personnalisé (poids ET colis du véhicule du chauffeur, qui se remplit au fil
    des familles cochées) et récapitulatif du mode automatique (flotte entière).

    Seuils : vert en dessous de 90 %, ambre de 90 à 100 %, rouge au-delà. Le dépassement reste
    permis (décision produit) : la barre déborde visuellement, passe au rouge et le parent
    affiche l'avertissement — ce composant ne bloque rien. Une capacité nulle avec une charge
    non nulle compte comme un dépassement.
-->
<script setup lang="ts">
import { computed } from "vue";

const props = defineProps<{
    libelle: string;
    valeur: number;
    /** Capacité maximale (même unité que `valeur`). */
    max: number;
    unite: string;
}>();

const ratio = computed(() => (props.max > 0 ? props.valeur / props.max : props.valeur > 0 ? Infinity : 0));
const depasse = computed(() => ratio.value > 1);
const proche = computed(() => ratio.value >= 0.9 && !depasse.value);
const largeur = computed(() => `${Math.min(100, Math.round(ratio.value * 100))}%`);
const pourcentage = computed(() => (Number.isFinite(ratio.value) ? `${Math.round(ratio.value * 100)} %` : "—"));

const classeBarre = computed(() => (depasse.value ? "bg-rose-500" : proche.value ? "bg-amber-500" : "bg-emerald-500"));
const classeTexte = computed(() =>
    depasse.value ? "text-rose-700" : proche.value ? "text-amber-700" : "text-ink-muted",
);
</script>

<template>
    <div>
        <div class="flex items-baseline justify-between gap-2 text-[12px]">
            <span class="font-medium text-ink">{{ libelle }}</span>
            <span :class="classeTexte" class="tabular-nums">
                {{ valeur }} / {{ max }} {{ unite }} · {{ pourcentage }}
                <span v-if="depasse" aria-hidden="true">⚠️</span>
            </span>
        </div>
        <div
            class="mt-1 h-2.5 w-full overflow-hidden rounded-full bg-stone-200"
            role="progressbar"
            :aria-label="libelle"
            :aria-valuenow="valeur"
            aria-valuemin="0"
            :aria-valuemax="max"
        >
            <div
                class="h-full rounded-full transition-all duration-300 ease-out"
                :class="classeBarre"
                :style="{ width: largeur }"
            ></div>
        </div>
    </div>
</template>
