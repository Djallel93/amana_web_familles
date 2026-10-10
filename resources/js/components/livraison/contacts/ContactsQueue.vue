<!-- resources/js/components/livraison/contacts/ContactsQueue.vue -->
<!--
    File de suivi des contacts — reconstruit en Vue le 03/09/2026, révisé
    en profondeur le 05/09/2026 (voir le prompt de cette date §2 et
    resources/views/livraison/contacts.blade.php) :
      - filtre étendu au panneau partagé FamilleFilterPanel (§2.6), même
        filtres qu'eligibles()/Dossier Familles ;
      - "tout sélectionner" couvre désormais TOUT le filtré (toutes pages,
        via ids_only), pas seulement la page affichée (§2.6.2) ;
      - per_page configurable (§2.7) ;
      - créneaux regroupés matin/après-midi avec case tout/rien par
        groupe + globale (§2.8) ;
      - bouton "Modifier le dossier" ouvre DetailPanel.vue (§2.3) — mêmes
        règles de statut/synchronisation que Dossier Familles, sans rien
        dupliquer ici ;
      - téléphone bis affiché, layout contact retravaillé (§2.5) ;
      - clustering déplacé ici depuis CampagneDetail.vue (§1.5), gated côté
        serveur ET côté Vue sur "plus aucune famille à a_contacter pour la
        journée choisie" — RE-déplacé sur CampagneDetail.vue le 09/09/2026
        (prompt de cette date §2.2), voir ce fichier pour le bouton/gate
        désormais.

    Section E4 du refactor (16/09/2026, sixième chunk du domaine
    livraison) : ce composant n'est plus un îlot monté par app.ts sur
    #vue-livraison-contacts-queue, mais un enfant normal de
    resources/js/pages/Livraison/Contacts.vue. Les data-* lues jusqu'ici
    sur le point de montage sont devenues des props ; DetailPanel.vue est
    monté à côté dans cette même page (plus via
    familles/partials/vue-famille-detail.blade.php) — window.openFamilleDetail
    reste la façon dont ce composant l'ouvre (voir plus bas), inchangée :
    Vue monte les enfants avant le onMounted() du parent, donc cette
    fonction globale est déjà assignée quel que soit l'ordre des deux
    composants dans le template de la page.

    Aucun repli dataset conservé : cet écran est le seul consommateur de
    ce composant (vérifié par grep avant conversion), il n'y a pas de
    page Blade non migrée à faire coexister.
-->
<script setup lang="ts">
import { ref, reactive, computed, onMounted } from "vue";
import { useConfirm, usePrompt, useToast } from "@amana/shared-ui";
import { apiDelete, apiGet, apiPost, buildQuery } from "../shared/api";
import PaginationControls from "../shared/PaginationControls.vue";
import PersonSelect from "../shared/PersonSelect.vue";
import FamilleFilterPanel from "../shared/FamilleFilterPanel.vue";
import { useFormulaireCreneaux } from "../shared/useFormulaireCreneaux";
import {
    CRENEAUX_MATIN,
    CRENEAUX_APRES_MIDI,
    CRENEAU_LIBELLES,
    normalizePaginated,
    type Campagne,
    type CampagneJournee,
    type FamilleFiltres,
    type Livraison,
    type Organisation,
    type Paginated,
    type PersonneResume,
    type Quartier,
    type RawLaravelPaginator,
    type Secteur,
    type StatutContactPostable,
    type Ville,
} from "../shared/types";

declare global {
    interface Window {
        openFamilleDetail?: (id: number) => void;
    }
}

const toast = useToast();
const confirmDialog = useConfirm();
const promptDialog = usePrompt();

const props = defineProps<{
    campagnes: Campagne[];
    villes: Ville[];
    secteurs: Secteur[];
    quartiers: Quartier[];
    organisations: Organisation[];
    queueUrl: string;
    statistiquesUrl: string;
    assignerUrlTemplate: string;
    assignerLotUrl: string;
    contacterManuelUrlTemplate: string;
    // seDeplaceUrlTemplate (25/09/2026, prompt de cette date) : première UI
    // pour ContactTrackingController::mettreAJourSeDeplace() — corrige
    // se_deplace après coup, indépendamment du reste du contact (voir
    // basculerSeDeplace() plus bas).
    seDeplaceUrlTemplate: string;
    // priseEnChargeUrlTemplate (06/10/2026) : « Prendre en charge » — un
    // chauffeur s'engage à livrer cette famille (livraison imposée), voir
    // ContactTrackingController::prendreEnCharge().
    priseEnChargeUrlTemplate: string;
    // retirerUrlTemplate / reinitialiserUrlTemplate (09/10/2026) : retirer une famille
    // ajoutée par erreur (DELETE) et remettre à « à contacter » une famille confirmée
    // par erreur — voir ContactTrackingController::retirer()/reinitialiser().
    retirerUrlTemplate: string;
    reinitialiserUrlTemplate: string;
}>();

const campagnes = ref<Campagne[]>(props.campagnes);
const villes = ref<Ville[]>(props.villes);
const secteurs = ref<Secteur[]>(props.secteurs);
const quartiers = ref<Quartier[]>(props.quartiers);
const organisations = ref<Organisation[]>(props.organisations);
const queueUrl = props.queueUrl;
const statistiquesUrl = props.statistiquesUrl;
const assignerUrlTemplate = props.assignerUrlTemplate;
const assignerLotUrl = props.assignerLotUrl;
const contacterManuelUrlTemplate = props.contacterManuelUrlTemplate;
const seDeplaceUrlTemplate = props.seDeplaceUrlTemplate;
const priseEnChargeUrlTemplate = props.priseEnChargeUrlTemplate;
const retirerUrlTemplate = props.retirerUrlTemplate;
const reinitialiserUrlTemplate = props.reinitialiserUrlTemplate;

const LIBELLES_STATUT_CONTACT: Record<StatutContactPostable, string> = {
    injoignable: "Injoignable",
    confirme: "Confirmé",
    rejetee: "Rejetée",
    archive: "Archivé",
};

function urlAssigner(id: number): string {
    return assignerUrlTemplate.replace("__ID__", String(id));
}
function urlContacterManuel(id: number): string {
    return contacterManuelUrlTemplate.replace("__ID__", String(id));
}
function urlSeDeplace(id: number): string {
    return seDeplaceUrlTemplate.replace("__ID__", String(id));
}
function urlPriseEnCharge(id: number): string {
    return priseEnChargeUrlTemplate.replace("__ID__", String(id));
}
function urlRetirer(id: number): string {
    return retirerUrlTemplate.replace("__ID__", String(id));
}
function urlReinitialiser(id: number): string {
    return reinitialiserUrlTemplate.replace("__ID__", String(id));
}

