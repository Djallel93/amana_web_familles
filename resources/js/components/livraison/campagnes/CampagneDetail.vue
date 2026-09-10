<!-- resources/js/components/livraison/campagnes/CampagneDetail.vue -->
<!--
    Écran détail campagne — reconstruit en Vue le 03/09/2026, révisé le
    05/09/2026 (voir resources/views/livraison/campagne-detail.blade.php
    et le prompt de cette date §1) :
      - HQ propre à la campagne + commentaire (§1.2/§1.3), édition inline ;
      - rangée de boutons de navigation + Notifier bénévole, une couleur
        distincte chacun (§1.4) ;
      - bouton clustering RETIRÉ d'ici (05/09/2026 §1.5) — déplacé sur
        Suivi des contacts (ContactsQueue.vue) — puis RE-déplacé ICI le
        09/09/2026 (prompt de cette date §2.2), toujours gated sur "plus
        aucune famille à contacter pour la journée choisie" (même règle,
        vérifiée à nouveau côté serveur par LiveBoardController::
        genererRoutes()) ; remonté au-dessus de "Sélection des familles
        éligibles" le 09/09/2026 (même prompt §2.2, second passage) ;
      - sélection des familles éligibles : vraie table + FamilleFilterPanel
        partagé + sélection croisant les pages via ids_only (§1.6) ;
      - bouton renommé, désactivé tant qu'aucune sélection (§1.7) ;
      - section "Livraisons confirmées jamais couvertes" supprimée (§1.8,
        reste disponible sur le tableau de bord via ShortfallPanel.vue).
-->
<script setup lang="ts">
import { ref, reactive, computed, onMounted } from "vue";
import { useToast, useConfirm } from "@amana/shared-ui";
import { apiGet, apiPatch, apiPost, buildQuery } from "../shared/api";
import Paginator from "../shared/Paginator.vue";
import FamilleFilterPanel from "../shared/FamilleFilterPanel.vue";
import CampagneProgressBar, {
    type AvancementCampagne,
} from "./CampagneProgressBar.vue";
import HqCoordinatesAutocomplete from "../../admin/HqCoordinatesAutocomplete.vue";
import {
    CAMPAGNE_TYPES,
    normalizePaginated,
    type Campagne,
    type CampagneJournee,
    type CampagnePoidsMoyenHistorique,
    type FamilleEligible,
    type FamilleFiltres,
    type GenererLivraisonsResultat,
    type GenererRoutesResultat,
    type Organisation,
    type Paginated,
    type Quartier,
    type RawLaravelPaginator,
    type Secteur,
    type Ville,
} from "../shared/types";

const toast = useToast();
const confirmDialog = useConfirm();

const el = document.getElementById("vue-livraison-campagne-detail")!;
const campagne = ref<Campagne>(JSON.parse(el.dataset.campagne ?? "{}"));
const quartiers = ref<Quartier[]>(JSON.parse(el.dataset.quartiers ?? "[]"));
const villes = ref<Ville[]>(JSON.parse(el.dataset.villes ?? "[]"));
const secteurs = ref<Secteur[]>(JSON.parse(el.dataset.secteurs ?? "[]"));
const organisations = ref<Organisation[]>(
    JSON.parse(el.dataset.organisations ?? "[]"),
);
const googlePlacesKey = el.dataset.googlePlacesKey ?? "";
const urls = {
    eligibles: el.dataset.eligiblesUrl ?? "",
    genererLivraisons: el.dataset.genererLivraisonsUrl ?? "",
    // Ajoutés le 09/09/2026 (prompt §2.2) : bouton clustering déplacé ici.
    genererRoutes: el.dataset.genererRoutesUrl ?? "",
    queue: el.dataset.queueUrl ?? "",
    // Ajouté le 09/09/2026 (prompt de cette date §1.5, second passage) :
    // total des livraisons de la journée (indépendamment de
    // statut_contact) — voir aucuneLivraison ci-dessous, distinct de
    // resteAContacter qui ne compte que 'a_contacter'.
    contactsStatistiques: el.dataset.contactsStatistiquesUrl ?? "",
    benevoles: el.dataset.benevolesUrl ?? "",
    equipes: el.dataset.equipesUrl ?? "",
    // Ajouté le 09/09/2026 (prompt de cette date §1.1/§1.2).
    reception: el.dataset.receptionUrl ?? "",
    ajouterJournee: el.dataset.ajouterJourneeUrl ?? "",
    avancement: el.dataset.avancementUrl ?? "",
    update: el.dataset.updateUrl ?? "",
    contacts: el.dataset.contactsUrl ?? "",
    pesee: el.dataset.peseeUrl ?? "",
    packaging: el.dataset.packagingUrl ?? "",
    chargement: el.dataset.chargementUrl ?? "",
    suiviLivraison: el.dataset.suiviLivraisonUrl ?? "",
    // Ajouté le 09/09/2026 (prompt de cette date §1.3) : campagne
    // présélectionnée sur l'écran Statistiques, même patron que
    // suivi-livraison (data-campagne-id).
    statistiques: el.dataset.statistiquesUrl ?? "",
};

