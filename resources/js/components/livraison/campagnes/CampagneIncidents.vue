<!-- resources/js/components/livraison/campagnes/CampagneIncidents.vue -->
<!--
    Incidents d'une campagne (03/10/2026) — listés dans la section repliable
    « Incidents » du hub depuis le 06/10/2026 (CampagneIncidentsSection.vue ;
    plus de page dédiée) : tous
    les incidents avec leur statut, filtre par statut (DÉFAUT : ouverts) et
    tri par date (récents d'abord, inversable). Clic sur une ligne : fenêtre
    avec la description et, tant que l'incident est ouvert, « Résoudre » et
    « Ignorer ». Un incident résolu ou ignoré n'est pas rouvert (décision du
    03/10/2026) : si le problème revient, un nouvel incident est ouvert.

    « Résoudre » garde le comportement historique (LiveBoardController::
    resoudreIncident()) : pour benevole_absent, confirmation puis re-
    clustering des arrêts orphelins. « Ignorer » ferme sans effet de bord.
    Le guide de résolution pas à pas viendra dans une évolution ultérieure :
    l'emplacement est déjà réservé dans la fenêtre.
-->
<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { Modal, useConfirm, useToast } from "@amana/shared-ui";
import { apiPost } from "../shared/api";
import type { LigneIncident, ResoudreIncidentResultat } from "../shared/types";

const props = defineProps<{
    incidents: LigneIncident[];
    resoudreUrlTemplate: string;
    ignorerUrlTemplate: string;
}>();

const emit = defineEmits<{
    /** Un incident a été résolu/ignoré : le hub rafraîchit son compteur. */
    change: [];
}>();

const toast = useToast();
const confirmDialog = useConfirm();

const lignes = ref<LigneIncident[]>([...props.incidents]);

// La liste est rechargée par le parent (polling) pendant que la section est ouverte.
watch(
    () => props.incidents,
    (nouvelles) => {
        lignes.value = [...nouvelles];
        if (selection.value) {
            const maj = nouvelles.find((l) => l.id === selection.value?.id);
            if (maj) selection.value = maj;
        }
    },
);

type Filtre = "ouvert" | "ignore" | "resolu" | "tous";

const STATUTS: { id: Filtre; label: string }[] = [
    { id: "ouvert", label: "Ouverts" },
    { id: "ignore", label: "Fermés (ignorés)" },
    { id: "resolu", label: "Résolus" },
    { id: "tous", label: "Tous" },
];

const STYLE_STATUT: Record<LigneIncident["statut"], string> = {
    ouvert: "bg-rose-100 text-rose-700 border-rose-200",
    ignore: "bg-stone-100 text-stone-600 border-stone-200",
    resolu: "bg-emerald-100 text-emerald-700 border-emerald-200",
};

const LABEL_STATUT: Record<LigneIncident["statut"], string> = {
    ouvert: "Ouvert",
    ignore: "Fermé (ignoré)",
    resolu: "Résolu",
};

const filtre = ref<Filtre>("ouvert"); // défaut : seulement les ouverts
const recentsDabord = ref(true);

const compteurs = computed(() => ({
    ouvert: lignes.value.filter((l) => l.statut === "ouvert").length,
    ignore: lignes.value.filter((l) => l.statut === "ignore").length,
    resolu: lignes.value.filter((l) => l.statut === "resolu").length,
    tous: lignes.value.length,
}));

const visibles = computed(() => {
    const filtrees = lignes.value.filter((l) => filtre.value === "tous" || l.statut === filtre.value);
    const dir = recentsDabord.value ? -1 : 1;
    return [...filtrees].sort((a, b) => dir * ((a.created_at ?? "") < (b.created_at ?? "") ? -1 : 1));
});