function formatDateFr(iso: string): string {
    const [annee, mois, jour] = iso.split("T")[0].split("-");
    return `${jour}/${mois}/${annee}`;
}

// ── Lignes repliables (01/10/2026) ───────────────────────────────────────
// Repliées par défaut ; l'état est local à l'écran (pas de persistance).
// Volontairement NON réinitialisé par chargerFile() : assigner une personne
// recharge la file, et la ligne en cours d'édition ne doit pas se replier
// sous les doigts de l'utilisateur.
const ouvertes = reactive(new Set<number>());

function ligneOuverte(id: number): boolean {
    return ouvertes.has(id);
}
function basculerLigne(id: number) {
    if (ouvertes.has(id)) ouvertes.delete(id);
    else ouvertes.add(id);
}

// Libellés/couleurs d'affichage du statut de contact — une couleur par
// statut (a_contacter inclus : il n'avait pas de libellé et s'affichait
// en clair, « a_contacter »).
const LIBELLES_STATUT_AFFICHE: Record<string, string> = {
    a_contacter: "À contacter",
    ...LIBELLES_STATUT_CONTACT,
};
const CLASSES_STATUT_CONTACT: Record<string, string> = {
    a_contacter: "bg-stone-100 text-ink-muted",
    injoignable: "bg-amber-100 text-amber-700",
    confirme: "bg-emerald-100 text-emerald-700",
    archive: "bg-gray-200 text-gray-600",
    rejetee: "bg-rose-100 text-rose-700",
};

// ── Filtre + file ────────────────────────────────────────────────────────
const paramsUrl = new URLSearchParams(window.location.search);
const filtreCampagne = ref(paramsUrl.get("id_campagne") ?? "");
const filtresFamille = ref<FamilleFiltres>({});
const parPage = ref(50);

const campagneSelectionnee = computed(
    () => campagnes.value.find((c) => String(c.id) === String(filtreCampagne.value)) ?? null,
);
const journeesCampagne = computed<CampagneJournee[]>(() => campagneSelectionnee.value?.journees ?? []);
const idJourneeSelectionnee = ref<number | "">("");

const file = ref<Livraison[]>([]);
const meta = ref<Paginated<Livraison>["meta"] | null>(null);
const chargement = ref(true);
const erreur = ref(false);

const toutesOuvertes = computed(() => file.value.length > 0 && file.value.every((l) => ouvertes.has(l.id)));

function toutDeplier() {
    if (toutesOuvertes.value) {
        ouvertes.clear();
    } else {
        file.value.forEach((l) => ouvertes.add(l.id));
    }
}

function queryFiltres(page: number) {
    return buildQuery({
        page,
        per_page: parPage.value,
        id_campagne: filtreCampagne.value,
        id_ville: filtresFamille.value.id_ville,
        id_secteur: filtresFamille.value.id_secteur,
        id_quartier: filtresFamille.value.id_quartier,
        criticite: filtresFamille.value.criticite,
        se_deplace: filtresFamille.value.se_deplace || undefined,
        est_hotel: filtresFamille.value.est_hotel || undefined,
        etudiant: filtresFamille.value.etudiant || undefined,
        zakat_el_fitr: filtresFamille.value.zakat_el_fitr || undefined,
        sadaqa: filtresFamille.value.sadaqa || undefined,
        id_organisation_origine: filtresFamille.value.id_organisation_origine,
        id_organisation_rattachee: filtresFamille.value.id_organisation_rattachee,
        recherche: filtresFamille.value.recherche,
        id_personne_assignee: filtresFamille.value.id_personne_assignee,
        non_assigne: filtresFamille.value.non_assigne || undefined,
    });
}

async function chargerFile(page = 1) {
    chargement.value = true;
    erreur.value = false;
    selection.clear();

    const resultat = await apiGet<RawLaravelPaginator<Livraison>>(queueUrl + queryFiltres(page));
    chargement.value = false;

    if (!resultat.ok) {
        erreur.value = true;
        return;
    }

    const paginé = normalizePaginated(resultat.data);
    file.value = paginé.data;
    meta.value = paginé.meta;

    // Rafraîchies avec la même portée (mêmes filtres) à chaque rechargement
    // de la liste, plutôt que sur ses propres déclencheurs séparés — reste
    // ainsi toujours cohérente avec ce qui est affiché juste en dessous
    // sans avoir à traquer chaque appelant de chargerFile() un par un.
    chargerStatistiques();
}

// ── Cartes statistiques (08/09/2026, prompt de cette date §3.2) ─────────
const stats = ref<{ total: number; a_contacter: number; confirme: number; injoignable: number } | null>(null);

async function chargerStatistiques() {
    const resultat = await apiGet<{ total: number; a_contacter: number; confirme: number; injoignable: number }>(
        statistiquesUrl + queryFiltres(1),
    );
    stats.value = resultat.ok ? resultat.data : null;
}

// ── Assignation ──────────────────────────────────────────────────────────
const assignationEnCours = reactive<Record<number, boolean>>({});

async function assigner(livraison: Livraison, personne: PersonneResume | null) {
    if (!personne) return;
    assignationEnCours[livraison.id] = true;

    const resultat = await apiPost<{ success: boolean }>(urlAssigner(livraison.id), {
        id_personne_assignee: personne.id,
    });

    assignationEnCours[livraison.id] = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    livraison.id_personne_assignee = personne.id;
    livraison.personne_assignee = personne;
    toast.success(`Assigné à ${personne.prenom} ${personne.nom}.`);
}

// ── Sélection + assignation en lot (05/09/2026 : couvre tout le filtré,
//    pas seulement la page affichée — prompt §2.6.2) ─────────────────────
const selection = reactive<Set<number>>(new Set());
const assignationLotEnCours = ref(false);
const chargementSelectionTout = ref(false);

function toggleSelection(id: number) {
    if (selection.has(id)) selection.delete(id);
    else selection.add(id);
}

