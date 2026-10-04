<!-- resources/js/pages/Livraison/CampagneFamilles.vue -->
<!--
    Page Inertia « Sélection des familles » (03/10/2026) — voir
    CampagnesController::familles() et CampagneFamilles.vue. Sans campagne
    (entrée de la barre latérale), propose d'en choisir une, comme Suivi
    livraison propose son sélecteur.
-->
<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import CampagneFamilles from "../../components/livraison/campagnes/CampagneFamilles.vue";
import {
    CAMPAGNE_TYPES,
    type Campagne,
    type Organisation,
    type Quartier,
    type Secteur,
    type Ville,
} from "../../components/livraison/shared/types";
import {
    CAMPAGNE_STATUT_LABELS,
    CAMPAGNE_TYPE_STYLES,
    formatDateFr,
} from "../../components/livraison/campagnes/campagneStyles";

defineProps<{
    campagne: Campagne | null;
    campagnes: Campagne[];
    quartiers: Quartier[];
    villes: Ville[];
    secteurs: Secteur[];
    organisations: Organisation[];
    retourUrl: string;
    urlsCampagne: { eligibles: string; genererLivraisons: string } | null;
    choisirUrlTemplate: string;
}>();
</script>

<template>
    <Head title="Sélection des familles — AMANA Familles" />

    <div class="max-w-5xl mx-auto py-8">
        <Link
            :href="retourUrl"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90"
        >
            {{ campagne ? "← Retour à la campagne" : "← Retour aux campagnes" }}
        </Link>

        <template v-if="campagne && urlsCampagne">
            <h1 class="font-heading text-xl font-semibold text-ink mb-1">Sélection des familles</h1>
            <p class="text-[13px] text-ink-muted mb-6">
                {{ CAMPAGNE_TYPES[campagne.type] ?? campagne.type }} — {{ formatDateFr(campagne.date_livraison) }}
            </p>
            <CampagneFamilles
                :key="campagne.id"
                :campagne="campagne"
                :quartiers="quartiers"
                :villes="villes"
                :secteurs="secteurs"
                :organisations="organisations"
                :urls="urlsCampagne"
            />
        </template>

        <template v-else>
            <h1 class="font-heading text-xl font-semibold text-ink mb-1">Sélection des familles</h1>
            <p class="text-[13px] text-ink-muted mb-6">
                Choisissez la campagne pour laquelle sélectionner des familles.
            </p>
            <div class="space-y-2">
                <Link
                    v-for="c in campagnes"
                    :key="c.id"
                    :href="choisirUrlTemplate.replace('__CAMPAGNE__', String(c.id))"
                    class="flex items-center gap-2 bg-surface border border-surface-border hover:border-accent rounded-xl p-4 no-underline"
                >
                    <span
                        class="shrink-0 text-[11px] font-medium px-2 py-0.5 rounded-full border"
                        :class="CAMPAGNE_TYPE_STYLES[c.type]?.pastille"
                        >{{ CAMPAGNE_TYPES[c.type] ?? c.type }}</span
                    >
                    <span class="text-[14px] font-medium text-ink">{{ formatDateFr(c.date_livraison) }}</span>
                    <span class="text-[12px] text-ink-muted">{{ CAMPAGNE_STATUT_LABELS[c.statut] ?? c.statut }}</span>
                </Link>
                <p v-if="campagnes.length === 0" class="text-[14px] text-ink-muted">Aucune campagne.</p>
            </div>
        </template>
    </div>
</template>
