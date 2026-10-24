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

    23/10/2026 : la fenêtre affiche le guide de résolution pas à pas du type (RouteIncident::GUIDES), de quoi
    joindre la personne concernée (téléphone, email) et, pour les types qui s'y prêtent, des actions de
    résolution directes (capacité, familles sans tournée, famille non localisée / injoignable, email en échec…).
    Les incidents levés automatiquement portent le badge « Détecté automatiquement ».
-->
<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { Modal, useConfirm, useToast } from "@amana/shared-ui";
import { apiGet, apiPost } from "../shared/api";
import PersonSelect from "../shared/PersonSelect.vue";
import type {
    CandidatChauffeur,
    LigneIncident,
    OptionsCapacite,
    OptionsChauffeurIndisponible,
    OptionsIncident,
    OptionsNonCouvertes,
    PersonneResume,
    ResoudreIncidentResultat,
} from "../shared/types";

const props = defineProps<{
    incidents: LigneIncident[];
    resoudreUrlTemplate: string;
    ignorerUrlTemplate: string;
    rouvrirUrlTemplate: string;
    optionsUrlTemplate: string;
    resoudreSuiteUrlTemplate: string;
    /** `__ID__` = id d'une TOURNÉE (18/10/2026). */
    remettreDansLePoolUrlTemplate: string;
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
    reinitialiserSuite();
}

// ── Suite à donner à la famille (09/10/2026) ────────────────────────────
type ActionSuite =
    | "reinitialiser"
    | "tournee"
    | "retrait_qg"
    | "chauffeur"
    | "reessayer"
    | "domicile"
    | "reassigner"
    | "repartir"
    | "retirer"
    | "diviser"
    | "prevenue"
    | "annuler_imposition"
    | "regeocoder"
    | "a_recontacter"
    | "renvoyer"
    | "simple";

const CHOIX_LIVRAISON_IGNOREE: { id: ActionSuite; label: string; aide: string }[] = [
    {
        id: "reinitialiser",
        label: "Remettre la famille à planifier",
        aide: "Elle sera reprise à la prochaine génération de routes.",
    },
    {
        id: "tournee",
        label: "L'ajouter à une tournée existante",
        aide: "Une tournée pas encore chargée de cette campagne.",
    },
    {
        id: "retrait_qg",
        label: "Elle viendra chercher son colis au QG",
        aide: "Un rendez-vous au QG lui est attribué.",
    },
    { id: "chauffeur", label: "L'imposer à un chauffeur", aide: "Seuls les chauffeurs confirmés pour cette journée." },
    { id: "simple", label: "Clore sans suite", aide: "L'incident est résolu, la famille reste ignorée." },
];

const CHOIX_RETRAIT_NON_LIVRE: { id: ActionSuite; label: string; aide: string }[] = [
    { id: "reessayer", label: "La famille peut revenir", aide: "Son retrait repasse à « Prête »." },
    { id: "domicile", label: "La livrer à domicile", aide: "Elle ne se déplace plus : à planifier dans une tournée." },
    { id: "simple", label: "Clore sans suite", aide: "L'incident est résolu, rien ne change pour la famille." },
];

// 18/10/2026 : « chauffeur indisponible » — réassigner (véhicule de même type ou plus grand) ou répartir.
const CHOIX_CHAUFFEUR_INDISPONIBLE: { id: ActionSuite; label: string; aide: string }[] = [
    {
        id: "reassigner",
        label: "La réassigner à un autre chauffeur",
        aide: "Un véhicule de même type ou plus grand, au choix.",
    },
    {
        id: "repartir",
        label: "La répartir entre plusieurs chauffeurs",
        aide: "Quand aucun véhicule assez grand n'est disponible : au moins deux chauffeurs.",
    },
    { id: "simple", label: "Clore sans suite", aide: "L'incident est résolu, la tournée reste telle quelle." },
];

