<!-- resources/js/components/livraison/campagnes/ClotureDialog.vue -->
<!--
    Fenêtre « Terminer la campagne » du hub (03/10/2026). Au moment de
    l'ouverture, demande le pré-contrôle (CampagnesController::cloture()) et
    affiche l'un de ces états :

      - BLOQUÉ : au moins une tournée n'est ni terminée ni annulée — liste
        des tournées, lien vers Suivi livraison, pas de bouton de
        confirmation (le serveur refuse aussi : terminer() renvoie 422) ;
      - AVERTISSEMENT incidents : des incidents sont ouverts — ils ne
        bloquent pas, mais un bouton « Tout résoudre de force » (avec
        seconde confirmation) les passe tous à « résolu » sans re-clustering ;
      - sinon : récapitulatif et confirmation.

    Fenêtre propre plutôt que useConfirm() : l'état bloqué n'a pas de bouton
    de confirmation et l'avertissement porte une action secondaire.
-->
<script setup lang="ts">
import { ref, watch } from "vue";
import { Modal, useConfirm, useToast } from "@amana/shared-ui";
import { apiGet, apiPost } from "../shared/api";

interface Cloture {
    statut: string;
    routes_non_terminees: { id: number; statut: string; benevole: string | null }[];
    incidents_ouverts: number;
    livraisons_en_attente: number;
}

const props = defineProps<{
    open: boolean;
    clotureUrl: string;
    terminerUrl: string;
    forcerIncidentsUrl: string;
    suiviLivraisonUrl: string;
}>();

const emit = defineEmits<{ close: []; terminee: []; incidentsResolus: [] }>();

const toast = useToast();
const confirmDialog = useConfirm();

const cloture = ref<Cloture | null>(null);
const chargement = ref(false);
const erreur = ref("");
const enCours = ref(false);

const LABELS_STATUT_ROUTE: Record<string, string> = {
    planifiee: "Planifiée",
    chargement: "Chargement",
    charge: "Chargée",
    en_cours: "En cours",
    livraisons_terminees: "Livraisons terminées",
    packaging_annule: "Packaging annulé",
};

async function charger() {
    chargement.value = true;
    erreur.value = "";
    cloture.value = null;

    const resultat = await apiGet<Cloture>(props.clotureUrl);
    chargement.value = false;

    if (!resultat.ok) {
        erreur.value = resultat.message;
        return;
    }
    cloture.value = resultat.data;
}

// Recalculé à CHAQUE ouverture : l'état d'une campagne bouge vite le jour J.
watch(
    () => props.open,
    (ouvert) => {
        if (ouvert) void charger();
    },
);

async function forcerIncidents() {
    const confirmed = await confirmDialog.ask({
        title: "Résoudre tous les incidents ?",
        message:
            `${cloture.value?.incidents_ouverts ?? 0} incident(s) ouvert(s) vont être marqués « résolus » sans aucune action ` +
            "(pas de re-clustering des tournées). Cette opération est définitive.",
        confirmLabel: "Tout résoudre",
        danger: true,
    });
    if (!confirmed) return;

    enCours.value = true;
    const resultat = await apiPost<{ success: boolean; resolus: number }>(props.forcerIncidentsUrl);
    enCours.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    toast.success(`${resultat.data.resolus} incident(s) résolu(s).`);
    if (cloture.value) cloture.value = { ...cloture.value, incidents_ouverts: 0 };
    emit("incidentsResolus");
}

async function terminer() {
    enCours.value = true;
    const resultat = await apiPost<{ success: boolean }>(props.terminerUrl);
    enCours.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        // Un 422 « tournées non terminées » : l'état a changé depuis l'ouverture.
        void charger();
        return;
    }

    toast.success("Campagne terminée.");
    emit("terminee");
}
</script>