function formatDateFr(iso: string): string {
    const [annee, mois, jour] = iso.split("T")[0].split("-");
    return `${jour}/${mois}/${annee}`;
}

// ── Sélection de journée ─────────────────────────────────────────────────
const journees = ref<CampagneJournee[]>(campagne.value.journees ?? []);
const idJourneeSelectionnee = ref<number | null>(journees.value[0]?.id ?? null);

// ── Ajout d'une journée ──────────────────────────────────────────────────
const afficherFormAjoutJournee = ref(false);
const nouvelleJourneeDate = ref("");
const nouvelleJourneeLabel = ref("");
const chargementAjoutJournee = ref(false);
const erreurAjoutJournee = ref("");

async function ajouterJournee() {
    if (!nouvelleJourneeDate.value) {
        erreurAjoutJournee.value = "Choisissez une date.";
        return;
    }
    chargementAjoutJournee.value = true;
    erreurAjoutJournee.value = "";

    const resultat = await apiPost<{
        success: boolean;
        journee: CampagneJournee;
    }>(urls.ajouterJournee, {
        date: nouvelleJourneeDate.value,
        label: nouvelleJourneeLabel.value || null,
    });
    chargementAjoutJournee.value = false;

    if (!resultat.ok) {
        erreurAjoutJournee.value = resultat.message;
        return;
    }

    journees.value = [...journees.value, resultat.data.journee];
    idJourneeSelectionnee.value = resultat.data.journee.id;
    nouvelleJourneeDate.value = "";
    nouvelleJourneeLabel.value = "";
    afficherFormAjoutJournee.value = false;
    toast.success("Journée ajoutée.");
}

// ── HQ propre à la campagne + commentaire (05/09/2026, prompt §1.2/§1.3) ──
// Édition inline sur cette page (pas de page d'édition séparée dans cette
// app) — hq_latitude/hq_longitude préremplies au réglage global à la
// création (voir CampagnesController::store()), simples champs numériques
// éditables ici plutôt que de réintégrer le widget Google Places de
// Paramètres (HqCoordinatesAutocomplete.vue, conçu pour cibler des inputs
// DOM par id sur cette page-là spécifiquement) : l'adresse saisie ici sert
// avant tout de LIBELLÉ de log, pas de source d'autorité pour le calcul de
// tournée (voir docblock de la migration campagnes).
const afficherFormEdition = ref(false);
const formEdition = reactive({
    commentaire: campagne.value.commentaire ?? "",
    hq_adresse: campagne.value.hq_adresse ?? "",
    hq_latitude: campagne.value.hq_latitude ?? "",
    hq_longitude: campagne.value.hq_longitude ?? "",
    // Ajouté le 08/09/2026 (prompt §2.2.3) — même statut que hq_* ci-dessus :
    // préremplie au réglage global à la création, éditable ici au cas par cas.
    livraisons_max_par_tournee: campagne.value.livraisons_max_par_tournee ?? "",
});
const chargementEdition = ref(false);
const erreurEdition = ref("");

async function enregistrerEdition() {
    chargementEdition.value = true;
    erreurEdition.value = "";

    const resultat = await apiPatch<{ success: boolean; campagne: Campagne }>(
        urls.update,
        {
            commentaire: formEdition.commentaire || null,
            hq_adresse: formEdition.hq_adresse || null,
            hq_latitude:
                formEdition.hq_latitude === ""
                    ? null
                    : Number(formEdition.hq_latitude),
            hq_longitude:
                formEdition.hq_longitude === ""
                    ? null
                    : Number(formEdition.hq_longitude),
            livraisons_max_par_tournee:
                formEdition.livraisons_max_par_tournee === ""
                    ? null
                    : Number(formEdition.livraisons_max_par_tournee),
        },
    );
    chargementEdition.value = false;

    if (!resultat.ok) {
        erreurEdition.value = resultat.message;
        return;
    }

    campagne.value = { ...campagne.value, ...resultat.data.campagne };
    afficherFormEdition.value = false;
    toast.success("HQ & commentaire confirmés.");
}

// Couleur de la section HQ & commentaire (09/09/2026, prompt de cette date
// §3.2 ; orange RETIRÉ le 09/09/2026, prompt de cette date §1.4 : "If HQ
// was define at campagne creation do not color HQ & commentaire section
// in orange" — un HQ est TOUJOURS défini à la création dès que le réglage
// global l'est (CampagnesController::store() le recopie automatiquement),
// donc l'ancien état orange "hérité mais jamais confirmé" ne signalait pas
// une vraie donnée manquante. hq_confirmee_le reste posé par le bouton
// "Confirmer" (traçabilité côté serveur) mais n'a plus d'effet visuel ici.
// Seul cas restant : rouge si cette campagne n'a AUCUN HQ du tout (ni
// saisi, ni hérité — ne peut arriver que si le réglage global lui-même
// n'était pas configuré au moment de la création).
const hqCouleur = computed<"rouge" | null>(() => {
    if (!campagne.value.hq_latitude && !campagne.value.hq_longitude)
        return "rouge";
    return null;
});