async function toutSelectionnerFiltre() {
    const pageActuelleIds = file.value.map((l) => l.id);
    const dejaTout = pageActuelleIds.length > 0 && pageActuelleIds.every((id) => selection.has(id));

    if (dejaTout) {
        pageActuelleIds.forEach((id) => selection.delete(id));
        return;
    }

    chargementSelectionTout.value = true;
    const resultat = await apiGet<{ ids: number[] }>(queueUrl + queryFiltres(1) + "&ids_only=1");
    chargementSelectionTout.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    resultat.data.ids.forEach((id) => selection.add(id));
}

async function assignerLot(personne: PersonneResume | null) {
    if (!personne || selection.size === 0) return;
    assignationLotEnCours.value = true;

    const resultat = await apiPost<{ success: boolean; assignees: number }>(assignerLotUrl, {
        id_personne_assignee: personne.id,
        ids_livraison: [...selection],
    });

    assignationLotEnCours.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    toast.success(`${resultat.data.assignees} livraison(s) assignée(s) à ${personne.prenom} ${personne.nom}.`);
    selection.clear();
    chargerFile(meta.value?.current_page ?? 1);
}

// ── Saisie téléphonique manuelle ────────────────────────────────────────
/**
 * Formulaire de CONFIRMATION uniquement désormais (05/09/2026, prompt
 * §2.2 : "Delete Saisie Telephonique button since there is now Modifier
 * le dossier" — la correction de champs famille se fait sur ce panneau,
 * pas ici). injoignable/rejetee/archive n'ont plus besoin d'un
 * formulaire du tout (voir marquerStatutSimple()) — un seul champ requis
 * nulle part pour ces 3-là (voir la validation serveur, contacterManuel()).
 *
 * Réduit à `creneaux` uniquement (07/09/2026, prompt §2.5) —
 * adresse/code postal/ville/adultes/enfants retirés : ces informations
 * vivent déjà dans le dossier famille, éditable juste au-dessus via
 * "✏️ Modifier le dossier" — les redemander ici dupliquait une saisie
 * pour rien et risquait de diverger de la même source de vérité que ce
 * panneau. Voir ContactTrackingController::contacterManuel(), qui
 * n'exige plus ces champs pour ce chemin.
 */
/**
 * Formulaire de CONFIRMATION uniquement désormais (05/09/2026, prompt
 * §2.2 : "Delete Saisie Telephonique button since there is now Modifier
 * le dossier" — la correction de champs famille se fait sur ce panneau,
 * pas ici). injoignable/rejetee/archive n'ont plus besoin d'un
 * formulaire du tout (voir marquerStatutSimple()) — un seul champ requis
 * nulle part pour ces 3-là (voir la validation serveur, contacterManuel()).
 *
 * Réduit à `creneaux` uniquement (07/09/2026, prompt §2.5) —
 * adresse/code postal/ville/adultes/enfants retirés : ces informations
 * vivent déjà dans le dossier famille, éditable juste au-dessus via
 * "✏️ Modifier le dossier" — les redemander ici dupliquait une saisie
 * pour rien et risquait de diverger de la même source de vérité que ce
 * panneau. Voir ContactTrackingController::contacterManuel(), qui
 * n'exige plus ces champs pour ce chemin.
 *
 * Ouverture/créneaux via useFormulaireCreneaux() depuis le 10/09/2026
 * (Section A4 du refactor) — partagé avec BenevoleDisponibiliteQueue.vue.
 * L'état de requête (envoiEnCours/erreurs) reste local : forme propre à
 * cet écran, pas au patron d'expansion lui-même.
 */
const { formulaire, basculerOuverture, toggleCreneau, groupeToutCoche, toggleGroupe, toggleTout } =
    useFormulaireCreneaux();

interface EtatEnvoiConfirmation {
    envoiEnCours: boolean;
    erreurs: Record<string, string[]>;
}

const envoisConfirmation = reactive<Record<number, EtatEnvoiConfirmation>>({});

function etatEnvoi(id: number): EtatEnvoiConfirmation {
    if (!envoisConfirmation[id]) {
        envoisConfirmation[id] = { envoiEnCours: false, erreurs: {} };
    }
    return envoisConfirmation[id];
}

/**
 * injoignable/rejetee/archive — un clic, aucun champ requis côté
 * validation serveur (voir ContactTrackingController::contacterManuel()),
 * donc aucune raison de passer par un formulaire pour ces trois-là
 * (05/09/2026, prompt §2.2/§2.3).
 */
const statutSimpleEnCours = reactive<Record<number, boolean>>({});

/**
 * se_deplace (25/09/2026, prompt de cette date) : seul moment où la
 * famille peut elle-même indiquer, via l'appel téléphonique staff, si
 * elle se déplacera au QG pour CETTE campagne — décision produit actée :
 * pas ajouté au formulaire public de confirmation. Local à cet écran comme
 * `formulaire`/`etatEnvoi` ci-dessus — pas dans useFormulaireCreneaux(),
 * partagé avec BenevoleDisponibiliteQueue.vue qui n'a rien à voir avec
 * se_deplace.
 *
 * AUCUNE valeur par défaut depuis le 06/10/2026 (avant : « Non » présélectionné,
 * donc jamais réellement choisi) : tant que le gestionnaire n'a pas répondu
 * Oui ou Non, « Enregistrer la confirmation » reste grisé — voir
 * confirmationIncomplete().
 */
const seDeplaceFormulaire = reactive<Record<number, boolean | undefined>>({});

function seDeplaceValeur(id: number): boolean | undefined {
    return seDeplaceFormulaire[id];
}

/**
 * Ce qu'il manque pour enregistrer la confirmation (06/10/2026) : au moins un
 * créneau ET une réponse Oui/Non à « se déplacera-t-elle au QG ? ». Le serveur
 * applique les mêmes règles (ContactTrackingController::contacterManuel()).
 */
function confirmationIncomplete(id: number): string[] {
    const manque: string[] = [];
    if (formulaire(id).creneaux.length === 0) manque.push("au moins un créneau");
    if (seDeplaceValeur(id) === undefined) manque.push("« se déplace au QG » : oui ou non");
    return manque;
}

