<!-- resources/js/components/livraison/campagnes/GenererRoutesWizard.vue -->
<!--
    Assistant « Génération des routes » du hub de la campagne (06/10/2026) —
    remplace le bloc « Génération des routes » (GenererRoutesPanel) et le
    constructeur « tournée personnalisée » (BuildRouteFlow) de Suivi livraison.
    Fenêtre en étapes :

      1. Mode : Automatique (clustering + TSP) ou Personnalisé (un chauffeur,
         des familles choisies à la main, sans optimisation de répartition) ;
      2. Journée + créneau, avec bouton Confirmer ;
      3. Chauffeurs disponibles sur ce créneau, filtrables (recherche, véhicule,
         secteur) — Automatique : un ou plusieurs ; Personnalisé : un seul.
         Un chauffeur déjà en tournée sur ce créneau est grisé ;
      4. Automatique : récapitulatif (familles éligibles, poids vs capacité) puis
         génération. Personnalisé : tableau de familles filtrable (mêmes filtres
         que la sélection des familles), création de la tournée, puis « Créer
         une autre tournée » sans repasser par le mode et le créneau.

    Les familles se_deplace (retrait QG) et imposées (tournée de leur
    chauffeur, sans créneau) ne sont jamais proposées : le serveur les exclut
    (LiveBoardController / RouteMutationService::construirePersonnalisee()).
-->
<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { Modal, useToast } from "@amana/shared-ui";
import { apiGet, apiPost, buildQuery } from "../shared/api";
import PaginationControls from "../shared/PaginationControls.vue";
import FamilleFilterPanel from "../shared/FamilleFilterPanel.vue";
import {
    CRENEAUX,
    creneauxRestantsPour,
    CRENEAU_LIBELLES,
    normalizePaginated,
    type ApercuGeneration,
    type Campagne,
    type ChauffeurDisponible,
    type Creneau,
    type FamilleEligible,
    type FamilleFiltres,
    type GenerationUrls,
    type Organisation,
    type Paginated,
    type Quartier,
    type RawLaravelPaginator,
    type Secteur,
    type Ville,
} from "../shared/types";
import { formatDateFr } from "./campagneStyles";

const props = defineProps<{
    open: boolean;
    campagne: Campagne;
    urls: GenerationUrls;
    villes: Ville[];
    secteurs: Secteur[];
    quartiers: Quartier[];
    organisations: Organisation[];
}>();

const emit = defineEmits<{
    close: [];
    /** Des tournées ont été créées : le hub rafraîchit son avancement. */
    done: [];
}>();

const toast = useToast();

type Mode = "auto" | "custom";
type Etape = "mode" | "creneau" | "chauffeurs" | "recap" | "familles" | "termine";

const etape = ref<Etape>("mode");
const mode = ref<Mode>("auto");
const idJournee = ref<number | null>(null);
const creneau = ref<string>("");
const enCours = ref(false);

const journees = computed(() => props.campagne.journees ?? []);
const journeeChoisie = computed(() => journees.value.find((j) => j.id === idJournee.value) ?? null);

const TITRES: Record<Etape, string> = {
    mode: "Génération des routes",
    creneau: "Journée et créneau",
    chauffeurs: "Chauffeurs",
    recap: "Récapitulatif",
    familles: "Familles à livrer",
    termine: "Terminé",
};

// Réinitialisation à chaque ouverture ; la journée est présélectionnée quand
// la campagne n'en a qu'une.
watch(
    () => props.open,
    (ouvert) => {
        if (!ouvert) return;
        etape.value = "mode";
        mode.value = "auto";
        creneau.value = "";
        idJournee.value = journees.value.length === 1 ? journees.value[0].id : null;
        selection.clear();
        idChauffeur.value = null;
        resume.value = null;
        resultat.value = "";
    },
);

function retour() {
    if (etape.value === "creneau") etape.value = "mode";
    else if (etape.value === "chauffeurs") etape.value = "creneau";
    else if (etape.value === "recap" || etape.value === "familles") etape.value = "chauffeurs";
}