// ── Filtre + table des familles éligibles (05/09/2026, prompt §1.6) ──────
const filtres = ref<FamilleFiltres>({});
// Tri colonne par colonne (05/09/2026, prompt §1.2.3) — mêmes clés que
// CampagnesController::COLONNES_TRIABLES_ELIGIBLES.
const tri = ref<string | null>(null);
const directionTri = ref<"asc" | "desc">("asc");

function trierPar(colonne: string) {
    if (tri.value === colonne) {
        directionTri.value = directionTri.value === "asc" ? "desc" : "asc";
    } else {
        tri.value = colonne;
        directionTri.value = "asc";
    }
    chargerEligibles(1);
}

const eligibles = ref<FamilleEligible[]>([]);
const metaEligibles = ref<Paginated<FamilleEligible>["meta"] | null>(null);
const chargementEligibles = ref(true);
const erreurEligibles = ref(false);

// La sélection survit à la pagination et au changement de filtre — Set
// d'ids plutôt qu'un tableau de lignes cochées par page.
const selectionnees = ref<Set<number>>(new Set());

function queryFiltres(page: number) {
    return buildQuery({
        page,
        tri: tri.value,
        direction: tri.value ? directionTri.value : undefined,
        id_ville: filtres.value.id_ville,
        id_secteur: filtres.value.id_secteur,
        id_quartier: filtres.value.id_quartier,
        criticite: filtres.value.criticite,
        se_deplace: filtres.value.se_deplace || undefined,
        est_hotel: filtres.value.est_hotel || undefined,
        etudiant: filtres.value.etudiant || undefined,
        zakat_el_fitr: filtres.value.zakat_el_fitr || undefined,
        sadaqa: filtres.value.sadaqa || undefined,
        id_organisation_origine: filtres.value.id_organisation_origine,
        id_organisation_rattachee: filtres.value.id_organisation_rattachee,
        recherche: filtres.value.recherche,
    });
}

async function chargerEligibles(page = 1) {
    chargementEligibles.value = true;
    erreurEligibles.value = false;

    const resultat = await apiGet<RawLaravelPaginator<FamilleEligible>>(
        `${urls.eligibles}${queryFiltres(page)}`,
    );
    chargementEligibles.value = false;

    if (!resultat.ok) {
        erreurEligibles.value = true;
        return;
    }

    const paginé = normalizePaginated(resultat.data);
    eligibles.value = paginé.data;
    metaEligibles.value = paginé.meta;
}

function toggleFamille(id: number) {
    if (selectionnees.value.has(id)) selectionnees.value.delete(id);
    else selectionnees.value.add(id);
    selectionnees.value = new Set(selectionnees.value);
}

/**
 * Sélectionner/désélectionner TOUT ce qui correspond au filtre courant,
 * pas seulement la page affichée (prompt §1.6.3) — interroge
 * .../eligibles?ids_only=1 pour récupérer les ids sur toutes les pages en
 * un appel plutôt que de paginer manuellement.
 */
const chargementSelectionTout = ref(false);

async function toutSelectionnerFiltre() {
    const pageActuelleIds = new Set(eligibles.value.map((f) => f.id));
    const dejaToutSelectionne =
        eligibles.value.length > 0 &&
        eligibles.value.every((f) => selectionnees.value.has(f.id));

    if (dejaToutSelectionne) {
        // Décoche uniquement ce qui vient du filtre courant, pas une
        // sélection faite plus tôt sous un autre filtre.
        for (const id of pageActuelleIds) selectionnees.value.delete(id);
        selectionnees.value = new Set(selectionnees.value);
        return;
    }

    chargementSelectionTout.value = true;
    const resultat = await apiGet<{ ids: number[] }>(
        `${urls.eligibles}${queryFiltres(1)}&ids_only=1`,
    );
    chargementSelectionTout.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    resultat.data.ids.forEach((id) => selectionnees.value.add(id));
    selectionnees.value = new Set(selectionnees.value);
}

// ── Génération des livraisons ───────────────────────────────────────────
const chargementGeneration = ref(false);
const resultatGeneration = ref<GenererLivraisonsResultat | null>(null);
const erreurGeneration = ref("");

