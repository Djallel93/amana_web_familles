<!-- resources/js/components/livraison/tableau-de-bord/GenererRoutesPanel.vue -->
<!--
    Clustering / génération des routes — déplacé de la page détail campagne
    vers Suivi livraison le 03/10/2026 (la page campagne devient un hub) :
    c'est le début de la phase livraison, et le rappel de la page Chargement
    (« générez d'abord les routes ») pointait déjà vers Suivi livraison alors
    qu'aucun bouton n'y existait. Code et règles inchangés (09/09/2026,
    prompt §2.2 + §1.5) : journée choisie, plus aucune famille à contacter
    pour cette journée, confirmation avant lancement, vérification refaite
    côté serveur (LiveBoardController::genererRoutes()).
-->
<script setup lang="ts">
import { ref, computed, watch, onMounted } from "vue";
import { useToast, useConfirm } from "@amana/shared-ui";
import { apiGet, apiPost, buildQuery } from "../shared/api";
import type { CampagneJournee, GenererRoutesResultat } from "../shared/types";

const toast = useToast();
const confirmDialog = useConfirm();

const props = defineProps<{
    campagneId: number;
    journees: CampagneJournee[];
    urls: { genererRoutes: string; queue: string; contactsStatistiques: string };
}>();

const emit = defineEmits<{ generated: [] }>();

function formatDateFr(iso: string): string {
    const [annee, mois, jour] = iso.split("T")[0].split("-");
    return `${jour}/${mois}/${annee}`;
}

const idJourneeSelectionnee = ref<number | null>(props.journees[0]?.id ?? null);

// La campagne affichée peut changer sans démonter ce panneau : on repart de
// sa première journée.
watch(() => props.campagneId, () => {
    idJourneeSelectionnee.value = props.journees[0]?.id ?? null;
    resultatRoutes.value = null;
    erreurRoutes.value = "";
    verifierGateClustering();
});

// ── Clustering / génération des routes (09/09/2026, prompt de cette date
// §2.2 : déplacé ici depuis ContactsQueue.vue) ───────────────────────────
// Gate : plus aucune livraison à statut_contact = 'a_contacter' pour la
// journée choisie — revérifié aussi côté serveur (voir
// LiveBoardController::genererRoutes()), ce calcul côté Vue ne sert qu'à
// griser le bouton avant même de tenter l'appel. Même logique que
// l'ancienne verifierGateClustering() de ContactsQueue.vue.
//
// aucuneLivraison ajouté le 09/09/2026 (prompt de cette date §1.5) : le
// bouton restait cliquable tant qu'AUCUNE famille n'avait encore été
// ajoutée à la journée (resteAContacter tombe à 0 "par le vide" — aucune
// ligne 'a_contacter' puisqu'aucune ligne du tout), menant à un
// "0 livraison créé" inoffensif mais confus plutôt qu'un vrai refus.
// Distingue donc désormais "rien à contacter parce que tout est
// contacté" de "rien à contacter parce qu'il n'y a rien du tout" via le
// total (contacts.statistiques, même filtre id_campagne/
// id_campagne_journee que resteAContacter ci-dessus).
const chargementVerifGate = ref(false);
const resteAContacter = ref<number | null>(null);
const aucuneLivraison = ref<boolean | null>(null);

async function verifierGateClustering() {
    if (idJourneeSelectionnee.value === null) {
        resteAContacter.value = null;
        aucuneLivraison.value = null;
        return;
    }
    chargementVerifGate.value = true;
    const filtresJournee = {
        id_campagne: props.campagneId,
        id_campagne_journee: idJourneeSelectionnee.value,
    };
    const [resultatReste, resultatTotal] = await Promise.all([
        apiGet<{ ids: number[] }>(
            props.urls.queue +
                buildQuery({
                    ...filtresJournee,
                    statut_contact: "a_contacter",
                    ids_only: 1,
                }),
        ),
        apiGet<{ total: number }>(
            props.urls.contactsStatistiques + buildQuery(filtresJournee),
        ),
    ]);
    chargementVerifGate.value = false;
    resteAContacter.value = resultatReste.ok
        ? resultatReste.data.ids.length
        : null;
    aucuneLivraison.value = resultatTotal.ok
        ? resultatTotal.data.total === 0
        : null;
}

const chargementRoutes = ref(false);
const resultatRoutes = ref<GenererRoutesResultat | null>(null);
const erreurRoutes = ref("");

async function genererRoutes() {
    if (idJourneeSelectionnee.value === null) return;

    const confirmed = await confirmDialog.ask({
        title: "Lancer la génération des routes",
        message:
            "Le clustering et l'assignation des tournées vont être (re)calculés pour cette journée. Continuer ?",
        confirmLabel: "Lancer",
    });
    if (!confirmed) return;

    chargementRoutes.value = true;
    erreurRoutes.value = "";
    resultatRoutes.value = null;

    const resultat = await apiPost<GenererRoutesResultat>(props.urls.genererRoutes, {
        id_campagne_journee: idJourneeSelectionnee.value,
    });
    chargementRoutes.value = false;

    if (!resultat.ok) {
        erreurRoutes.value = resultat.message;
        toast.error(resultat.message);
        return;
    }

    resultatRoutes.value = resultat.data;
    toast.success(`${resultat.data.routes_creees} tournée(s) créée(s).`);
    emit("generated");
}


function surChangementJournee() {
    verifierGateClustering();
}

onMounted(verifierGateClustering);

const peutGenerer = computed(
    () => !(chargementRoutes.value || chargementVerifGate.value || (resteAContacter.value ?? 1) > 0 || aucuneLivraison.value !== false),
);
</script>

<template>
    <div class="bg-surface border border-surface-border rounded-xl p-5 mb-6">
        <h2 class="text-[14px] font-medium text-ink mb-3">Génération des routes</h2>

        <div v-if="journees.length > 1" class="mb-3">
            <label class="block text-[12.5px] font-medium text-ink-muted mb-1">Journée</label>
            <select v-model.number="idJourneeSelectionnee" @change="surChangementJournee"
                class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                <option v-for="journee in journees" :key="journee.id" :value="journee.id">
                    {{ journee.label ?? formatDateFr(journee.date) }} — {{ formatDateFr(journee.date) }}
                </option>
            </select>
        </div>

        <button type="button" :disabled="!peutGenerer" @click="genererRoutes"
            class="min-h-[2.25rem] text-[13px] px-4 py-2 rounded-lg bg-accent text-white disabled:opacity-40 disabled:cursor-not-allowed">
            🚚 {{ chargementRoutes ? "Génération…" : "Génération des routes" }}
        </button>
        <p v-if="chargementVerifGate" class="text-[12.5px] text-ink-muted mt-2">Vérification…</p>
        <p v-else-if="aucuneLivraison === true" class="text-[12.5px] text-amber-700 mt-2">
            Aucune famille n'a encore été ajoutée à cette journée.
        </p>
        <p v-else-if="(resteAContacter ?? 0) > 0" class="text-[12.5px] text-amber-700 mt-2">
            {{ resteAContacter }} famille(s) encore à contacter pour cette journée.
        </p>
        <p v-else-if="resteAContacter === 0" class="text-[12.5px] text-emerald-700 mt-2">
            Toutes les familles ont été contactées.
        </p>
        <p v-if="resultatRoutes" class="text-[12.5px] text-ink-muted mt-2">
            {{ resultatRoutes.routes_creees }} tournée(s) créée(s), dont {{ resultatRoutes.imposees }} imposée(s).
        </p>
        <p v-if="erreurRoutes" class="text-[12.5px] text-rose-600 mt-2">{{ erreurRoutes }}</p>
    </div>
</template>