async function marquerStatutSimple(livraison: Livraison, statut: "injoignable" | "rejetee" | "archive") {
    // Rejeter/archiver exige un MOTIF (09/10/2026) : la popup ne valide rien tant que le motif
    // n'est pas saisi et confirmé — annuler (null) n'envoie aucune requête. Le serveur applique
    // la même règle (ContactTrackingController::contacterManuel()).
    let motif: string | undefined;
    if (statut === "rejetee" || statut === "archive") {
        const nom = `${livraison.famille.prenom} ${livraison.famille.nom}`;
        const saisi = await promptDialog.ask({
            title: statut === "archive" ? "Archiver cette famille" : "Rejeter cette famille",
            message: `${nom} (#${livraison.famille.id}) — indiquez le motif. Le statut n'est appliqué qu'une fois le motif confirmé.`,
            label: "Motif (obligatoire)",
            placeholder:
                statut === "archive" ? "Pourquoi archiver cette famille ?" : "Pourquoi rejeter cette famille ?",
            confirmLabel: statut === "archive" ? "Archiver" : "Rejeter",
            required: true,
            maxLength: 1000,
        });
        if (saisi === null) return;
        motif = saisi;
    }

    statutSimpleEnCours[livraison.id] = true;
    const resultat = await apiPost<{ success: boolean }>(urlContacterManuel(livraison.id), {
        statut_contact: statut,
        motif,
    });
    statutSimpleEnCours[livraison.id] = false;

    if (!resultat.ok) {
        toast.error(resultat.errors.motif?.[0] ?? resultat.message);
        return;
    }

    toast.success("Statut mis à jour.");
    chargerFile(meta.value?.current_page ?? 1);
}

/**
 * Retirer une famille ajoutée par erreur à la campagne (09/10/2026) — supprime sa
 * livraison. Le serveur refuse (message affiché tel quel) si la famille est livrée, dans
 * une tournée chargée/en cours ou si son conditionnement a commencé.
 */
const retraitEnCours = reactive<Record<number, boolean>>({});

async function retirerDeLaCampagne(livraison: Livraison) {
    const nom = `${livraison.famille.prenom} ${livraison.famille.nom}`;
    const confirme = await confirmDialog.ask({
        title: "Retirer de la campagne ?",
        message: `${nom} (#${livraison.famille.id}) sera retirée de cette campagne : sa livraison, ses créneaux et sa place dans une tournée sont supprimés. Vous pourrez la rajouter depuis la sélection des familles.`,
        confirmLabel: "Retirer",
        danger: true,
    });
    if (!confirme) return;

    retraitEnCours[livraison.id] = true;
    const resultat = await apiDelete<{ success: boolean }>(urlRetirer(livraison.id));
    retraitEnCours[livraison.id] = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    ouvertes.delete(livraison.id);
    toast.success("Famille retirée de la campagne.");
    chargerFile(meta.value?.current_page ?? 1);
}

/**
 * Famille confirmée par erreur (09/10/2026) : retour à « à contacter » — créneaux,
 * « se déplace », chauffeur imposé et place dans une tournée non chargée sont effacés.
 */
const reinitialisationEnCours = reactive<Record<number, boolean>>({});

async function reinitialiserConfirmation(livraison: Livraison) {
    const nom = `${livraison.famille.prenom} ${livraison.famille.nom}`;
    const confirme = await confirmDialog.ask({
        title: "Réinitialiser la confirmation ?",
        message: `${nom} (#${livraison.famille.id}) repasse à « À contacter » : ses créneaux, son choix « se déplace au QG », son chauffeur imposé et sa place dans une tournée seront effacés.`,
        confirmLabel: "Réinitialiser",
        danger: true,
    });
    if (!confirme) return;

    reinitialisationEnCours[livraison.id] = true;
    const resultat = await apiPost<{ success: boolean }>(urlReinitialiser(livraison.id));
    reinitialisationEnCours[livraison.id] = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    // Le formulaire de confirmation déjà rempli ne doit pas ressurgir tel quel.
    delete seDeplaceFormulaire[livraison.id];
    const f = formulaire(livraison.id);
    f.ouvert = false;
    f.creneaux = [];
    toast.success("Confirmation réinitialisée.");
    chargerFile(meta.value?.current_page ?? 1);
}

/** Un motif n'existe que pour un rejet ou un archivage (statuts absents du type StatutContact, lignes déjà en base). */
function motifApplicable(livraison: Livraison): boolean {
    return ["archive", "rejetee"].includes(livraison.statut_contact);
}

/** Le conditionnement entamé bloque « Retirer » et « Réinitialiser » (voir le serveur). */
function conditionnementEntame(livraison: Livraison): boolean {
    return livraison.statut_conditionnement === "en_cours" || livraison.statut_conditionnement === "prete";
}

/**
 * Correction a posteriori de se_deplace — indépendante du reste du
 * contact (voir ContactTrackingController::mettreAJourSeDeplace(), qui
 * recalcule aussi le planning retrait QG de la journée après coup).
 * Distincte de seDeplaceFormulaire (le choix initial fait par
 * enregistrerContact() à la première confirmation, voir plus bas) — cet
 * endpoint reste utilisable même après confirmation, sans rouvrir le
 * formulaire de créneaux.
 */
const seDeplaceEnCours = reactive<Record<number, boolean>>({});

async function basculerSeDeplace(livraison: Livraison) {
    seDeplaceEnCours[livraison.id] = true;
    const resultat = await apiPost<{ success: boolean; se_deplace: boolean }>(urlSeDeplace(livraison.id), {
        se_deplace: !livraison.se_deplace,
    });
    seDeplaceEnCours[livraison.id] = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    livraison.se_deplace = resultat.data.se_deplace;
    toast.success("Se déplace mis à jour.");
}

/**
 * « Prendre en charge » (06/10/2026) : un chauffeur s'engage à livrer cette
 * famille quand il veut (tournée imposée, sans créneau). `null` retire
 * l'imposition. Le serveur refuse si la famille est déjà dans une tournée
 * chargée/en cours, déjà livrée ou se déplace au QG — le message s'affiche tel quel.
 */
const priseEnChargeEnCours = reactive<Record<number, boolean>>({});

async function prendreEnCharge(livraison: Livraison, personne: PersonneResume | null) {
    priseEnChargeEnCours[livraison.id] = true;
    const resultat = await apiPost<{
        success: boolean;
        id_benevole_impose: number | null;
        benevole_impose: PersonneResume | null;
    }>(urlPriseEnCharge(livraison.id), { id_benevole: personne?.id ?? null });
    priseEnChargeEnCours[livraison.id] = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        // La sélection affichée revient à la valeur serveur.
        chargerFile(meta.value?.current_page ?? 1);
        return;
    }

    livraison.id_benevole_impose = resultat.data.id_benevole_impose;
    livraison.benevole_impose = resultat.data.benevole_impose;
    toast.success(personne ? "Livraison confiée à ce bénévole." : "Imposition retirée.");
}