async function genererLivraisons() {
    if (selectionnees.value.size === 0 || idJourneeSelectionnee.value === null)
        return;

    chargementGeneration.value = true;
    erreurGeneration.value = "";
    resultatGeneration.value = null;

    const resultat = await apiPost<GenererLivraisonsResultat>(
        urls.genererLivraisons,
        {
            ids_familles: [...selectionnees.value],
            id_campagne_journee: idJourneeSelectionnee.value,
        },
    );
    chargementGeneration.value = false;

    if (!resultat.ok) {
        erreurGeneration.value = resultat.message;
        return;
    }

    resultatGeneration.value = resultat.data;
    selectionnees.value = new Set();
    // Libellé recentré sur l'action de l'utilisateur (09/09/2026, prompt
    // §2.1 : "instead of something like famille(s) ajoutée(s)") — compte
    // resultat.data.generees (familles réellement ajoutées), pas la
    // sélection brute qui peut inclure des conflits/déjà-existantes ; le
    // détail génération/déjà existantes/conflits reste affiché juste en
    // dessous (voir resultatGeneration dans le template).
    toast.success(`${resultat.data.generees} famille(s) ajoutée(s).`);
    chargerAvancement();
    chargerEligibles(metaEligibles.value?.current_page ?? 1);
    // Ajouté le 09/09/2026 (prompt de cette date §1.5) : le bouton
    // "Génération des routes" restait grisé-à-tort ou actif-à-tort après
    // un ajout de familles tant que la page n'était pas rechargée
    // (resteAContacter/aucuneLivraison ne se recalculaient qu'au montage
    // ou au changement de journée) — recalculé ici pour rester à jour
    // sans rechargement.
    verifierGateClustering();
}

