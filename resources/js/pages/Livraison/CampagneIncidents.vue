<!-- resources/js/pages/Livraison/CampagneIncidents.vue -->
<!--
    Page Inertia « Incidents » d'une campagne (03/10/2026) — carte du hub,
    voir IncidentsController::index() et CampagneIncidents.vue.
-->
<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import CampagneIncidents from "../../components/livraison/campagnes/CampagneIncidents.vue";
import { CAMPAGNE_TYPES, type Campagne, type LigneIncident } from "../../components/livraison/shared/types";
import { formatDateFr } from "../../components/livraison/campagnes/campagneStyles";

defineProps<{
    campagne: Campagne;
    incidents: LigneIncident[];
    retourUrl: string;
    resoudreUrlTemplate: string;
    ignorerUrlTemplate: string;
}>();
</script>

<template>
    <Head title="Incidents — AMANA Familles" />

    <div class="max-w-3xl mx-auto py-8">
        <Link
            :href="retourUrl"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90"
        >
            ← Retour à la campagne
        </Link>

        <h1 class="font-heading text-xl font-semibold text-ink mb-1">Incidents</h1>
        <p class="text-[13px] text-ink-muted mb-6">
            {{ CAMPAGNE_TYPES[campagne.type] ?? campagne.type }} — {{ formatDateFr(campagne.date_livraison) }}
        </p>

        <CampagneIncidents
            :incidents="incidents"
            :resoudre-url-template="resoudreUrlTemplate"
            :ignorer-url-template="ignorerUrlTemplate"
        />
    </div>
</template>