async function enregistrerContact(livraison: Livraison) {
    const f = formulaire(livraison.id);
    const seDeplace = seDeplaceValeur(livraison.id);
    if (seDeplace === undefined || f.creneaux.length === 0) return;

    const e = etatEnvoi(livraison.id);
    e.envoiEnCours = true;
    e.erreurs = {};

    const resultat = await apiPost<{ success: boolean }>(urlContacterManuel(livraison.id), {
        statut_contact: "confirme",
        creneaux: f.creneaux,
        se_deplace: seDeplace,
    });
    e.envoiEnCours = false;

    if (!resultat.ok) {
        e.erreurs = resultat.errors;
        if (Object.keys(resultat.errors).length === 0) toast.error(resultat.message);
        return;
    }

    toast.success("Contact enregistré.");
    chargerFile(meta.value?.current_page ?? 1);
}

// ── Modifier le dossier famille (05/09/2026, prompt §2.3) ────────────────
// Ouvre DetailPanel.vue (même panneau que Dossier Familles, voir
// contacts.blade.php pour son montage) — mêmes règles de statut/
// synchronisation qu'ailleurs dans l'app, rien à dupliquer ici.
function modifierDossier(livraison: Livraison) {
    if (!window.openFamilleDetail) {
        toast.error("Le panneau d'édition n'a pas pu être chargé.");
        return;
    }
    window.openFamilleDetail(livraison.famille.id);
}

function surChangementCampagne() {
    idJourneeSelectionnee.value = journeesCampagne.value[0]?.id ?? "";
    chargerFile(1);
}
function surChangementJournee() {
    chargerFile(1);
}

onMounted(() => {
    if (campagneSelectionnee.value) idJourneeSelectionnee.value = journeesCampagne.value[0]?.id ?? "";
    chargerFile(1);
});
</script>