// ── Notification bénévoles ──────────────────────────────────────────────
// notifierBenevoles()/resultatNotif retirés le 05/09/2026 (prompt §1.3 :
// "transfer Notifier bénévole to this new view") — le bouton et son état
// vivent désormais sur BenevoleDisponibiliteQueue.vue (écran de suivi des
// réponses), pas ici.

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
        id_campagne: campagne.value.id,
        id_campagne_journee: idJourneeSelectionnee.value,
    };
    const [resultatReste, resultatTotal] = await Promise.all([
        apiGet<{ ids: number[] }>(
            urls.queue +
                buildQuery({
                    ...filtresJournee,
                    statut_contact: "a_contacter",
                    ids_only: 1,
                }),
        ),
        apiGet<{ total: number }>(
            urls.contactsStatistiques + buildQuery(filtresJournee),
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

    const resultat = await apiPost<GenererRoutesResultat>(urls.genererRoutes, {
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
}

function surChangementJournee() {
    verifierGateClustering();
}

onMounted(() => {
    chargerEligibles(1);
    chargerAvancement();
    verifierGateClustering();
});

// ── Avancement (checklist) ───────────────────────────────────────────────
const avancement = ref<AvancementCampagne | null>(null);

async function chargerAvancement() {
    const resultat = await apiGet<AvancementCampagne>(urls.avancement);
    if (resultat.ok) avancement.value = resultat.data;
}

// ── Historique des poids moyens (lecture seule ici — édition sur l'écran
//    Packaging, voir le prompt §5.2 : "Add a section... under Packaging") ─
const historiquePoids = ref<CampagnePoidsMoyenHistorique[]>(
    campagne.value.poids_moyen_historique ?? [],
);
</script>

<template>
    <div>
        <h1 class="font-heading text-xl font-semibold text-ink mb-1">
            {{ CAMPAGNE_TYPES[campagne.type] ?? campagne.type }} —
            {{ formatDateFr(campagne.date_livraison) }}
        </h1>
        <p class="text-[13px] text-ink-muted mb-6">
            Statut : {{ campagne.statut }}
        </p>

        <CampagneProgressBar :avancement="avancement" />

        <!--
            Rangée de navigation + Notifier bénévole (05/09/2026, prompt
            §1.4) : Notifier bénévole rejoint la même rangée que les liens
            vers les écrans des étapes suivantes, chacun avec sa propre
            couleur pour les distinguer d'un coup d'œil — contrairement à
            avant où tous les liens de navigation étaient dans le même
            style neutre et Notifier bénévole était un bouton à part sur sa
            propre ligne avec le (désormais retiré) bouton clustering.

            Scindée en deux rangées le 09/09/2026 (prompt de cette date
            §1.3 : "Since there are more and more pills split them on two
            rows") — Réception et Statistiques ajoutées le même jour
            (§1.1/§1.3) ont fait passer le total à 9, plus assez lisible
            sur une seule ligne y compris en wrap. Rangée 1 = préparation/
            staffing de la journée (bénévoles → équipes → contact →
            réception → pesée) ; rangée 2 = suite de la chaîne matérielle
            (packaging → chargement → suivi → statistiques).
        -->
        <div class="flex flex-wrap gap-2 mb-2">
            <!--
                Suivi des bénévoles + Équipes déplacés avant Suivi des
                contacts (08/09/2026, prompt de cette date §4) — les
                équipes/disponibilités bénévoles sont désormais montrées
                comme un préalable au contact famille dans le flux, pas
                après.
            -->
            <a
                :href="urls.benevoles"
                class="text-[12.5px] px-3 py-1.5 rounded-lg text-white bg-emerald-600 hover:opacity-90"
            >
                👥 Suivi des bénévoles
            </a>
            <!--
                Ajouté le 08/09/2026 (prompt de cette date) : écran dédié
                pour peupler campagne_equipe_membres (affectations
                équipe_* PAR CAMPAGNE) — voir EquipeMembresQueue.vue.
                Distinct de "Suivi des bénévoles" ci-dessus, qui gère la
                disponibilité déclarée par le bénévole lui-même, pas les
                rôles équipe_reception/pesee/packaging/chargement.

                Déplacée entre Suivi des contacts et Réception le
                09/09/2026 (prompt de cette date §1.2).
            -->
            <a
                :href="urls.contacts"
                class="text-[12.5px] px-3 py-1.5 rounded-lg text-white bg-sky-600 hover:opacity-90"
            >
                📞 Suivi des contacts
            </a>
            <a
                :href="urls.equipes"
                class="text-[12.5px] px-3 py-1.5 rounded-lg text-white bg-sky-600 hover:opacity-90"
            >
                🧑‍🤝‍🧑 Équipes
            </a>
            <!-- Ajouté le 09/09/2026 (prompt de cette date §1.1) : équipe_
                 reception n'avait jusqu'ici aucun lien depuis cet écran
                 (seulement via son propre point d'entrée "choisir", voir
                 config/amana-shared.php) — même route que Pesée/Packaging/
                 Chargement ci-dessous (livraison.reception.show). -->
            <a
                :href="urls.reception"
                class="text-[12.5px] px-3 py-1.5 rounded-lg text-white bg-indigo-600 hover:opacity-90"
            >
                🧾 Réception
            </a>
            <a
                :href="urls.pesee"
                class="text-[12.5px] px-3 py-1.5 rounded-lg text-white bg-amber-600 hover:opacity-90"
            >
                ⚖️ Pesée
            </a>
        </div>
        <div class="flex flex-wrap gap-2 mb-6">
            <a
                :href="urls.packaging"
                class="text-[12.5px] px-3 py-1.5 rounded-lg text-white bg-violet-600 hover:opacity-90"
            >
                📦 Packaging
            </a>
            <a
                :href="urls.chargement"
                class="text-[12.5px] px-3 py-1.5 rounded-lg text-white bg-rose-600 hover:opacity-90"
            >
                🚛 Chargement
            </a>
            <a
                :href="urls.suiviLivraison"
                class="text-[12.5px] px-3 py-1.5 rounded-lg text-white bg-teal-600 hover:opacity-90"
            >
                🗺️ Suivi livraison
            </a>
            <!-- Ajouté le 09/09/2026 (prompt de cette date §1.3) : pointe
                 directement sur cette campagne, même patron que
                 Suivi livraison (data-campagne-id, voir
                 StatistiquesController::index()). -->
            <a
                :href="urls.statistiques"
                class="text-[12.5px] px-3 py-1.5 rounded-lg text-white bg-fuchsia-600 hover:opacity-90"
            >
                📊 Statistiques
            </a>
        </div>

        <!-- Sélecteur de journée -->
        <div v-if="journees.length > 1" class="mb-3">
            <label class="block text-[12.5px] font-medium text-ink-muted mb-1"
                >Journée</label
            >
            <select
                v-model.number="idJourneeSelectionnee"
                @change="surChangementJournee"
                class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]"
            >
                <option
                    v-for="journee in journees"
                    :key="journee.id"
                    :value="journee.id"
                >
                    {{ journee.label ?? formatDateFr(journee.date) }} —
                    {{ formatDateFr(journee.date) }}
                </option>
            </select>
        </div>

        <div class="mb-6">
            <button
                v-if="!afficherFormAjoutJournee"
                type="button"
                @click="afficherFormAjoutJournee = true"
                class="text-[12.5px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted hover:bg-stone-50"
            >
                + Ajouter une journée
            </button>
            <form
                v-else
                @submit.prevent="ajouterJournee"
                class="flex flex-col sm:flex-row gap-2 sm:items-end bg-surface border border-surface-border rounded-xl p-4"
            >
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1"
                        >Date</label
                    >
                    <input
                        v-model="nouvelleJourneeDate"
                        type="date"
                        required
                        class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]"
                    />
                </div>
                <div class="flex-1">
                    <label class="block text-[12px] text-ink-muted mb-1"
                        >Label (optionnel)</label
                    >
                    <input
                        v-model="nouvelleJourneeLabel"
                        type="text"
                        placeholder="ex: Livraison (jour 2)"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]"
                    />
                </div>
                <div class="flex gap-2">
                    <button
                        type="submit"
                        :disabled="chargementAjoutJournee"
                        class="min-h-[2.25rem] text-[13px] px-3 py-1.5 rounded-lg bg-accent text-white disabled:opacity-60"
                    >
                        {{ chargementAjoutJournee ? "Ajout…" : "Ajouter" }}
                    </button>
                    <button
                        type="button"
                        @click="afficherFormAjoutJournee = false"
                        class="min-h-[2.25rem] text-[13px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted"
                    >
                        Annuler
                    </button>
                </div>
            </form>
            <p v-if="erreurAjoutJournee" class="text-[13px] text-rose-600 mt-2">
                {{ erreurAjoutJournee }}
            </p>
        </div>

        <!--
            HQ + commentaire (05/09/2026, prompt §1.2/§1.3).
            Couleur (09/09/2026, prompt de cette date §3.2 ; orange retiré
            le 09/09/2026, prompt de cette date §1.4) — voir hqCouleur
            ci-dessus : rouge = aucun HQ du tout (ni saisi, ni réglage
            global configuré).
        -->
        <div
            class="bg-surface border rounded-xl p-5 mb-6"
            :class="{
                'border-rose-300 bg-rose-50/60': hqCouleur === 'rouge',
                'border-surface-border': hqCouleur === null,
            }"
        >
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-[14px] font-medium text-ink">
                    HQ &amp; commentaire
                </h2>
                <button
                    type="button"
                    @click="afficherFormEdition = !afficherFormEdition"
                    class="text-[12.5px] text-accent"
                >
                    {{ afficherFormEdition ? "Fermer" : "Modifier" }}
                </button>
            </div>

            <template v-if="!afficherFormEdition">
                <p class="text-[13px] text-ink-muted">
                    HQ : {{ campagne.hq_adresse || "non renseigné" }}
                    <span v-if="campagne.hq_latitude && campagne.hq_longitude">
                        ({{ campagne.hq_latitude }},
                        {{ campagne.hq_longitude }})
                    </span>
                </p>
                <p class="text-[13px] text-ink-muted mt-1">
                    Max livraisons/tournée :
                    {{
                        campagne.livraisons_max_par_tournee ?? "réglage global"
                    }}
                </p>
                <p class="text-[13px] text-ink mt-2 whitespace-pre-wrap">
                    {{ campagne.commentaire || "Aucun commentaire." }}
                </p>
            </template>

            <form v-else @submit.prevent="enregistrerEdition" class="space-y-3">
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1"
                        >Adresse HQ (libellé, pour le log)</label
                    >
                    <input
                        v-model="formEdition.hq_adresse"
                        type="text"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]"
                    />
                </div>
                <!--
                    Autocomplétion Google Maps + bascule saisie manuelle
                    (05/09/2026, prompt §1.1) — même composant que l'écran
                    Paramètres (HqCoordinatesAutocomplete.vue, généralisé
                    ce même jour pour être réutilisable ainsi). Écrit
                    directement dans les 2 inputs ci-dessous via leur id
                    (campagne-hq-lat/campagne-hq-lng) + un évènement
                    'input', que v-model capte normalement.
                -->
                <HqCoordinatesAutocomplete
                    :google-places-key="googlePlacesKey"
                    target-lat-id="campagne-hq-lat"
                    target-lng-id="campagne-hq-lng"
                />
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[12px] text-ink-muted mb-1"
                            >Latitude</label
                        >
                        <input
                            id="campagne-hq-lat"
                            v-model="formEdition.hq_latitude"
                            type="number"
                            step="any"
                            readonly
                            class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem] bg-stone-50 text-ink-muted"
                        />
                    </div>
                    <div>
                        <label class="block text-[12px] text-ink-muted mb-1"
                            >Longitude</label
                        >
                        <input
                            id="campagne-hq-lng"
                            v-model="formEdition.hq_longitude"
                            type="number"
                            step="any"
                            readonly
                            class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem] bg-stone-50 text-ink-muted"
                        />
                    </div>
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1"
                        >Max livraisons/tournée</label
                    >
                    <input
                        v-model="formEdition.livraisons_max_par_tournee"
                        type="number"
                        min="1"
                        step="1"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]"
                    />
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1"
                        >Commentaire</label
                    >
                    <textarea
                        v-model="formEdition.commentaire"
                        rows="3"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px]"
                    ></textarea>
                </div>
                <!-- Renommé "Enregistrer" → "Confirmer" (09/09/2026, prompt
                     §3.2) : pressé = HQ confirmé pour cette campagne, voir
                     hq_confirmee_le/hqCouleur ci-dessus. -->
                <button
                    type="submit"
                    :disabled="chargementEdition"
                    class="min-h-[2.25rem] text-[13px] px-4 py-1.5 rounded-lg bg-accent text-white disabled:opacity-60"
                >
                    {{ chargementEdition ? "Confirmation…" : "Confirmer" }}
                </button>
                <p v-if="erreurEdition" class="text-[13px] text-rose-600">
                    {{ erreurEdition }}
                </p>
            </form>

            <div
                v-if="historiquePoids.length > 0"
                class="mt-4 pt-4 border-t border-surface-border"
            >
                <p class="text-[12px] font-medium text-ink-muted mb-1">
                    Historique des poids moyens
                </p>
                <p class="text-[12px] text-ink-muted">
                    Voir l'écran Packaging pour modifier les poids moyens et
                    consulter l'historique complet.
                </p>
            </div>
        </div>

        <!--
            Clustering / génération des routes (09/09/2026, prompt de cette
            date §2.2) — déplacé ici depuis Suivi des contacts, puis
            REMONTÉ au-dessus de "Sélection des familles éligibles" le
            09/09/2026 (même prompt §2.2 : "Move Clustering / génération
            des route above Sélection des familles éligibles"). Ne peut se
            lancer que si une journée est choisie ET qu'il ne reste plus
            aucune famille à contacter pour cette journée — grisé sinon
            plutôt que de laisser tenter un appel qui échouera de toute
            façon côté serveur (même règle qu'avant le déplacement).
        -->
        <div
            class="bg-surface border border-surface-border rounded-xl p-5 mb-6"
        >
            <h2 class="text-[14px] font-medium text-ink mb-3">
                Clustering / génération des routes
            </h2>
            <button
                type="button"
                :disabled="
                    chargementRoutes ||
                    chargementVerifGate ||
                    (resteAContacter ?? 1) > 0 ||
                    aucuneLivraison !== false
                "
                @click="genererRoutes"
                class="min-h-[2.25rem] text-[13px] px-4 py-2 rounded-lg bg-accent text-white disabled:opacity-40 disabled:cursor-not-allowed"
            >
                🚚
                {{ chargementRoutes ? "Génération…" : "Génération des routes" }}
            </button>
            <p
                v-if="chargementVerifGate"
                class="text-[12.5px] text-ink-muted mt-2"
            >
                Vérification…
            </p>
            <!-- Ajouté le 09/09/2026 (prompt de cette date §1.5) : distinct
                 du message "toutes contactées" ci-dessous — aucune famille
                 n'a même été ajoutée à cette journée. -->
            <p
                v-else-if="aucuneLivraison === true"
                class="text-[12.5px] text-amber-700 mt-2"
            >
                Aucune famille n'a encore été ajoutée à cette journée.
            </p>
            <p
                v-else-if="(resteAContacter ?? 0) > 0"
                class="text-[12.5px] text-amber-700 mt-2"
            >
                {{ resteAContacter }} famille(s) encore à contacter pour cette
                journée.
            </p>
            <p
                v-else-if="resteAContacter === 0"
                class="text-[12.5px] text-emerald-700 mt-2"
            >
                Toutes les familles ont été contactées.
            </p>
            <p v-if="resultatRoutes" class="text-[12.5px] text-ink-muted mt-2">
                {{ resultatRoutes.routes_creees }} tournée(s) créée(s), dont
                {{ resultatRoutes.imposees }} imposée(s).
            </p>
            <p v-if="erreurRoutes" class="text-[12.5px] text-rose-600 mt-2">
                {{ erreurRoutes }}
            </p>
        </div>

        <!-- Sélection des familles éligibles (05/09/2026, prompt §1.6) -->
        <div
            class="bg-surface border border-surface-border rounded-xl p-5 mb-8"
        >
            <h2 class="text-[14px] font-medium text-ink mb-4">
                Sélection des familles éligibles
            </h2>

            <FamilleFilterPanel
                :villes="villes"
                :secteurs="secteurs"
                :quartiers="quartiers"
                :organisations="organisations"
                :model-value="filtres"
                @update:model-value="filtres = $event"
                @filtrer="chargerEligibles(1)"
            />

            <div
                class="overflow-x-auto mb-3 border border-surface-border rounded-lg"
            >
                <table class="w-full text-[13px]">
                    <thead>
                        <tr
                            class="text-left text-ink-muted border-b border-surface-border bg-stone-50"
                        >
                            <th class="px-3 py-2 font-medium">
                                <input
                                    type="checkbox"
                                    :checked="
                                        eligibles.length > 0 &&
                                        eligibles.every((f) =>
                                            selectionnees.has(f.id),
                                        )
                                    "
                                    :disabled="chargementSelectionTout"
                                    @change="toutSelectionnerFiltre"
                                    class="w-4 h-4 accent-accent"
                                />
                            </th>
                            <!-- Colonnes triables (05/09/2026, prompt §1.2.3) —
                                 clic sur l'en-tête, mêmes clés que
                                 CampagnesController::COLONNES_TRIABLES_ELIGIBLES. -->
                            <th
                                class="px-3 py-2 font-medium cursor-pointer select-none"
                                @click="trierPar('id')"
                            >
                                ID
                                <span v-if="tri === 'id'">{{
                                    directionTri === "asc" ? "▲" : "▼"
                                }}</span>
                            </th>
                            <th
                                class="px-3 py-2 font-medium cursor-pointer select-none"
                                @click="trierPar('nom')"
                            >
                                Nom
                                <span v-if="tri === 'nom'">{{
                                    directionTri === "asc" ? "▲" : "▼"
                                }}</span>
                            </th>
                            <th
                                class="px-3 py-2 font-medium cursor-pointer select-none"
                                @click="trierPar('telephone')"
                            >
                                Contact
                                <span v-if="tri === 'telephone'">{{
                                    directionTri === "asc" ? "▲" : "▼"
                                }}</span>
                            </th>
                            <th class="px-3 py-2 font-medium">Adresse</th>
                            <th
                                class="px-3 py-2 font-medium cursor-pointer select-none"
                                @click="trierPar('criticite')"
                            >
                                Criticité
                                <span v-if="tri === 'criticite'">{{
                                    directionTri === "asc" ? "▲" : "▼"
                                }}</span>
                            </th>
                            <th
                                class="px-3 py-2 font-medium cursor-pointer select-none"
                                @click="trierPar('derniere_livraison_le')"
                            >
                                Dernière livraison
                                <span v-if="tri === 'derniere_livraison_le'">{{
                                    directionTri === "asc" ? "▲" : "▼"
                                }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="chargementEligibles">
                            <td colspan="7" class="px-3 py-3 text-ink-muted">
                                Chargement…
                            </td>
                        </tr>
                        <tr v-else-if="erreurEligibles">
                            <td colspan="7" class="px-3 py-3 text-rose-600">
                                Impossible de charger les familles éligibles.
                            </td>
                        </tr>
                        <tr v-else-if="eligibles.length === 0">
                            <td colspan="7" class="px-3 py-3 text-ink-muted">
                                Aucune famille éligible pour ces filtres.
                            </td>
                        </tr>
                        <tr
                            v-for="famille in eligibles"
                            :key="famille.id"
                            class="border-b border-surface-border last:border-0 hover:bg-stone-50"
                        >
                            <td class="px-3 py-2">
                                <input
                                    type="checkbox"
                                    :checked="selectionnees.has(famille.id)"
                                    @change="toggleFamille(famille.id)"
                                    class="w-4 h-4 accent-accent"
                                />
                            </td>
                            <td class="px-3 py-2 text-ink-muted">
                                #{{ famille.id }}
                            </td>
                            <td class="px-3 py-2 text-ink">
                                {{ famille.prenom }} {{ famille.nom }}
                            </td>
                            <td class="px-3 py-2 text-ink-muted">
                                {{ famille.telephone || "—" }}
                                <span v-if="famille.telephone_bis">
                                    / {{ famille.telephone_bis }}</span
                                >
                            </td>
                            <td class="px-3 py-2 text-ink-muted">
                                {{ famille.adresse || "—" }}
                                <span v-if="famille.quartier"
                                    >— {{ famille.quartier.nom }}</span
                                >
                            </td>
                            <td class="px-3 py-2 text-ink-muted">
                                {{ famille.criticite ?? "—" }}
                            </td>
                            <td class="px-3 py-2 text-ink-muted">
                                {{
                                    famille.derniere_livraison_le
                                        ? formatDateFr(
                                              famille.derniere_livraison_le,
                                          )
                                        : "jamais livrée"
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Paginator
                v-if="metaEligibles"
                :meta="metaEligibles"
                @change="chargerEligibles"
            />

            <p class="text-[12px] text-ink-muted mt-3 mb-2">
                {{ selectionnees.size }} famille(s) sélectionnée(s)
            </p>
            <!-- Renommé + désactivé tant qu'aucune sélection (05/09/2026,
                 prompt §1.7) — auparavant toujours actif dès que
                 chargementGeneration était faux, cliquable même à 0
                 sélection. -->
            <button
                type="button"
                :disabled="chargementGeneration || selectionnees.size === 0"
                @click="genererLivraisons"
                class="min-h-[2.5rem] text-[13px] px-4 py-2 rounded-lg bg-accent text-white disabled:opacity-40 disabled:cursor-not-allowed"
            >
                {{
                    chargementGeneration
                        ? "Ajout…"
                        : "Ajouter les familles sélectionnées"
                }}
            </button>
            <p v-if="erreurGeneration" class="text-[13px] text-rose-600 mt-3">
                {{ erreurGeneration }}
            </p>

            <div
                v-if="resultatGeneration"
                class="mt-4 pt-4 border-t border-surface-border"
            >
                <p class="text-[13px] text-ink">
                    {{ resultatGeneration.generees }} livraison(s) générée(s),
                    {{ resultatGeneration.deja_existantes }} déjà existante(s).
                </p>
                <div
                    v-if="resultatGeneration.conflits.length > 0"
                    class="mt-2 bg-rose-50 border border-rose-200 rounded-lg p-3"
                >
                    <p class="text-[12.5px] font-medium text-rose-700 mb-1.5">
                        ⚠ {{ resultatGeneration.conflits.length }} famille(s) en
                        conflit étudiant/hôtel, à corriger avant génération :
                    </p>
                    <ul
                        class="text-[12.5px] text-rose-700 space-y-0.5 list-disc list-inside"
                    >
                        <li
                            v-for="conflit in resultatGeneration.conflits"
                            :key="conflit.id"
                        >
                            {{ conflit.nom }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>