// ── Étape 2 : journée + créneau ─────────────────────────────────────────
// Mode automatique (09/10/2026) : seuls le créneau en cours et les suivants sont proposés
// (le serveur refuse aussi un créneau terminé) ; le mode personnalisé garde les six créneaux.
const creneauxProposes = computed<readonly Creneau[]>(() =>
    mode.value === "auto" && journeeChoisie.value ? creneauxRestantsPour(journeeChoisie.value.date) : CRENEAUX,
);

// Un créneau déjà choisi qui n'est plus proposé (changement de journée ou de mode) est abandonné.
watch(creneauxProposes, (propose) => {
    if (creneau.value !== "" && !propose.includes(creneau.value as Creneau)) creneau.value = "";
});

const creneauConfirmable = computed(() => idJournee.value !== null && creneau.value !== "");

async function confirmerCreneau() {
    if (!creneauConfirmable.value) return;
    etape.value = "chauffeurs";
    selection.clear();
    idChauffeur.value = null;
    await chargerChauffeurs();
}

// ── Étape 3 : chauffeurs ────────────────────────────────────────────────
const chauffeurs = ref<ChauffeurDisponible[]>([]);
const chargementChauffeurs = ref(false);
const recherche = ref("");
const filtreVehicule = ref("");
const filtreSecteur = ref<number | "">("");
const selection = reactive<Set<number>>(new Set()); // mode automatique : plusieurs
const idChauffeur = ref<number | null>(null); // mode personnalisé : un seul

async function chargerChauffeurs() {
    chargementChauffeurs.value = true;
    const resultatApi = await apiGet<ChauffeurDisponible[]>(
        `${props.urls.chauffeurs}${buildQuery({ id_campagne_journee: idJournee.value, creneau: creneau.value })}`,
    );
    chargementChauffeurs.value = false;

    if (!resultatApi.ok) {
        toast.error(resultatApi.message);
        chauffeurs.value = [];
        return;
    }
    chauffeurs.value = resultatApi.data;
}

const vehiculesDisponibles = computed(() => [...new Set(chauffeurs.value.map((c) => c.vehicule))].sort());
const secteursDesChauffeurs = computed(() => {
    const ids = new Set(chauffeurs.value.flatMap((c) => c.ids_secteurs));
    return props.secteurs.filter((s) => ids.has(s.id));
});

/** Début du prénom OU du nom — même règle que PersonSelect. */
const chauffeursVisibles = computed(() => {
    const terme = recherche.value.trim().toLowerCase();
    return chauffeurs.value.filter((c) => {
        if (filtreVehicule.value && c.vehicule !== filtreVehicule.value) return false;
        if (filtreSecteur.value !== "" && !c.ids_secteurs.includes(filtreSecteur.value)) return false;
        if (
            terme &&
            !`${c.prenom} ${c.nom}`
                .toLowerCase()
                .split(" ")
                .some((mot) => mot.startsWith(terme))
        )
            return false;
        return true;
    });
});

const libres = computed(() => chauffeursVisibles.value.filter((c) => !c.occupe));
const toutSelectionne = computed(
    () => libres.value.length > 0 && libres.value.every((c) => selection.has(c.id_personne)),
);

function basculerChauffeur(c: ChauffeurDisponible) {
    if (c.occupe) return;
    if (mode.value === "custom") {
        idChauffeur.value = idChauffeur.value === c.id_personne ? null : c.id_personne;
        return;
    }
    if (selection.has(c.id_personne)) selection.delete(c.id_personne);
    else selection.add(c.id_personne);
}

function toutBasculer() {
    if (toutSelectionne.value) libres.value.forEach((c) => selection.delete(c.id_personne));
    else libres.value.forEach((c) => selection.add(c.id_personne));
}

function estChoisi(c: ChauffeurDisponible): boolean {
    return mode.value === "custom" ? idChauffeur.value === c.id_personne : selection.has(c.id_personne);
}

const chauffeurPret = computed(() => (mode.value === "custom" ? idChauffeur.value !== null : selection.size > 0));
const chauffeurPersonnalise = computed(() => chauffeurs.value.find((c) => c.id_personne === idChauffeur.value) ?? null);

async function continuerChauffeurs() {
    if (!chauffeurPret.value) return;
    if (mode.value === "custom") {
        etape.value = "familles";
        idsLivraisons.clear();
        void chargerLignes(1);
        return;
    }
    await chargerResume();
}