function formatDateHeure(iso: string | null): string {
    if (!iso) return "—";
    return new Date(iso).toLocaleString("fr-FR", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
}

// ── Fenêtre de détail ───────────────────────────────────────────────────
const selection = ref<LigneIncident | null>(null);
const enCours = ref(false);

function ouvrir(incident: LigneIncident) {
    selection.value = incident;
}

function majStatut(id: number, statut: LigneIncident["statut"]) {
    lignes.value = lignes.value.map((l) => (l.id === id ? { ...l, statut } : l));
    if (selection.value?.id === id) selection.value = { ...selection.value, statut };
}

async function resoudre(incident: LigneIncident) {
    if (incident.type === "benevole_absent") {
        const confirmed = await confirmDialog.ask({
            title: "Résoudre cet incident",
            message:
                "Les livraisons orphelines de cette tournée vont être relancées dans un nouveau cycle de clustering. Continuer ?",
            confirmLabel: "Résoudre et relancer",
        });
        if (!confirmed) return;
    }

    enCours.value = true;
    const resultat = await apiPost<ResoudreIncidentResultat & { success: boolean }>(
        props.resoudreUrlTemplate.replace("__ID__", String(incident.id)),
    );
    enCours.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    majStatut(incident.id, "resolu");
    emit("change");
    toast.success(
        resultat.data.routes_creees !== undefined
            ? `Résolu. ${resultat.data.routes_creees} nouvelle(s) tournée(s), ${resultat.data.non_couvertes} non couverte(s).`
            : "Incident résolu.",
    );
}

async function ignorer(incident: LigneIncident) {
    const confirmed = await confirmDialog.ask({
        title: "Ignorer cet incident ?",
        message: "L'incident sera fermé sans être traité (aucune action n'est lancée). Il ne pourra pas être rouvert.",
        confirmLabel: "Ignorer",
    });
    if (!confirmed) return;

    enCours.value = true;
    const resultat = await apiPost<{ success: boolean }>(
        props.ignorerUrlTemplate.replace("__ID__", String(incident.id)),
    );
    enCours.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    majStatut(incident.id, "ignore");
    emit("change");
    toast.success("Incident fermé (ignoré).");
}
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div class="flex flex-wrap gap-1.5" role="tablist" aria-label="Filtrer par statut">
                <button
                    v-for="s in STATUTS"
                    :key="s.id"
                    type="button"
                    role="tab"
                    :aria-selected="filtre === s.id"
                    @click="filtre = s.id"
                    class="px-3 py-1.5 rounded-full text-[12.5px] font-medium border transition-colors"
                    :class="
                        filtre === s.id
                            ? 'bg-ink text-white border-ink'
                            : 'bg-surface text-ink-muted border-surface-border hover:bg-stone-50'
                    "
                >
                    {{ s.label }} <span class="font-bold">{{ compteurs[s.id] }}</span>
                </button>
            </div>
            <button
                type="button"
                @click="recentsDabord = !recentsDabord"
                class="text-[12.5px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted hover:bg-stone-50"
            >
                Date : {{ recentsDabord ? "récents d'abord ↓" : "anciens d'abord ↑" }}
            </button>
        </div>

        <div class="space-y-2">
            <button
                v-for="incident in visibles"
                :key="incident.id"
                type="button"
                @click="ouvrir(incident)"
                class="w-full text-left flex items-center justify-between gap-3 bg-surface border border-surface-border hover:border-accent rounded-xl p-4 transition-colors"
            >
                <div class="min-w-0">
                    <p class="text-[14px] font-medium text-ink truncate">{{ incident.type_label }}</p>
                    <p class="text-[12.5px] text-ink-muted truncate">
                        Tournée #{{ incident.id_route
                        }}<template v-if="incident.chauffeur"> · {{ incident.chauffeur }}</template
                        ><template v-if="incident.famille"> · {{ incident.famille }}</template>
                    </p>
                    <p class="text-[12px] text-ink-muted">{{ formatDateHeure(incident.created_at) }}</p>
                </div>
                <span
                    class="shrink-0 text-[11px] font-semibold px-2.5 py-1 rounded-full border"
                    :class="STYLE_STATUT[incident.statut]"
                >
                    {{ LABEL_STATUT[incident.statut] }}
                </span>
            </button>
            <p v-if="visibles.length === 0" class="text-[14px] text-ink-muted py-6 text-center">
                {{ filtre === "ouvert" ? "Aucun incident ouvert. 🎉" : "Aucun incident pour ce filtre." }}
            </p>
        </div>

        <Modal :open="selection !== null" max-width="max-w-lg" @close="selection = null">
            <div v-if="selection">
                <div class="flex items-start justify-between gap-3 mb-3">
                    <h2 class="text-[16px] font-semibold text-ink">{{ selection.type_label }}</h2>
                    <span
                        class="shrink-0 text-[11px] font-semibold px-2.5 py-1 rounded-full border"
                        :class="STYLE_STATUT[selection.statut]"
                    >
                        {{ LABEL_STATUT[selection.statut] }}
                    </span>
                </div>

                <p class="text-[13.5px] text-ink mb-4">{{ selection.description }}</p>

                <dl
                    class="divide-y divide-surface-3 rounded-lg border border-surface-border bg-surface-2 text-[13px] mb-4"
                >
                    <div class="flex justify-between gap-4 px-3 py-2">
                        <dt class="text-ink-muted">Tournée</dt>
                        <dd class="font-medium text-ink">#{{ selection.id_route }}</dd>
                    </div>
                    <div v-if="selection.chauffeur" class="flex justify-between gap-4 px-3 py-2">
                        <dt class="text-ink-muted">Chauffeur</dt>
                        <dd class="font-medium text-ink">{{ selection.chauffeur }}</dd>
                    </div>
                    <div v-if="selection.famille" class="flex justify-between gap-4 px-3 py-2">
                        <dt class="text-ink-muted">Famille</dt>
                        <dd class="font-medium text-ink">{{ selection.famille }}</dd>
                    </div>
                    <div v-if="selection.signale_par" class="flex justify-between gap-4 px-3 py-2">
                        <dt class="text-ink-muted">Signalé par</dt>
                        <dd class="font-medium text-ink">{{ selection.signale_par }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-3 py-2">
                        <dt class="text-ink-muted">Date</dt>
                        <dd class="font-medium text-ink">{{ formatDateHeure(selection.created_at) }}</dd>
                    </div>
                </dl>

                <!-- Emplacement réservé : le guide de résolution (explications pas à pas) sera ajouté dans une évolution ultérieure. -->
                <div class="rounded-lg border border-dashed border-surface-border p-3 mb-4">
                    <p class="text-[12px] font-semibold uppercase tracking-wide text-ink-muted mb-1">
                        Guide de résolution
                    </p>
                    <p class="text-[13px] text-ink-muted whitespace-pre-line">
                        {{
                            selection.guide ?? "Le guide pas à pas pour résoudre cet incident sera bientôt disponible."
                        }}
                    </p>
                </div>

                <p v-if="selection.notes" class="text-[12px] text-ink-muted whitespace-pre-line mb-4">
                    {{ selection.notes }}
                </p>

                <div class="flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        @click="selection = null"
                        class="px-4 py-2 rounded-lg border border-surface-border text-[13px] text-ink-muted hover:bg-stone-50"
                    >
                        Fermer
                    </button>
                    <template v-if="selection.statut === 'ouvert'">
                        <button
                            type="button"
                            :disabled="enCours"
                            @click="ignorer(selection)"
                            class="px-4 py-2 rounded-lg border border-surface-border text-[13px] text-ink hover:bg-stone-50 disabled:opacity-60"
                        >
                            Ignorer
                        </button>
                        <button
                            type="button"
                            :disabled="enCours"
                            @click="resoudre(selection)"
                            class="px-4 py-2 rounded-lg bg-accent text-white text-[13px] font-semibold disabled:opacity-60"
                        >
                            Résoudre
                        </button>
                    </template>
                </div>
            </div>
        </Modal>
    </div>
</template>
