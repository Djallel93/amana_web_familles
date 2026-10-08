<!-- resources/js/components/livraison/campagnes/CampagneIncidentsSection.vue -->
<!--
    Section « Incidents » repliable du hub de la campagne (06/10/2026) —
    remplace la carte « Incidents » et la page dédiée. Le titre porte un badge
    avec le nombre d'incidents ouverts (le hub le rafraîchit toutes les 20 s
    via /avancement) ; le détail (liste, filtres, résoudre/ignorer) ne se
    charge qu'à l'ouverture, puis se rafraîchit toutes les 20 s tant que la
    section reste ouverte et à chaque changement du compteur.
-->
<script setup lang="ts">
import { onUnmounted, ref, watch } from "vue";
import { apiGet } from "../shared/api";
import type { IncidentsUrls, LigneIncident } from "../shared/types";
import CampagneIncidents from "./CampagneIncidents.vue";

const props = defineProps<{
    urls: IncidentsUrls;
    /** Nombre d'incidents ouverts — vient de l'avancement du hub, déjà polled. */
    ouverts: number | null;
}>();

const emit = defineEmits<{ change: [] }>();

const deplie = ref(false);
const incidents = ref<LigneIncident[] | null>(null);
const erreur = ref(false);

async function charger() {
    const resultat = await apiGet<LigneIncident[]>(props.urls.liste);
    if (!resultat.ok) {
        erreur.value = true;
        return;
    }
    erreur.value = false;
    incidents.value = resultat.data;
}

let minuteur: ReturnType<typeof setInterval> | null = null;

watch(deplie, (ouverte) => {
    if (minuteur) {
        clearInterval(minuteur);
        minuteur = null;
    }
    if (!ouverte) return;
    void charger();
    minuteur = setInterval(() => {
        if (!document.hidden) void charger();
    }, 20000);
});

// Un incident ouvert/fermé ailleurs : le compteur bouge, la liste suit.
watch(
    () => props.ouverts,
    () => {
        if (deplie.value) void charger();
    },
);

onUnmounted(() => {
    if (minuteur) clearInterval(minuteur);
});
</script>

<template>
    <section class="mb-7">
        <button
            type="button"
            :aria-expanded="deplie"
            @click="deplie = !deplie"
            class="w-full flex items-center justify-between gap-3 rounded-xl border-2 px-4 py-3 text-left transition-colors"
            :class="
                (ouverts ?? 0) > 0
                    ? 'bg-rose-50 border-rose-300 hover:border-rose-400'
                    : 'bg-surface border-surface-border hover:border-accent'
            "
        >
            <span class="flex items-center gap-2">
                <span class="text-[20px] leading-none">⚠️</span>
                <span class="text-[15px] font-semibold text-ink">Incidents</span>
                <span
                    v-if="ouverts !== null"
                    class="text-[11px] font-semibold px-2 py-0.5 rounded-full"
                    :class="ouverts > 0 ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'"
                >
                    {{ ouverts > 0 ? `${ouverts} ouvert(s)` : "Aucun ouvert" }}
                </span>
            </span>
            <span
                class="text-ink-muted text-[13px] transition-transform duration-200"
                :class="deplie ? 'rotate-180' : ''"
                >▾</span
            >
        </button>

        <div v-if="deplie" class="mt-3">
            <p v-if="erreur" class="text-[13px] text-rose-600">Impossible de charger les incidents.</p>
            <p v-else-if="incidents === null" class="text-[13px] text-ink-muted">Chargement…</p>
            <CampagneIncidents
                v-else
                :incidents="incidents"
                :resoudre-url-template="urls.resoudre"
                :ignorer-url-template="urls.ignorer"
                @change="emit('change')"
            />
        </div>
    </section>
</template>