// ── Étape 4 (automatique) : récapitulatif + génération ──────────────────
const resume = ref<ApercuGeneration | null>(null);
const resultat = ref("");

async function chargerResume() {
    enCours.value = true;
    const resultatApi = await apiGet<ApercuGeneration>(
        `${props.urls.apercu}${buildQuery({
            id_campagne_journee: idJournee.value,
            creneau: creneau.value,
            ids_benevoles: [...selection],
        })}`,
    );
    enCours.value = false;

    if (!resultatApi.ok) {
        toast.error(resultatApi.message);
        return;
    }
    resume.value = resultatApi.data;
    etape.value = "recap";
}

const capaciteInsuffisante = computed(() => resume.value !== null && resume.value.poids_kg > resume.value.capacite_kg);
const aucuneFamille = computed(
    () => resume.value !== null && resume.value.familles + resume.value.sans_coordonnees === 0,
);

async function generer() {
    enCours.value = true;
    const resultatApi = await apiPost<{ success: boolean; routes_creees: number; non_couvertes: number }>(
        props.urls.generer,
        {
            id_campagne_journee: idJournee.value,
            creneau: creneau.value,
            ids_benevoles: [...selection],
        },
    );
    enCours.value = false;

    if (!resultatApi.ok) {
        toast.error(resultatApi.message);
        return;
    }

    const { routes_creees: routes, non_couvertes: restantes } = resultatApi.data;
    resultat.value = `${routes} tournée(s) créée(s)${restantes > 0 ? `, ${restantes} famille(s) restent sans tournée (capacité insuffisante).` : "."}`;
    etape.value = "termine";
    emit("done");
}

// ── Étape 4 (personnalisé) : familles à livrer ──────────────────────────
const filtres = ref<FamilleFiltres>({});
const tri = ref<string | null>(null);
const directionTri = ref<"asc" | "desc">("asc");
const lignes = ref<FamilleEligible[]>([]);
const metaLignes = ref<Paginated<FamilleEligible>["meta"] | null>(null);
const chargementLignes = ref(false);
const erreurLignes = ref(false);
const chargementSelectionTout = ref(false);
/** Par défaut : familles confirmées pour le créneau choisi ; coché = toutes les familles à planifier. */
const autresCreneaux = ref(false);

// Sélection par id_livraison (pas id de famille) — ce que le serveur attend.
const idsLivraisons = reactive<Set<number>>(new Set());
const poidsParLivraison = reactive<Map<number, number>>(new Map());

function trierPar(colonne: string) {
    if (tri.value === colonne) directionTri.value = directionTri.value === "asc" ? "desc" : "asc";
    else {
        tri.value = colonne;
        directionTri.value = "asc";
    }
    void chargerLignes(1);
}

function queryFiltres(page: number) {
    return buildQuery({
        page,
        tri: tri.value,
        direction: tri.value ? directionTri.value : undefined,
        id_campagne_journee: idJournee.value,
        creneau: autresCreneaux.value ? undefined : creneau.value,
        // Jamais les familles qui viennent au QG, ni celles imposées à un chauffeur.
        se_deplace: 0,
        sans_imposees: 1,
        id_ville: filtres.value.id_ville,
        id_secteur: filtres.value.id_secteur,
        id_quartier: filtres.value.id_quartier,
        criticite: filtres.value.criticite,
        est_hotel: filtres.value.est_hotel || undefined,
        etudiant: filtres.value.etudiant || undefined,
        zakat_el_fitr: filtres.value.zakat_el_fitr || undefined,
        sadaqa: filtres.value.sadaqa || undefined,
        id_organisation_origine: filtres.value.id_organisation_origine,
        id_organisation_rattachee: filtres.value.id_organisation_rattachee,
        recherche: filtres.value.recherche,
    });
}