// 23/10/2026 : actions de résolution des autres types (IncidentResolutionService::resoudreAutre()).
const CHOIX_PAR_TYPE: Record<string, { id: ActionSuite; label: string; aide: string }[]> = {
    capacite: [
        { id: "retirer", label: "Retirer quelques familles de la tournée", aide: "Elles repassent à planifier." },
        { id: "diviser", label: "Scinder la tournée en deux", aide: "Une seconde tournée est créée." },
        { id: "reassigner", label: "La confier à un véhicule plus grand", aide: "Parmi les chauffeurs disponibles." },
        { id: "simple", label: "Clore sans suite", aide: "L'incident est résolu, la tournée reste telle quelle." },
    ],
    se_deplace_remplace: [
        { id: "prevenue", label: "Famille prévenue", aide: "Clôt l'incident." },
        {
            id: "annuler_imposition",
            label: "Annuler la livraison imposée",
            aide: "La famille revient chercher son colis au QG.",
        },
    ],
    livraisons_non_couvertes: [
        {
            id: "retrait_qg",
            label: "Les faire venir au QG",
            aide: "Toutes ces familles reçoivent un rendez-vous au QG.",
        },
        { id: "simple", label: "Clore sans suite", aide: "Les familles restent sans tournée." },
    ],
    famille_non_geocodee: [
        { id: "regeocoder", label: "Relancer la localisation", aide: "Se ferme tout seul si l'adresse est trouvée." },
        { id: "simple", label: "Clore sans suite", aide: "L'adresse reste non localisée." },
    ],
    famille_injoignable: [
        { id: "a_recontacter", label: "Remettre à contacter", aide: "Elle revient dans le suivi des contacts." },
        { id: "retirer", label: "Retirer de la campagne", aide: "Elle ne sera pas livrée." },
        { id: "simple", label: "Clore sans suite", aide: "Elle reste en attente." },
    ],
    email_echec: [
        { id: "renvoyer", label: "Renvoyer l'email", aide: "Email de rendez-vous au QG." },
        { id: "simple", label: "Clore sans suite", aide: "La famille sera prévenue autrement." },
    ],
};

const avecSuite = computed(() => {
    const type = selection.value?.type;
    return (
        type === "livraison_ignoree" ||
        type === "retrait_hq_non_livre" ||
        type === "chauffeur_indisponible" ||
        (type !== undefined && type in CHOIX_PAR_TYPE)
    );
});

const choixSuite = computed(() => {
    const type = selection.value?.type;
    if (type !== undefined && type in CHOIX_PAR_TYPE) return CHOIX_PAR_TYPE[type];
    if (type === "retrait_hq_non_livre") return CHOIX_RETRAIT_NON_LIVRE;
    if (type === "chauffeur_indisponible") return CHOIX_CHAUFFEUR_INDISPONIBLE;
    return CHOIX_LIVRAISON_IGNOREE;
});

const questionSuite = computed(() =>
    selection.value?.type === "chauffeur_indisponible"
        ? "Que faire de cette tournée ?"
        : "Que faire de cette famille ?",
);

const panneauSuite = ref(false);
const suite = ref<ActionSuite | null>(null);
const idRouteChoisie = ref<number | null>(null);
const chauffeurChoisi = ref<PersonneResume | null>(null);
const tournees = ref<OptionsIncident["tournees"]>([]);
const chargementOptions = ref(false);
// Incident « chauffeur indisponible » (18/10/2026).
const optionsChauffeur = ref<OptionsChauffeurIndisponible | null>(null);
const idChauffeurReassigne = ref<number | null>(null);
const idsRepartition = ref<number[]>([]);
// Incident « capacité » / « familles sans tournée » (23/10/2026).
const optionsCapacite = ref<OptionsCapacite | null>(null);
const optionsNonCouvertes = ref<OptionsNonCouvertes | null>(null);
const idsEtapesRetirees = ref<number[]>([]);

function basculerEtape(id: number) {
    const i = idsEtapesRetirees.value.indexOf(id);
    if (i === -1) idsEtapesRetirees.value.push(id);
    else idsEtapesRetirees.value.splice(i, 1);
}

const candidatsReassignation = computed<CandidatChauffeur[]>(
    () => optionsChauffeur.value?.candidats.filter((c) => c.assez_grand) ?? [],
);