<template>
    <div>
        <div class="flex flex-col sm:flex-row sm:items-end gap-3 mb-4">
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Campagne</label>
                <select
                    v-model="filtreCampagne"
                    @change="surChangementCampagne"
                    class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.5rem]"
                >
                    <option value="">Toutes les campagnes</option>
                    <option v-for="c in campagnes" :key="c.id" :value="c.id">
                        {{ formatDateFr(c.date_livraison) }} — {{ c.type }}
                    </option>
                </select>
            </div>
            <div v-if="journeesCampagne.length > 1">
                <label class="block text-[12px] text-ink-muted mb-1">Journée</label>
                <select
                    v-model="idJourneeSelectionnee"
                    @change="surChangementJournee"
                    class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.5rem]"
                >
                    <option v-for="j in journeesCampagne" :key="j.id" :value="j.id">
                        {{ j.label ?? formatDateFr(j.date) }}
                    </option>
                </select>
            </div>
        </div>

        <!--
            Cartes statistiques (08/09/2026, prompt de cette date §3.2) —
            même portée de filtres que la liste juste en dessous (voir
            chargerStatistiques()). "contacte" volontairement absent : déjà
            retiré des statuts utilisables le 05/09/2026, plus affiché nulle
            part sur cet écran (voir aussi LIBELLES_STATUT_CONTACT).
        -->
        <div v-if="stats" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
            <div class="bg-surface border border-surface-border rounded-xl p-3">
                <p class="text-[11px] text-ink-muted uppercase tracking-wide">Familles</p>
                <p class="text-[20px] font-semibold text-ink">{{ stats.total }}</p>
            </div>
            <div class="bg-stone-50 border border-surface-border rounded-xl p-3">
                <p class="text-[11px] text-ink-muted uppercase tracking-wide">À contacter</p>
                <p class="text-[20px] font-semibold text-ink">{{ stats.a_contacter }}</p>
            </div>
            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3">
                <p class="text-[11px] text-emerald-700 uppercase tracking-wide">Confirmées</p>
                <p class="text-[20px] font-semibold text-emerald-700">{{ stats.confirme }}</p>
            </div>
            <div class="bg-rose-50 border border-rose-100 rounded-xl p-3">
                <p class="text-[11px] text-rose-700 uppercase tracking-wide">Injoignables</p>
                <p class="text-[20px] font-semibold text-rose-700">{{ stats.injoignable }}</p>
            </div>
        </div>

        <FamilleFilterPanel
            :villes="villes"
            :secteurs="secteurs"
            :quartiers="quartiers"
            :organisations="organisations"
            :model-value="filtresFamille"
            @update:model-value="filtresFamille = $event"
            @filtrer="chargerFile(1)"
            avec-se-deplace
            avec-assignation
        />

        <p v-if="chargement" class="text-[14px] text-ink-muted">Chargement…</p>
        <p v-else-if="erreur" class="text-[14px] text-rose-600">Impossible de charger la file de contact.</p>
        <p v-else-if="file.length === 0" class="text-[14px] text-ink-muted">Aucune livraison en attente de contact.</p>

        <div v-else class="space-y-3">
            <div
                class="flex flex-wrap items-center gap-3 bg-stone-50 border border-surface-border rounded-xl px-4 py-2.5"
            >
                <label class="flex items-center gap-2 text-[12.5px] text-ink-muted min-h-[2rem]">
                    <input
                        type="checkbox"
                        :disabled="chargementSelectionTout"
                        :checked="file.length > 0 && file.every((l) => selection.has(l.id))"
                        @change="toutSelectionnerFiltre"
                        class="w-4 h-4 accent-accent"
                    />
                    Tout sélectionner (le filtre entier — {{ selection.size }})
                </label>
                <button
                    type="button"
                    @click="toutDeplier"
                    class="min-h-[2rem] text-[12px] font-medium px-2.5 py-1 rounded-lg border border-surface-border text-ink-muted hover:bg-surface"
                >
                    {{ toutesOuvertes ? "Tout replier" : "Tout déplier" }}
                </button>
                <div v-if="selection.size > 0" class="max-w-xs">
                    <PersonSelect
                        role="gestionnaire"
                        placeholder="Assigner la sélection à…"
                        :model-value="null"
                        @update:model-value="assignerLot"
                    />
                </div>
                <!-- Bouton clustering retiré d'ici (09/09/2026, prompt §2.2) —
                     déplacé sur CampagneDetail.vue (livraison/campagnes/{id}). -->
            </div>

            <!--
                09/10/2026 : plus d'`overflow-hidden` sur la carte — il coupait la liste déroulante
                du sélecteur de chauffeur (PersonSelect) au bord de la ligne. Le corps déplié est
                organisé en blocs : Coordonnées / Suivi, puis « Livraison » (se déplace + chauffeur
                imposé, familles confirmées), puis Actions.
            -->
            <!--
                Ligne repliable (01/10/2026, prompt de cette date §2) : l'en-tête
                reste toujours visible (case, #id, nom, pastille assigné,
                pastille se_deplace, téléphone, statut en haut à droite) ; le
                détail (coordonnées, assignation, actions, formulaire de
                confirmation) vit dans le corps déplié. Repliée par défaut —
                voir ouvertes / basculerLigne() / toutDeplier().
            -->
            <div
                v-for="livraison in file"
                :key="livraison.id"
                class="bg-surface border border-surface-border rounded-xl shadow-sm"
                :class="selection.has(livraison.id) ? 'ring-2 ring-accent/40' : ''"
            >
                <div
                    class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-surface-2/60 transition-colors rounded-t-xl"
                    role="button"
                    tabindex="0"
                    :aria-expanded="ligneOuverte(livraison.id)"
                    @click="basculerLigne(livraison.id)"
                    @keydown.enter.self.prevent="basculerLigne(livraison.id)"
                    @keydown.space.self.prevent="basculerLigne(livraison.id)"
                >
                    <input
                        type="checkbox"
                        :checked="selection.has(livraison.id)"
                        @click.stop
                        @change="toggleSelection(livraison.id)"
                        class="w-4 h-4 accent-accent shrink-0"
                        aria-label="Sélectionner"
                    />
                    <span
                        class="text-ink-muted text-[12px] transition-transform duration-200 shrink-0"
                        :class="ligneOuverte(livraison.id) ? 'rotate-90' : ''"
                        aria-hidden="true"
                        >▶</span
                    >
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="text-ink-muted text-[12.5px] font-medium shrink-0"
                                >#{{ livraison.famille.id }}</span
                            >
                            <span class="text-[15px] font-semibold text-ink truncate"
                                >{{ livraison.famille.prenom }} {{ livraison.famille.nom }}</span
                            >
                            <span
                                v-if="livraison.personne_assignee"
                                class="inline-flex items-center gap-1 text-[11.5px] font-medium px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200"
                            >
                                👤 {{ livraison.personne_assignee.prenom }} {{ livraison.personne_assignee.nom }}
                            </span>
                            <span
                                v-else
                                class="inline-flex items-center gap-1 text-[11.5px] font-medium px-2 py-0.5 rounded-full bg-stone-100 text-ink-muted border border-surface-border"
                            >
                                👤 Non assigné
                            </span>
                            <span
                                v-if="livraison.se_deplace"
                                class="inline-flex items-center gap-1 text-[11.5px] font-medium px-2 py-0.5 rounded-full bg-amber-100 text-amber-700"
                            >
                                🚶 Se déplace
                            </span>
                        </div>
                        <p v-if="!ligneOuverte(livraison.id)" class="text-[12.5px] text-ink-muted mt-0.5 truncate">
                            📞 {{ livraison.famille.telephone || "—" }}
                            <span v-if="livraison.famille.telephone_bis"> · {{ livraison.famille.telephone_bis }}</span>
                        </p>
                    </div>
                    <!--
                        Statut en haut à droite + agrandi (09/09/2026), une
                        couleur par statut (01/10/2026 : seuls a_contacter et
                        confirmé en avaient une, les autres retombaient sur
                        aucune classe).
                    -->
                    <span
                        class="text-[13px] font-medium px-2.5 py-1 rounded-full shrink-0"
                        :class="CLASSES_STATUT_CONTACT[livraison.statut_contact] ?? 'bg-stone-100 text-ink-muted'"
                    >
                        {{ LIBELLES_STATUT_AFFICHE[livraison.statut_contact] ?? livraison.statut_contact }}
                    </span>
                </div>

                <div
                    v-if="ligneOuverte(livraison.id)"
                    class="border-t border-surface-border px-4 py-4 space-y-4 bg-surface"
                >
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <!-- Coordonnées — téléphone bis affiché (05/09/2026, prompt §2.5). -->
                        <div class="bg-stone-50 rounded-lg px-3 py-2.5 space-y-1">
                            <p class="text-[10px] font-bold text-ink-muted uppercase tracking-wide">Coordonnées</p>
                            <p class="text-[13px] text-ink">📞 {{ livraison.famille.telephone || "—" }}</p>
                            <p v-if="livraison.famille.telephone_bis" class="text-[13px] text-ink">
                                📞 {{ livraison.famille.telephone_bis }} <span class="text-ink-muted">(bis)</span>
                            </p>
                            <p class="text-[13px] text-ink break-all">
                                ✉️ {{ livraison.famille.email || "pas d'email" }}
                            </p>
                        </div>
                        <!-- Assignation + édition du dossier -->
                        <div class="bg-stone-50 rounded-lg px-3 py-2.5 space-y-2">
                            <p class="text-[10px] font-bold text-ink-muted uppercase tracking-wide">Suivi</p>
                            <PersonSelect
                                role="gestionnaire"
                                placeholder="Assigner à…"
                                :model-value="livraison.personne_assignee"
                                @update:model-value="(p) => assigner(livraison, p)"
                            />
                            <button
                                type="button"
                                @click="modifierDossier(livraison)"
                                class="min-h-[2.25rem] text-[12.5px] px-3 py-1.5 rounded-lg bg-indigo-600 text-white hover:opacity-90"
                            >
                                ✏️ Modifier le dossier
                            </button>
                        </div>
                    </div>

                    <!-- Motif saisi lors d'un archivage / rejet (09/10/2026). -->
                    <p
                        v-if="livraison.motif_statut_contact && motifApplicable(livraison)"
                        class="text-[12.5px] text-ink bg-stone-50 rounded-lg px-3 py-2"
                    >
                        <span class="text-[10px] font-bold text-ink-muted uppercase tracking-wide mr-1.5">Motif</span>
                        {{ livraison.motif_statut_contact }}
                    </p>

                    <!-- Bloc « Livraison » : correction a posteriori de se_deplace (25/09/2026,
                         mettreAJourSeDeplace()) + « Prendre en charge » (06/10/2026 : un chauffeur
                         s'engage à livrer cette famille, livraison imposée). Seulement une fois
                         confirmée ; pas de prise en charge pour une famille qui se déplace au QG
                         (le serveur refuse aussi). Le sélecteur ne propose que les chauffeurs
                         CONFIRMÉS pour la journée de la famille (09/10/2026). -->
                    <div
                        v-if="livraison.statut_contact === 'confirme'"
                        class="bg-stone-50 rounded-lg px-3 py-2.5 space-y-3"
                    >
                        <p class="text-[10px] font-bold text-ink-muted uppercase tracking-wide">Livraison</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 items-start">
                            <div>
                                <label class="block text-[11px] text-ink-muted mb-1">Retrait au QG</label>
                                <button
                                    type="button"
                                    :disabled="seDeplaceEnCours[livraison.id]"
                                    @click="basculerSeDeplace(livraison)"
                                    class="inline-flex items-center gap-1.5 text-[12.5px] font-medium px-2.5 py-1.5 rounded-full disabled:opacity-60"
                                    :class="
                                        livraison.se_deplace
                                            ? 'bg-amber-100 text-amber-700'
                                            : 'bg-white text-ink-muted border border-surface-border'
                                    "
                                >
                                    🚶 Se déplace : {{ livraison.se_deplace ? "Oui" : "Non" }} · changer
                                </button>
                            </div>
                            <div>
                                <label class="block text-[11px] text-ink-muted mb-1"
                                    >Prise en charge par un chauffeur</label
                                >
                                <div
                                    :class="
                                        priseEnChargeEnCours[livraison.id] || livraison.se_deplace
                                            ? 'pointer-events-none opacity-60'
                                            : ''
                                    "
                                >
                                    <PersonSelect
                                        role="benevole"
                                        avec-vehicule
                                        :id-campagne-journee="livraison.id_campagne_journee ?? null"
                                        :id-campagne="livraison.id_campagne"
                                        placeholder="Aucun — choisir un chauffeur…"
                                        :model-value="livraison.benevole_impose ?? null"
                                        @update:model-value="(p) => prendreEnCharge(livraison, p)"
                                    />
                                </div>
                                <p v-if="livraison.se_deplace" class="text-[11px] text-ink-muted mt-1">
                                    Cette famille vient au QG : pas de prise en charge possible.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Actions de statut : injoignable/rejetée/archivée — un clic, avec un motif
                         obligatoire pour les deux dernières (marquerStatutSimple()). Seule la
                         confirmation ouvre un formulaire. « Réinitialiser » (famille confirmée par
                         erreur) et « Retirer » (famille ajoutée par erreur) sont à part, à droite. -->
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            @click="basculerOuverture(livraison.id)"
                            class="min-h-[2.25rem] text-[12.5px] px-3 py-1.5 rounded-lg bg-emerald-600 text-white hover:opacity-90"
                        >
                            ✅ Confirmer
                        </button>
                        <button
                            type="button"
                            :disabled="statutSimpleEnCours[livraison.id]"
                            @click="marquerStatutSimple(livraison, 'injoignable')"
                            class="min-h-[2.25rem] text-[12.5px] px-3 py-1.5 rounded-lg bg-amber-600 text-white hover:opacity-90 disabled:opacity-60"
                        >
                            Injoignable
                        </button>
                        <button
                            type="button"
                            :disabled="statutSimpleEnCours[livraison.id]"
                            @click="marquerStatutSimple(livraison, 'rejetee')"
                            class="min-h-[2.25rem] text-[12.5px] px-3 py-1.5 rounded-lg bg-rose-600 text-white hover:opacity-90 disabled:opacity-60"
                        >
                            Rejetée
                        </button>
                        <button
                            type="button"
                            :disabled="statutSimpleEnCours[livraison.id]"
                            @click="marquerStatutSimple(livraison, 'archive')"
                            class="min-h-[2.25rem] text-[12.5px] px-3 py-1.5 rounded-lg bg-stone-500 text-white hover:opacity-90 disabled:opacity-60"
                        >
                            Archivée
                        </button>

                        <span class="hidden sm:block flex-1" aria-hidden="true"></span>

                        <button
                            v-if="livraison.statut_contact === 'confirme'"
                            type="button"
                            :disabled="reinitialisationEnCours[livraison.id] || conditionnementEntame(livraison)"
                            :title="
                                conditionnementEntame(livraison)
                                    ? 'Le conditionnement a commencé : annulez-le d\'abord dans Packaging.'
                                    : 'Remettre cette famille à « À contacter »'
                            "
                            @click="reinitialiserConfirmation(livraison)"
                            class="min-h-[2.25rem] text-[12.5px] px-3 py-1.5 rounded-lg border border-amber-300 text-amber-700 hover:bg-amber-50 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            ↺ Réinitialiser
                        </button>
                        <button
                            type="button"
                            :disabled="retraitEnCours[livraison.id] || conditionnementEntame(livraison)"
                            :title="
                                conditionnementEntame(livraison)
                                    ? 'Le conditionnement a commencé : annulez-le d\'abord dans Packaging.'
                                    : 'Retirer cette famille de la campagne'
                            "
                            @click="retirerDeLaCampagne(livraison)"
                            class="min-h-[2.25rem] text-[12.5px] px-3 py-1.5 rounded-lg border border-rose-300 text-rose-700 hover:bg-rose-50 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            🗑 Retirer de la campagne
                        </button>
                    </div>

                    <div v-if="formulaire(livraison.id).ouvert" class="mt-3 space-y-3 bg-stone-50 rounded-lg p-3">
                        <!-- Regroupement matin/après-midi (05/09/2026, prompt §2.8).
                             Adresse/code postal/ville/adultes/enfants retirés
                             d'ici (07/09/2026, prompt §2.5) — voir le
                             commentaire sur FormeConfirmation plus haut :
                             édition désormais uniquement via "✏️ Modifier le
                             dossier". -->
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <!--
                                    Déplacé à gauche + rendu plus visible
                                    (08/09/2026, prompt de cette date §3.3) —
                                    était un simple lien texte à droite du
                                    label, difficile à repérer.
                                -->
                                <button
                                    type="button"
                                    @click="toggleTout(livraison.id)"
                                    class="min-h-[1.875rem] text-[11.5px] font-medium px-2.5 py-1 rounded-lg border border-accent text-accent hover:bg-accent/5"
                                >
                                    Tout / Rien
                                </button>
                                <label class="text-[11px] text-ink-muted">Créneaux</label>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div class="border border-ink-faint rounded-lg p-2">
                                    <label class="flex items-center gap-1.5 text-[11.5px] font-medium text-ink mb-1.5">
                                        <input
                                            type="checkbox"
                                            :checked="groupeToutCoche(livraison.id, CRENEAUX_MATIN)"
                                            @change="toggleGroupe(livraison.id, CRENEAUX_MATIN)"
                                            class="w-3.5 h-3.5 accent-accent"
                                        />
                                        Matin
                                    </label>
                                    <div class="flex flex-wrap gap-1.5">
                                        <label
                                            v-for="creneau in CRENEAUX_MATIN"
                                            :key="creneau"
                                            class="flex items-center gap-1.5 px-2.5 py-2 border border-ink-faint rounded-md text-[11.5px] text-ink-muted cursor-pointer select-none transition-colors has-[:checked]:border-accent has-[:checked]:bg-accent/5 has-[:checked]:text-ink has-[:checked]:font-semibold"
                                        >
                                            <input
                                                type="checkbox"
                                                :checked="formulaire(livraison.id).creneaux.includes(creneau)"
                                                @change="toggleCreneau(livraison.id, creneau)"
                                                class="w-3.5 h-3.5 accent-accent"
                                            />
                                            {{ CRENEAU_LIBELLES[creneau] }}
                                        </label>
                                    </div>
                                </div>
                                <div class="border border-ink-faint rounded-lg p-2">
                                    <label class="flex items-center gap-1.5 text-[11.5px] font-medium text-ink mb-1.5">
                                        <input
                                            type="checkbox"
                                            :checked="groupeToutCoche(livraison.id, CRENEAUX_APRES_MIDI)"
                                            @change="toggleGroupe(livraison.id, CRENEAUX_APRES_MIDI)"
                                            class="w-3.5 h-3.5 accent-accent"
                                        />
                                        Après-midi
                                    </label>
                                    <div class="flex flex-wrap gap-1.5">
                                        <label
                                            v-for="creneau in CRENEAUX_APRES_MIDI"
                                            :key="creneau"
                                            class="flex items-center gap-1.5 px-2.5 py-2 border border-ink-faint rounded-md text-[11.5px] text-ink-muted cursor-pointer select-none transition-colors has-[:checked]:border-accent has-[:checked]:bg-accent/5 has-[:checked]:text-ink has-[:checked]:font-semibold"
                                        >
                                            <input
                                                type="checkbox"
                                                :checked="formulaire(livraison.id).creneaux.includes(creneau)"
                                                @change="toggleCreneau(livraison.id, creneau)"
                                                class="w-3.5 h-3.5 accent-accent"
                                            />
                                            {{ CRENEAU_LIBELLES[creneau] }}
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <p
                                v-for="e in etatEnvoi(livraison.id).erreurs.creneaux ?? []"
                                :key="e"
                                class="text-[11px] text-rose-600 mt-1"
                            >
                                {{ e }}
                            </p>
                        </div>

                        <!-- se_deplace (25/09/2026, prompt de cette date) :
                             posée UNIQUEMENT ici (saisie téléphonique staff),
                             pas sur le formulaire public de confirmation —
                             décision produit actée avec l'utilisateur. -->
                        <div>
                            <label class="block text-[11px] text-ink-muted mb-1.5"
                                >La famille se déplacera-t-elle au QG pour récupérer son colis ?</label
                            >
                            <div class="flex gap-2 max-w-xs">
                                <label
                                    class="flex-1 flex items-center justify-center gap-1.5 px-2.5 py-1.5 border rounded-md text-[12.5px] cursor-pointer select-none"
                                    :class="
                                        seDeplaceValeur(livraison.id) === true
                                            ? 'border-accent bg-accent/5 text-ink font-semibold'
                                            : 'border-ink-faint text-ink-muted'
                                    "
                                >
                                    <input
                                        type="radio"
                                        :value="true"
                                        v-model="seDeplaceFormulaire[livraison.id]"
                                        class="w-3.5 h-3.5 accent-accent"
                                    />
                                    Oui
                                </label>
                                <label
                                    class="flex-1 flex items-center justify-center gap-1.5 px-2.5 py-1.5 border rounded-md text-[12.5px] cursor-pointer select-none"
                                    :class="
                                        seDeplaceValeur(livraison.id) === false
                                            ? 'border-accent bg-accent/5 text-ink font-semibold'
                                            : 'border-ink-faint text-ink-muted'
                                    "
                                >
                                    <input
                                        type="radio"
                                        :value="false"
                                        v-model="seDeplaceFormulaire[livraison.id]"
                                        class="w-3.5 h-3.5 accent-accent"
                                    />
                                    Non
                                </label>
                            </div>
                            <p
                                v-for="e in etatEnvoi(livraison.id).erreurs.se_deplace ?? []"
                                :key="e"
                                class="text-[11px] text-rose-600 mt-1"
                            >
                                {{ e }}
                            </p>
                        </div>

                        <!-- Interdit tant que créneaux ET se_deplace ne sont pas
                             renseignés (06/10/2026) — le serveur applique la même règle. -->
                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                :disabled="
                                    etatEnvoi(livraison.id).envoiEnCours ||
                                    confirmationIncomplete(livraison.id).length > 0
                                "
                                @click="enregistrerContact(livraison)"
                                class="min-h-[2.25rem] text-[12.5px] px-3 py-1.5 rounded-lg bg-accent text-white disabled:opacity-60 disabled:cursor-not-allowed"
                            >
                                {{
                                    etatEnvoi(livraison.id).envoiEnCours
                                        ? "Enregistrement…"
                                        : "Enregistrer la confirmation"
                                }}
                            </button>
                            <span
                                v-if="confirmationIncomplete(livraison.id).length > 0"
                                class="text-[11.5px] text-ink-muted"
                            >
                                À renseigner : {{ confirmationIncomplete(livraison.id).join(" · ") }}.
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="meta" class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <PaginationControls :meta="meta" @change="chargerFile" />
            <!-- Déplacé en bas (07/09/2026, prompt §2.2) : à côté de la
                 pagination qu'il gouverne, plutôt qu'au-dessus des
                 filtres campagne/journée où il n'avait pas vraiment sa
                 place. -->
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Par page</label>
                <select
                    v-model.number="parPage"
                    @change="chargerFile(1)"
                    class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.5rem]"
                >
                    <option :value="25">25</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select>
            </div>
        </div>
    </div>
</template>