async function chargerLignes(page = 1) {
    chargementLignes.value = true;
    erreurLignes.value = false;

    const resultatApi = await apiGet<RawLaravelPaginator<FamilleEligible>>(
        `${props.urls.nonCouvertesTableau}${queryFiltres(page)}`,
    );
    chargementLignes.value = false;

    if (!resultatApi.ok) {
        erreurLignes.value = true;
        return;
    }

    const pagine = normalizePaginated(resultatApi.data);
    lignes.value = pagine.data;
    metaLignes.value = pagine.meta;
    for (const f of pagine.data) {
        if (f.id_livraison !== undefined && f.poids_kg !== undefined) poidsParLivraison.set(f.id_livraison, f.poids_kg);
    }
}

watch(autresCreneaux, () => {
    idsLivraisons.clear();
    void chargerLignes(1);
});

function basculerLivraison(idLivraison: number | undefined) {
    if (idLivraison === undefined) return;
    if (idsLivraisons.has(idLivraison)) idsLivraisons.delete(idLivraison);
    else idsLivraisons.add(idLivraison);
}

async function toutSelectionnerFiltre() {
    const idsPage = lignes.value.map((f) => f.id_livraison).filter((id): id is number => id !== undefined);
    if (idsPage.length > 0 && idsPage.every((id) => idsLivraisons.has(id))) {
        idsPage.forEach((id) => idsLivraisons.delete(id));
        return;
    }

    chargementSelectionTout.value = true;
    const resultatApi = await apiGet<{ ids: number[] }>(
        `${props.urls.nonCouvertesTableau}${queryFiltres(1)}&ids_only=1`,
    );
    chargementSelectionTout.value = false;

    if (!resultatApi.ok) {
        toast.error(resultatApi.message);
        return;
    }
    resultatApi.data.ids.forEach((id) => idsLivraisons.add(id));
}

/** Poids cumulé des familles cochées (celles dont le poids est connu) face à la capacité du véhicule. */
const poidsSelectionne = computed(() => {
    let total = 0;
    for (const id of idsLivraisons) total += poidsParLivraison.get(id) ?? 0;
    return Math.round(total * 10) / 10;
});
const depasseCapacite = computed(
    () => chauffeurPersonnalise.value !== null && poidsSelectionne.value > chauffeurPersonnalise.value.capacite_kg,
);

async function creerTournee() {
    if (idChauffeur.value === null || idsLivraisons.size === 0) return;

    enCours.value = true;
    const resultatApi = await apiPost<{ success: boolean; id_route: number }>(props.urls.personnalisee, {
        id_campagne_journee: idJournee.value,
        creneau: creneau.value,
        id_benevole: idChauffeur.value,
        ids_livraisons: [...idsLivraisons],
    });
    enCours.value = false;

    if (!resultatApi.ok) {
        toast.error(resultatApi.message);
        return;
    }

    resultat.value = `Tournée #${resultatApi.data.id_route} créée pour ${chauffeurPersonnalise.value?.prenom ?? ""} ${chauffeurPersonnalise.value?.nom ?? ""} (${idsLivraisons.size} famille(s)).`;
    etape.value = "termine";
    emit("done");
}

/** « Créer une autre tournée » : même journée et même créneau, retour au choix du chauffeur. */
async function autreTournee() {
    idChauffeur.value = null;
    idsLivraisons.clear();
    etape.value = "chauffeurs";
    await chargerChauffeurs();
}
</script>