function reinitialiserSuite() {
    panneauSuite.value = false;
    suite.value = null;
    idRouteChoisie.value = null;
    chauffeurChoisi.value = null;
    tournees.value = [];
    optionsChauffeur.value = null;
    idChauffeurReassigne.value = null;
    idsRepartition.value = [];
    optionsCapacite.value = null;
    optionsNonCouvertes.value = null;
    idsEtapesRetirees.value = [];
}

async function ouvrirPanneauSuite(incident: LigneIncident) {
    panneauSuite.value = true;

    if (incident.type === "chauffeur_indisponible") {
        chargementOptions.value = true;
        const res = await apiGet<OptionsChauffeurIndisponible>(
            props.optionsUrlTemplate.replace("__ID__", String(incident.id)),
        );
        chargementOptions.value = false;
        if (res.ok) optionsChauffeur.value = res.data;
        else toast.error(res.message);
        return;
    }

    if (incident.type === "capacite" || incident.type === "livraisons_non_couvertes") {
        chargementOptions.value = true;
        const res = await apiGet<OptionsCapacite & OptionsNonCouvertes>(
            props.optionsUrlTemplate.replace("__ID__", String(incident.id)),
        );
        chargementOptions.value = false;
        if (!res.ok) toast.error(res.message);
        else if (incident.type === "capacite") optionsCapacite.value = res.data;
        else optionsNonCouvertes.value = res.data;
        return;
    }

    if (incident.type !== "livraison_ignoree") return;

    chargementOptions.value = true;
    const resultat = await apiGet<OptionsIncident>(props.optionsUrlTemplate.replace("__ID__", String(incident.id)));
    chargementOptions.value = false;
    if (resultat.ok) tournees.value = resultat.data.tournees;
    else toast.error(resultat.message);
}

const suiteValidable = computed(() => {
    if (suite.value === null) return false;
    if (suite.value === "tournee") return idRouteChoisie.value !== null;
    if (suite.value === "chauffeur") return chauffeurChoisi.value !== null;
    if (suite.value === "reassigner") return idChauffeurReassigne.value !== null;
    if (suite.value === "repartir") return idsRepartition.value.length >= 2;
    if (suite.value === "retirer" && selection.value?.type === "capacite") return idsEtapesRetirees.value.length > 0;
    return true;
});

async function validerSuite(incident: LigneIncident) {
    if (!suiteValidable.value || suite.value === null) return;
    if (suite.value === "simple") return resoudre(incident);

    enCours.value = true;
    const resultat = await apiPost<{ success: boolean; message?: string | null }>(
        props.resoudreSuiteUrlTemplate.replace("__ID__", String(incident.id)),
        {
            action: suite.value,
            id_route: suite.value === "tournee" ? idRouteChoisie.value : undefined,
            id_benevole:
                suite.value === "chauffeur"
                    ? chauffeurChoisi.value?.id
                    : suite.value === "reassigner"
                      ? (idChauffeurReassigne.value ?? undefined)
                      : undefined,
            ids_benevoles: suite.value === "repartir" ? idsRepartition.value : undefined,
            ids_etapes:
                suite.value === "retirer" && selection.value?.type === "capacite" ? idsEtapesRetirees.value : undefined,
        },
    );
    enCours.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    // « Relancer la localisation » ne clôt pas : l'incident se ferme tout seul quand l'adresse est trouvée.
    if (suite.value === "regeocoder") {
        reinitialiserSuite();
        toast.success(resultat.data.message ?? "Localisation relancée.");
        return;
    }

    majStatut(incident.id, "resolu");
    reinitialiserSuite();
    emit("change");
    toast.success("Incident résolu.");
}

function basculerRepartition(id: number) {
    const i = idsRepartition.value.indexOf(id);
    if (i === -1) idsRepartition.value.push(id);
    else idsRepartition.value.splice(i, 1);
}

// « Remettre dans le pool » : le chauffeur a répondu « Non » mais est finalement revenu (18/10/2026).
async function remettreDansLePool(incident: LigneIncident) {
    const idRoute = optionsChauffeur.value?.id_route_non_retour ?? null;
    if (idRoute === null) return;

    enCours.value = true;
    const resultat = await apiPost<{ success: boolean }>(
        props.remettreDansLePoolUrlTemplate.replace("__ID__", String(idRoute)),
    );
    enCours.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    // Le serveur a résolu l'incident (le chauffeur est de retour au QG) : on ferme la fenêtre.
    majStatut(incident.id, "resolu");
    reinitialiserSuite();
    selection.value = null;
    emit("change");
    toast.success("Chauffeur remis dans le pool.");
}