<template>
    <Modal :open="open" max-width="max-w-lg" @close="emit('close')">
        <h2 class="text-[16px] font-semibold text-ink mb-3">Terminer la campagne</h2>

        <p v-if="chargement" class="text-[13px] text-ink-muted py-4">Vérification de l'état de la campagne…</p>

        <div v-else-if="erreur" class="text-[13px] text-rose-600 py-2">
            {{ erreur }}
            <button type="button" class="underline ml-1" @click="charger">Réessayer</button>
        </div>

        <template v-else-if="cloture">
            <!-- BLOQUÉ : tournées non terminées -->
            <div v-if="cloture.routes_non_terminees.length > 0">
                <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 mb-3 text-[13px] text-rose-800">
                    <p class="font-semibold">
                        🚫 {{ cloture.routes_non_terminees.length }} tournée(s) ne sont pas terminées.
                    </p>
                    <p class="mt-0.5">Terminez-les ou annulez-les avant de clôturer la campagne.</p>
                </div>
                <ul class="space-y-1 mb-4 text-[13px]">
                    <li
                        v-for="route in cloture.routes_non_terminees"
                        :key="route.id"
                        class="flex justify-between gap-3 rounded-lg bg-surface-2 px-3 py-2"
                    >
                        <span class="font-medium text-ink"
                            >Tournée #{{ route.id
                            }}<template v-if="route.benevole"> · {{ route.benevole }}</template></span
                        >
                        <span class="text-ink-muted shrink-0">{{
                            LABELS_STATUT_ROUTE[route.statut] ?? route.statut
                        }}</span>
                    </li>
                </ul>
                <div class="flex flex-wrap justify-end gap-2">
                    <a
                        :href="suiviLivraisonUrl"
                        class="px-4 py-2 rounded-lg border border-surface-border text-[13px] text-ink hover:bg-stone-50 no-underline"
                        >Ouvrir Suivi livraison</a
                    >
                    <button
                        type="button"
                        @click="emit('close')"
                        class="px-4 py-2 rounded-lg bg-ink text-white text-[13px] font-semibold"
                    >
                        Fermer
                    </button>
                </div>
            </div>

            <!-- Confirmation -->
            <div v-else>
                <p class="text-[13.5px] text-ink mb-3">
                    Toutes les tournées sont terminées. La campagne sera marquée comme terminée ; vous pourrez la
                    rouvrir si besoin.
                </p>

                <div
                    v-if="cloture.incidents_ouverts > 0"
                    class="rounded-lg border border-amber-300 bg-amber-50 p-3 mb-3 text-[13px] text-amber-900"
                >
                    <p class="font-semibold">⚠️ {{ cloture.incidents_ouverts }} incident(s) encore ouvert(s)</p>
                    <p class="mt-0.5">
                        Ils ne bloquent pas la clôture, mais resteront ouverts. Vous pouvez les résoudre de force
                        maintenant.
                    </p>
                    <button
                        type="button"
                        :disabled="enCours"
                        @click="forcerIncidents"
                        class="mt-2 px-3 py-1.5 rounded-lg border border-amber-400 bg-white text-[12.5px] font-semibold text-amber-900 hover:bg-amber-100 disabled:opacity-60"
                    >
                        Tout résoudre de force
                    </button>
                </div>

                <p v-if="cloture.livraisons_en_attente > 0" class="text-[12.5px] text-ink-muted mb-3">
                    ℹ️ {{ cloture.livraisons_en_attente }} livraison(s) confirmée(s) n'ont été ni livrées ni ignorées.
                </p>

                <div class="flex flex-wrap justify-end gap-2 mt-4">
                    <button
                        type="button"
                        @click="emit('close')"
                        class="px-4 py-2 rounded-lg border border-surface-border text-[13px] text-ink-muted hover:bg-stone-50"
                    >
                        Annuler
                    </button>
                    <button
                        type="button"
                        :disabled="enCours"
                        @click="terminer"
                        class="px-4 py-2 rounded-lg bg-accent text-white text-[13px] font-semibold disabled:opacity-60"
                    >
                        {{ enCours ? "Clôture…" : "Terminer la campagne" }}
                    </button>
                </div>
            </div>
        </template>
    </Modal>
</template>