<template>
    <Modal :open="open" max-width="max-w-4xl" @close="emit('close')">
        <div class="space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-[17px] font-semibold text-ink">🗺️ {{ TITRES[etape] }}</h2>
                    <p v-if="etape !== 'mode'" class="text-[12.5px] text-ink-muted mt-0.5">
                        {{ mode === "auto" ? "Automatique" : "Personnalisé" }}
                        <template v-if="journeeChoisie && creneau">
                            · {{ formatDateFr(journeeChoisie.date) }} ·
                            {{ CRENEAU_LIBELLES[creneau as keyof typeof CRENEAU_LIBELLES] }}</template
                        >
                    </p>
                </div>
                <button
                    type="button"
                    aria-label="Fermer"
                    @click="emit('close')"
                    class="text-ink-muted hover:text-ink text-[20px] leading-none px-1"
                >
                    ×
                </button>
            </div>

            <!-- 1. Mode -->
            <div v-if="etape === 'mode'" class="space-y-3">
                <p class="text-[13.5px] text-ink-muted">Comment voulez-vous créer les tournées ?</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <button
                        type="button"
                        @click="
                            mode = 'auto';
                            etape = 'creneau';
                        "
                        class="text-left rounded-xl border-2 border-surface-border hover:border-accent p-4 transition-colors"
                    >
                        <p class="text-[26px] leading-none mb-2">⚙️</p>
                        <p class="text-[15px] font-semibold text-ink">Automatique</p>
                        <p class="text-[12.5px] text-ink-muted">
                            Choisir un créneau et des chauffeurs : les familles disponibles sont réparties et ordonnées
                            automatiquement.
                        </p>
                    </button>
                    <button
                        type="button"
                        @click="
                            mode = 'custom';
                            etape = 'creneau';
                        "
                        class="text-left rounded-xl border-2 border-surface-border hover:border-accent p-4 transition-colors"
                    >
                        <p class="text-[26px] leading-none mb-2">🎯</p>
                        <p class="text-[15px] font-semibold text-ink">Personnalisé</p>
                        <p class="text-[12.5px] text-ink-muted">
                            Choisir un chauffeur et les familles à lui confier, sans répartition automatique.
                        </p>
                    </button>
                </div>
            </div>

            <!-- 2. Journée + créneau -->
            <div v-else-if="etape === 'creneau'" class="space-y-4">
                <div v-if="journees.length > 1">
                    <label class="block text-[12px] text-ink-muted mb-1">Journée</label>
                    <select
                        v-model="idJournee"
                        class="w-full sm:w-72 rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]"
                    >
                        <option :value="null" disabled>— Choisir —</option>
                        <option v-for="j in journees" :key="j.id" :value="j.id">
                            {{ formatDateFr(j.date) }}{{ j.label ? ` — ${j.label}` : "" }}
                        </option>
                    </select>
                </div>
                <p v-else-if="journeeChoisie" class="text-[13.5px] text-ink">
                    Journée du {{ formatDateFr(journeeChoisie.date) }}
                </p>
                <p v-else class="text-[13px] text-rose-600">Cette campagne n'a aucune journée.</p>

                <div>
                    <label class="block text-[12px] text-ink-muted mb-1.5">Créneau</label>
                    <p v-if="creneauxProposes.length === 0" class="text-[13px] text-rose-600">
                        Tous les créneaux de cette journée sont terminés : choisissez une autre journée.
                    </p>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        <button
                            v-for="c in creneauxProposes"
                            :key="c"
                            type="button"
                            @click="creneau = c"
                            class="px-3 py-2.5 rounded-lg border text-[13.5px] transition-colors"
                            :class="
                                creneau === c
                                    ? 'bg-accent text-white border-accent font-semibold'
                                    : 'border-surface-border text-ink hover:bg-stone-50'
                            "
                        >
                            {{ CRENEAU_LIBELLES[c] }}
                        </button>
                    </div>
                </div>

                <div class="flex justify-between gap-2 pt-2">
                    <button
                        type="button"
                        @click="retour"
                        class="px-4 py-2 rounded-lg border border-surface-border text-[13px] text-ink-muted hover:bg-stone-50"
                    >
                        ← Retour
                    </button>
                    <button
                        type="button"
                        :disabled="!creneauConfirmable"
                        @click="confirmerCreneau"
                        class="px-4 py-2 rounded-lg bg-accent text-white text-[13px] font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Confirmer
                    </button>
                </div>
            </div>

            <!-- 3. Chauffeurs -->
            <div v-else-if="etape === 'chauffeurs'" class="space-y-3">
                <p class="text-[13px] text-ink-muted">
                    {{
                        mode === "auto"
                            ? "Choisissez un ou plusieurs chauffeurs disponibles sur ce créneau."
                            : "Choisissez le chauffeur de cette tournée."
                    }}
                </p>

                <div class="flex flex-wrap gap-2">
                    <input
                        v-model="recherche"
                        type="search"
                        placeholder="Rechercher un chauffeur…"
                        class="flex-1 min-w-[10rem] rounded-lg border border-surface-border px-3 py-2 text-[13.5px] min-h-[2.5rem]"
                    />
                    <select
                        v-model="filtreVehicule"
                        class="rounded-lg border border-surface-border px-3 py-2 text-[13.5px] min-h-[2.5rem]"
                    >
                        <option value="">Tous les véhicules</option>
                        <option v-for="v in vehiculesDisponibles" :key="v" :value="v">{{ v }}</option>
                    </select>
                    <select
                        v-if="secteursDesChauffeurs.length > 0"
                        v-model="filtreSecteur"
                        class="rounded-lg border border-surface-border px-3 py-2 text-[13.5px] min-h-[2.5rem]"
                    >
                        <option value="">Tous les secteurs</option>
                        <option v-for="s in secteursDesChauffeurs" :key="s.id" :value="s.id">{{ s.nom }}</option>
                    </select>
                    <button
                        v-if="mode === 'auto'"
                        type="button"
                        @click="toutBasculer"
                        class="text-[12.5px] px-3 py-2 rounded-lg border border-accent text-accent hover:bg-accent/5"
                    >
                        {{ toutSelectionne ? "Tout désélectionner" : "Tout sélectionner" }}
                    </button>
                </div>

                <div class="max-h-[22rem] overflow-y-auto space-y-2">
                    <p v-if="chargementChauffeurs" class="text-[13px] text-ink-muted py-3">Chargement…</p>
                    <p v-else-if="chauffeurs.length === 0" class="text-[13px] text-ink-muted py-3">
                        Aucun chauffeur confirmé sur ce créneau (disponibilité à confirmer dans « Suivi des bénévoles
                        »).
                    </p>
                    <p v-else-if="chauffeursVisibles.length === 0" class="text-[13px] text-ink-muted py-3">
                        Aucun chauffeur pour ces filtres.
                    </p>

                    <label
                        v-for="c in chauffeursVisibles"
                        :key="c.id_personne"
                        class="flex items-center gap-3 rounded-xl border p-3 select-none"
                        :class="
                            c.occupe
                                ? 'opacity-50 cursor-not-allowed bg-stone-50 border-surface-border'
                                : estChoisi(c)
                                  ? 'border-accent bg-accent/5 cursor-pointer'
                                  : 'border-surface-border hover:bg-stone-50 cursor-pointer'
                        "
                    >
                        <input
                            :type="mode === 'auto' ? 'checkbox' : 'radio'"
                            name="chauffeur"
                            :checked="estChoisi(c)"
                            :disabled="c.occupe"
                            @change="basculerChauffeur(c)"
                            class="w-4 h-4 accent-accent"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="text-[14px] font-medium text-ink truncate">{{ c.prenom }} {{ c.nom }}</p>
                            <p class="text-[12px] text-ink-muted">{{ c.vehicule }} · {{ c.capacite_kg }} kg</p>
                        </div>
                        <span
                            v-if="c.occupe"
                            class="shrink-0 text-[11px] font-semibold px-2 py-0.5 rounded-full bg-stone-200 text-stone-600"
                        >
                            Déjà en tournée<template v-if="c.id_route"> (#{{ c.id_route }})</template>
                        </span>
                    </label>
                </div>

                <div class="flex items-center justify-between gap-2 pt-2">
                    <button
                        type="button"
                        @click="retour"
                        class="px-4 py-2 rounded-lg border border-surface-border text-[13px] text-ink-muted hover:bg-stone-50"
                    >
                        ← Retour
                    </button>
                    <div class="flex items-center gap-3">
                        <span v-if="mode === 'auto'" class="text-[12.5px] text-ink-muted"
                            >{{ selection.size }} chauffeur(s)</span
                        >
                        <button
                            type="button"
                            :disabled="!chauffeurPret || enCours"
                            @click="continuerChauffeurs"
                            class="px-4 py-2 rounded-lg bg-accent text-white text-[13px] font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Continuer
                        </button>
                    </div>
                </div>
            </div>

            <!-- 4a. Récapitulatif (automatique) -->
            <div v-else-if="etape === 'recap' && resume" class="space-y-4">
                <dl
                    class="divide-y divide-surface-3 rounded-lg border border-surface-border bg-surface-2 text-[13.5px]"
                >
                    <div class="flex justify-between gap-4 px-3 py-2">
                        <dt class="text-ink-muted">Chauffeurs retenus</dt>
                        <dd class="font-medium text-ink">{{ resume.chauffeurs }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-3 py-2">
                        <dt class="text-ink-muted">Familles éligibles sur ce créneau</dt>
                        <dd class="font-medium text-ink">{{ resume.familles }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-3 py-2">
                        <dt class="text-ink-muted">Poids à livrer</dt>
                        <dd class="font-medium text-ink">{{ resume.poids_kg }} kg</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-3 py-2">
                        <dt class="text-ink-muted">Capacité des véhicules</dt>
                        <dd class="font-medium text-ink">{{ resume.capacite_kg }} kg</dd>
                    </div>
                </dl>

                <p
                    v-if="aucuneFamille"
                    class="rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-[13px] px-3 py-2"
                >
                    Aucune famille confirmée en attente de tournée pour ce créneau.
                </p>
                <p
                    v-if="capaciteInsuffisante"
                    class="rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-[13px] px-3 py-2"
                >
                    Le poids dépasse la capacité des véhicules choisis : une partie des familles restera sans tournée
                    (reprises à un autre créneau ou avec d'autres chauffeurs).
                </p>
                <p
                    v-if="resume.sans_coordonnees > 0"
                    class="rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-[13px] px-3 py-2"
                >
                    {{ resume.sans_coordonnees }} famille(s) sans coordonnées géographiques seront ignorées (adresse à
                    corriger).
                </p>

                <div class="flex items-center justify-between gap-2 pt-2">
                    <button
                        type="button"
                        @click="retour"
                        class="px-4 py-2 rounded-lg border border-surface-border text-[13px] text-ink-muted hover:bg-stone-50"
                    >
                        ← Retour
                    </button>
                    <button
                        type="button"
                        :disabled="enCours || aucuneFamille"
                        @click="generer"
                        class="px-4 py-2 rounded-lg bg-accent text-white text-[13px] font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {{ enCours ? "Génération…" : "Générer les routes" }}
                    </button>
                </div>
            </div>

            <!-- 4b. Familles (personnalisé) -->
            <div v-else-if="etape === 'familles'" class="space-y-3">
                <p class="text-[13px] text-ink-muted">
                    Familles à confier à {{ chauffeurPersonnalise?.prenom }} {{ chauffeurPersonnalise?.nom }} ({{
                        chauffeurPersonnalise?.vehicule
                    }}
                    · {{ chauffeurPersonnalise?.capacite_kg }} kg). L'ordre des arrêts est optimisé, la répartition est
                    la vôtre.
                </p>

                <FamilleFilterPanel
                    :villes="villes"
                    :secteurs="secteurs"
                    :quartiers="quartiers"
                    :organisations="organisations"
                    :model-value="filtres"
                    @update:model-value="filtres = $event"
                    @filtrer="chargerLignes(1)"
                />

                <label class="flex items-center gap-2 text-[12.5px] text-ink-muted">
                    <input v-model="autresCreneaux" type="checkbox" class="w-3.5 h-3.5 accent-accent" />
                    Afficher aussi les familles confirmées pour d'autres créneaux
                </label>

                <div class="overflow-x-auto border border-surface-border rounded-lg max-h-[20rem] overflow-y-auto">
                    <table class="w-full text-[13px]">
                        <thead>
                            <tr class="text-left text-ink-muted border-b border-surface-border bg-stone-50">
                                <th class="px-3 py-2 font-medium">
                                    <input
                                        type="checkbox"
                                        :checked="
                                            lignes.length > 0 &&
                                            lignes.every((f) => idsLivraisons.has(f.id_livraison as number))
                                        "
                                        :disabled="chargementSelectionTout"
                                        @change="toutSelectionnerFiltre"
                                        class="w-4 h-4 accent-accent"
                                    />
                                </th>
                                <th class="px-3 py-2 font-medium cursor-pointer select-none" @click="trierPar('id')">
                                    ID <span v-if="tri === 'id'">{{ directionTri === "asc" ? "▲" : "▼" }}</span>
                                </th>
                                <th class="px-3 py-2 font-medium cursor-pointer select-none" @click="trierPar('nom')">
                                    Nom <span v-if="tri === 'nom'">{{ directionTri === "asc" ? "▲" : "▼" }}</span>
                                </th>
                                <th class="px-3 py-2 font-medium">Adresse</th>
                                <th class="px-3 py-2 font-medium">Poids</th>
                                <th
                                    class="px-3 py-2 font-medium cursor-pointer select-none"
                                    @click="trierPar('criticite')"
                                >
                                    Criticité
                                    <span v-if="tri === 'criticite'">{{ directionTri === "asc" ? "▲" : "▼" }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="chargementLignes">
                                <td colspan="6" class="px-3 py-3 text-ink-muted">Chargement…</td>
                            </tr>
                            <tr v-else-if="erreurLignes">
                                <td colspan="6" class="px-3 py-3 text-rose-600">Impossible de charger les familles.</td>
                            </tr>
                            <tr v-else-if="lignes.length === 0">
                                <td colspan="6" class="px-3 py-3 text-ink-muted">
                                    Aucune famille à livrer pour ces filtres.
                                </td>
                            </tr>
                            <tr
                                v-for="famille in lignes"
                                :key="famille.id_livraison ?? famille.id"
                                class="border-b border-surface-border last:border-0 hover:bg-stone-50"
                            >
                                <td class="px-3 py-2">
                                    <input
                                        type="checkbox"
                                        :checked="idsLivraisons.has(famille.id_livraison as number)"
                                        @change="basculerLivraison(famille.id_livraison)"
                                        class="w-4 h-4 accent-accent"
                                    />
                                </td>
                                <td class="px-3 py-2 text-ink-muted">#{{ famille.id }}</td>
                                <td class="px-3 py-2 text-ink">{{ famille.prenom }} {{ famille.nom }}</td>
                                <td class="px-3 py-2 text-ink-muted">
                                    {{ famille.adresse || "—"
                                    }}<span v-if="famille.quartier"> — {{ famille.quartier.nom }}</span>
                                </td>
                                <td class="px-3 py-2 text-ink-muted">
                                    {{ famille.poids_kg !== undefined ? `${famille.poids_kg} kg` : "—" }}
                                </td>
                                <td class="px-3 py-2 text-ink-muted">{{ famille.criticite ?? "—" }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <PaginationControls v-if="metaLignes" :meta="metaLignes" @change="chargerLignes" />

                <p
                    v-if="depasseCapacite"
                    class="rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-[13px] px-3 py-2"
                >
                    Poids sélectionné : {{ poidsSelectionne }} kg — au-dessus de la capacité du véhicule ({{
                        chauffeurPersonnalise?.capacite_kg
                    }}
                    kg).
                </p>

                <div class="flex items-center justify-between gap-2 pt-2">
                    <button
                        type="button"
                        @click="retour"
                        class="px-4 py-2 rounded-lg border border-surface-border text-[13px] text-ink-muted hover:bg-stone-50"
                    >
                        ← Retour
                    </button>
                    <div class="flex items-center gap-3">
                        <span class="text-[12.5px] text-ink-muted"
                            >{{ idsLivraisons.size }} famille(s) · {{ poidsSelectionne }} kg</span
                        >
                        <button
                            type="button"
                            :disabled="enCours || idsLivraisons.size === 0"
                            @click="creerTournee"
                            class="px-4 py-2 rounded-lg bg-accent text-white text-[13px] font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {{ enCours ? "Création…" : "Créer la tournée" }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- 5. Terminé -->
            <div v-else-if="etape === 'termine'" class="space-y-4">
                <p class="rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-[13.5px] px-4 py-3">
                    ✅ {{ resultat }}
                </p>
                <div class="flex justify-end gap-2">
                    <button
                        v-if="mode === 'custom'"
                        type="button"
                        @click="autreTournee"
                        class="px-4 py-2 rounded-lg border border-accent text-accent text-[13px] font-semibold hover:bg-accent/5"
                    >
                        Créer une autre tournée
                    </button>
                    <button
                        type="button"
                        @click="emit('close')"
                        class="px-4 py-2 rounded-lg bg-accent text-white text-[13px] font-semibold"
                    >
                        Terminer
                    </button>
                </div>
            </div>
        </div>
    </Modal>
</template>