async function rouvrir(incident: LigneIncident) {
    enCours.value = true;
    const resultat = await apiPost<{ success: boolean; avertissement?: string }>(
        props.rouvrirUrlTemplate.replace("__ID__", String(incident.id)),
    );
    enCours.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    majStatut(incident.id, "ouvert");
    emit("change");
    toast.success("Incident rouvert.");
    if (resultat.data.avertissement) toast.warning(resultat.data.avertissement);
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
        message:
            "L'incident sera fermé sans être traité (aucune action n'est lancée). Vous pourrez le rouvrir tant que la campagne n'est pas terminée.",
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
                        <template v-if="incident.id_route !== null">Tournée #{{ incident.id_route }}</template
                        ><template v-else>Retrait au QG</template
                        ><template v-if="incident.chauffeur"> · {{ incident.chauffeur }}</template
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
                    <h2 class="text-[16px] font-semibold text-ink">
                        {{ selection.type_label }}
                        <span
                            v-if="selection.detecte"
                            class="ml-1 align-middle text-[10.5px] font-semibold px-2 py-0.5 rounded-full bg-sky-100 text-sky-700 border border-sky-200"
                            >Détecté automatiquement</span
                        >
                    </h2>
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
                    <div v-if="selection.id_route !== null" class="flex justify-between gap-4 px-3 py-2">
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

                <!-- Contacts utiles (23/10/2026) -->
                <p
                    v-if="
                        selection.chauffeur_telephone || selection.famille_telephone.length || selection.famille_email
                    "
                    class="text-[13px] text-ink mb-4"
                >
                    <span v-if="selection.chauffeur_telephone">
                        📞 Chauffeur :
                        <a :href="`tel:${selection.chauffeur_telephone}`" class="text-accent font-medium">{{
                            selection.chauffeur_telephone
                        }}</a>
                        <br />
                    </span>
                    <span v-if="selection.famille_telephone.length">
                        📞 Famille :
                        <a
                            v-for="t in selection.famille_telephone"
                            :key="t"
                            :href="`tel:${t}`"
                            class="text-accent font-medium mr-2"
                            >{{ t }}</a
                        >
                        <br />
                    </span>
                    <span v-if="selection.famille_email">
                        ✉️
                        <a :href="`mailto:${selection.famille_email}`" class="text-accent font-medium">{{
                            selection.famille_email
                        }}</a>
                    </span>
                </p>

                <div v-if="selection.guide" class="rounded-lg border border-surface-border bg-surface-2 p-3 mb-4">
                    <p class="text-[12px] font-semibold uppercase tracking-wide text-ink-muted mb-1">
                        Guide de résolution
                    </p>
                    <p class="text-[13px] text-ink whitespace-pre-line">{{ selection.guide }}</p>
                </div>

                <p v-if="selection.notes" class="text-[12px] text-ink-muted whitespace-pre-line mb-4">
                    {{ selection.notes }}
                </p>

                <!-- Suite à donner à la famille (09/10/2026) -->
                <div
                    v-if="selection.statut === 'ouvert' && avecSuite && panneauSuite"
                    class="rounded-lg border border-surface-border p-3 mb-4 space-y-2"
                >
                    <p class="text-[12px] font-semibold uppercase tracking-wide text-ink-muted">
                        {{ questionSuite }}
                    </p>
                    <label
                        v-for="c in choixSuite"
                        :key="c.id"
                        class="flex items-start gap-2 text-[13px] cursor-pointer"
                    >
                        <input v-model="suite" type="radio" name="suite-incident" :value="c.id" class="mt-1" />
                        <span>
                            <span class="font-medium text-ink">{{ c.label }}</span>
                            <span class="block text-[12px] text-ink-muted">{{ c.aide }}</span>
                        </span>
                    </label>

                    <div v-if="suite === 'tournee'" class="pl-6">
                        <p v-if="chargementOptions" class="text-[12px] text-ink-muted">Chargement des tournées…</p>
                        <p v-else-if="tournees.length === 0" class="text-[12px] text-rose-600">
                            Aucune tournée ne peut encore accueillir cette famille (toutes sont chargées ou en cours).
                        </p>
                        <select
                            v-else
                            v-model="idRouteChoisie"
                            class="w-full text-[13px] border border-surface-border rounded-lg px-2 py-1.5 bg-surface"
                        >
                            <option :value="null" disabled>Choisir une tournée…</option>
                            <option v-for="t in tournees" :key="t.id" :value="t.id">{{ t.libelle }}</option>
                        </select>
                    </div>

                    <!-- Chauffeur indisponible (18/10/2026) -->
                    <div v-if="selection.type === 'chauffeur_indisponible'" class="pl-6 space-y-2">
                        <p v-if="chargementOptions" class="text-[12px] text-ink-muted">Chargement des chauffeurs…</p>
                        <p v-else-if="optionsChauffeur?.message" class="text-[12px] text-rose-600">
                            {{ optionsChauffeur.message }}
                        </p>
                        <template v-else-if="optionsChauffeur">
                            <p class="text-[12px] text-ink-muted">
                                {{ optionsChauffeur.nb_arrets }} arrêt(s) · véhicule actuel
                                {{ optionsChauffeur.vehicule_actuel?.type ?? "—" }} ({{
                                    optionsChauffeur.vehicule_actuel?.capacite_kg
                                }}
                                kg, {{ optionsChauffeur.vehicule_actuel?.nombre_part_max }} colis)
                            </p>

                            <div v-if="suite === 'reassigner'">
                                <p v-if="candidatsReassignation.length === 0" class="text-[12px] text-rose-600">
                                    Aucun chauffeur disponible n'a un véhicule de même type ou plus grand : répartissez
                                    la tournée entre plusieurs chauffeurs.
                                </p>
                                <select
                                    v-else
                                    v-model="idChauffeurReassigne"
                                    class="w-full text-[13px] border border-surface-border rounded-lg px-2 py-1.5 bg-surface"
                                >
                                    <option :value="null" disabled>Choisir un chauffeur…</option>
                                    <option
                                        v-for="c in candidatsReassignation"
                                        :key="c.id_personne"
                                        :value="c.id_personne"
                                    >
                                        {{ c.prenom }} {{ c.nom }} — {{ c.vehicule }} · {{ c.capacite_kg }} kg ·
                                        {{ c.nombre_part_max }} colis{{ c.en_livraison ? " · ⚠️ en livraison" : "" }}
                                    </option>
                                </select>
                            </div>

                            <div v-if="suite === 'repartir'" class="space-y-1">
                                <p v-if="optionsChauffeur.candidats.length < 2" class="text-[12px] text-rose-600">
                                    Il faut au moins deux chauffeurs disponibles sur ce créneau pour répartir la
                                    tournée.
                                </p>
                                <label
                                    v-for="c in optionsChauffeur.candidats"
                                    :key="c.id_personne"
                                    class="flex items-center gap-2 text-[13px] cursor-pointer"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="idsRepartition.includes(c.id_personne)"
                                        @change="basculerRepartition(c.id_personne)"
                                    />
                                    <span>
                                        {{ c.prenom }} {{ c.nom }} — {{ c.vehicule }} · {{ c.capacite_kg }} kg ·
                                        {{ c.nombre_part_max }} colis{{ c.en_livraison ? " · ⚠️ en livraison" : "" }}
                                    </span>
                                </label>
                            </div>

                            <button
                                v-if="optionsChauffeur.id_route_non_retour !== null"
                                type="button"
                                :disabled="enCours"
                                @click="remettreDansLePool(selection)"
                                class="px-3 py-1.5 rounded-lg border border-surface-border text-[12.5px] text-ink hover:bg-stone-50 disabled:opacity-60"
                            >
                                ↩️ Le chauffeur est finalement revenu — le remettre dans le pool
                            </button>
                        </template>
                    </div>

                    <!-- Capacité (23/10/2026) -->
                    <div v-if="selection.type === 'capacite'" class="pl-6 space-y-1">
                        <p v-if="chargementOptions" class="text-[12px] text-ink-muted">Chargement…</p>
                        <p v-else-if="optionsCapacite?.message" class="text-[12px] text-rose-600">
                            {{ optionsCapacite.message }}
                        </p>
                        <template v-else-if="optionsCapacite">
                            <p class="text-[12px] text-ink-muted">
                                {{ Math.round(optionsCapacite.poids_total_kg ?? 0) }} kg pour une capacité de
                                {{ optionsCapacite.capacite_kg ?? "—" }} kg.
                            </p>
                            <div v-if="suite === 'retirer'" class="space-y-1">
                                <label
                                    v-for="a in optionsCapacite.arrets"
                                    :key="a.id_etape"
                                    class="flex items-center gap-2 text-[13px] cursor-pointer"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="idsEtapesRetirees.includes(a.id_etape)"
                                        @change="basculerEtape(a.id_etape)"
                                    />
                                    <span>{{ a.famille }} — {{ a.poids_kg }} kg</span>
                                </label>
                            </div>
                            <div v-if="suite === 'reassigner'">
                                <p v-if="!optionsCapacite.candidats?.length" class="text-[12px] text-rose-600">
                                    Aucun chauffeur disponible n'a un véhicule assez grand.
                                </p>
                                <select
                                    v-else
                                    v-model="idChauffeurReassigne"
                                    class="w-full text-[13px] border border-surface-border rounded-lg px-2 py-1.5 bg-surface"
                                >
                                    <option :value="null" disabled>Choisir un chauffeur…</option>
                                    <option
                                        v-for="c in optionsCapacite.candidats"
                                        :key="c.id_personne"
                                        :value="c.id_personne"
                                    >
                                        {{ c.prenom }} {{ c.nom }} — {{ c.vehicule }} · {{ c.capacite_kg }} kg
                                    </option>
                                </select>
                            </div>
                        </template>
                    </div>

                    <!-- Familles sans tournée (23/10/2026) -->
                    <div v-if="selection.type === 'livraisons_non_couvertes'" class="pl-6">
                        <p v-if="chargementOptions" class="text-[12px] text-ink-muted">Chargement…</p>
                        <ul v-else-if="optionsNonCouvertes" class="text-[12.5px] text-ink-muted list-disc pl-4">
                            <li v-for="f in optionsNonCouvertes.familles" :key="f.id_livraison">{{ f.nom }}</li>
                        </ul>
                    </div>

                    <div v-if="suite === 'chauffeur'" class="pl-6">
                        <PersonSelect
                            role="benevole"
                            avec-vehicule
                            :id-campagne-journee="selection.id_campagne_journee"
                            :id-campagne="selection.id_campagne"
                            placeholder="Choisir un chauffeur confirmé…"
                            :model-value="chauffeurChoisi"
                            @update:model-value="(p) => (chauffeurChoisi = p)"
                        />
                    </div>
                </div>

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
                            v-if="avecSuite && !panneauSuite"
                            type="button"
                            :disabled="enCours"
                            @click="ouvrirPanneauSuite(selection)"
                            class="px-4 py-2 rounded-lg bg-accent text-white text-[13px] font-semibold disabled:opacity-60"
                        >
                            Résoudre…
                        </button>
                        <button
                            v-else-if="avecSuite"
                            type="button"
                            :disabled="enCours || !suiteValidable"
                            @click="validerSuite(selection)"
                            class="px-4 py-2 rounded-lg bg-accent text-white text-[13px] font-semibold disabled:opacity-60"
                        >
                            Valider
                        </button>
                        <button
                            v-else
                            type="button"
                            :disabled="enCours"
                            @click="resoudre(selection)"
                            class="px-4 py-2 rounded-lg bg-accent text-white text-[13px] font-semibold disabled:opacity-60"
                        >
                            Résoudre
                        </button>
                    </template>
                    <!-- Rouvrir (09/10/2026) : incident résolu ou fermé ; refusé par le serveur si la campagne est terminée. -->
                    <button
                        v-else
                        type="button"
                        :disabled="enCours"
                        @click="rouvrir(selection)"
                        class="px-4 py-2 rounded-lg border border-accent text-accent text-[13px] font-semibold hover:bg-accent/5 disabled:opacity-60"
                    >
                        Rouvrir
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>
