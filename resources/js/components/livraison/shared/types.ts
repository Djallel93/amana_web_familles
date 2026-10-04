// resources/js/components/livraison/shared/types.ts
//
// Types partagés entre les quatre écrans livraison (campagnes, contacts,
// tableau de bord, statistiques) — un seul fichier plutôt qu'un par
// composant, pour éviter que Livraison/Campagne/etc. divergent
// accidentellement entre écrans qui consomment les mêmes endpoints.

/** Enveloppe d'un LengthAwarePaginator Laravel sérialisé en JSON. */
export interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
}

// Laravel sérialise un paginator avec current_page/last_page/etc. à la
// racine ET dans un sous-objet meta selon la version/les ressources API
// utilisées ; les contrôleurs livraison renvoient response()->json($paginator)
// brut (voir CampagnesController::eligibles(), ContactTrackingController::queue()),
// donc les métadonnées sont à la racine. On les recompose ici pour que le
// reste du code (Paginator.vue notamment) n'ait qu'une seule forme à gérer.
export interface RawLaravelPaginator<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export function normalizePaginated<T>(raw: RawLaravelPaginator<T>): Paginated<T> {
    return {
        data: raw.data,
        links: raw.links,
        meta: {
            current_page: raw.current_page,
            last_page: raw.last_page,
            per_page: raw.per_page,
            total: raw.total,
            from: raw.from,
            to: raw.to,
        },
    };
}

export const CAMPAGNE_TYPES = {
    zakat_el_fitr: 'Zakat el-fitr',
    collecte_alimentaire: 'Collecte alimentaire',
    don_ponctuel: 'Don ponctuel',
} as const;

export type CampagneType = keyof typeof CAMPAGNE_TYPES;

/**
 * Statistiques d'une campagne pour la ligne dépliée de la liste
 * (CampagnesController::apercu(), 03/10/2026).
 */
export interface ApercuCampagne {
    familles: { total: number; confirmees: number; se_deplacent: number };
    contacts: Record<string, number>;
    benevoles: { disponibles: number; en_attente: number };
    poids: { estime_kg: number; collecte_kg: number };
    tournees: { total: number; par_statut: Record<string, number> };
    packaging: { pretes: number; confirmees: number; taux: number | null };
    livraisons: { livrees: number; ignorees: number; en_attente: number };
}

export interface Campagne {
    id: number;
    type: CampagneType;
    date_livraison: string;
    statut: string;
    poids_moyen_kg: number;
    poids_moyen_hotel_kg: number | null;
    poids_moyen_etudiant_kg: number | null;
    // Ajoutés le 05/09/2026 (prompt §1.2/§1.3) — voir Campagne (modèle PHP).
    hq_adresse: string | null;
    hq_latitude: number | null;
    hq_longitude: number | null;
    // NULL tant que le HQ n'est qu'hérité du réglage global — voir
    // create_campagnes_table.php (ajouté le 09/09/2026, prompt §3.2).
    hq_confirmee_le: string | null;
    // Ajouté le 08/09/2026 (prompt §2.2.3) — voir Campagne (modèle PHP).
    livraisons_max_par_tournee: number | null;
    // Ajoutés le 24/09/2026 (prompt de cette date §2/§4) — "HH:MM:SS",
    // voir CampagneResource.
    heure_debut_arrivee_hq: string | null;
    heure_fin_arrivee_hq: string | null;
    commentaire: string | null;
    poids_moyen_historique?: CampagnePoidsMoyenHistorique[];
    // Chargées via Campagne::journees() (voir CampagnesController::show())
    // — au moins une journée depuis le 05/09/2026, toute campagne en a une
    // (voir CampagnesController::store()).
    journees?: CampagneJournee[];
}

/** Voir CampagnePoidsMoyenHistorique (modèle PHP) et le prompt du 05/09/2026 §5.2. */
export interface CampagnePoidsMoyenHistorique {
    id: number;
    type: 'normal' | 'hotel' | 'etudiant';
    ancienne_valeur: number;
    nouvelle_valeur: number;
    horodatage: string;
    logge_par: number | PersonneResume;
}

/** Une journée de collecte/livraison d'une campagne — voir CampagneJournee. */
export interface CampagneJournee {
    id: number;
    id_campagne: number;
    date: string;
    label: string | null;
    ordre: number;
}

export interface Ville {
    id: number;
    nom: string;
}

export interface Organisation {
    id: number;
    nom: string;
}

export interface Secteur {
    id: number;
    nom: string;
    id_ville: number;
}

export interface Quartier {
    id: number;
    nom: string;
    id_secteur?: number;
}

/**
 * Famille telle que renvoyée par GET .../eligibles (checklist paginée) —
 * étendue le 05/09/2026 (prompt §1.6 : "real table with columns") avec
 * telephone_bis/email/adresse/ville_texte, absentes jusque-là de cette
 * vue.
 */
export interface FamilleEligible {
    id: number;
    nom: string;
    prenom: string;
    telephone: string;
    telephone_bis?: string | null;
    email?: string | null;
    adresse?: string | null;
    nombre_adulte: number;
    nombre_enfant: number;
    criticite: number | null;
    id_quartier: number | null;
    quartier: (Quartier & { secteur?: Secteur & { ville?: Ville } }) | null;
    id_organisation: number | null;
    est_hotel: boolean;
    etudiant: boolean;
    /** Calculée côté serveur — null si jamais livrée. */
    derniere_livraison_le: string | null;
    // id_livraison (09/09/2026, prompt §5.1.3) : présent seulement sur la
    // réponse de LiveBoardController::nonCouvertesTable() — cette table
    // réutilise FamilleEligible (mêmes colonnes) mais a besoin de l'id de
    // la Livraison (pas de la Famille) pour construire une tournée
    // personnalisée.
    id_livraison?: number;
}

/**
 * Filtres reconnus par App\Support\FamilleFilters — voir FamilleFilterPanel.vue,
 * utilisé à la fois par la sélection éligibilité campagne et Suivi des
 * contacts (prompt du 05/09/2026 §1.6/§2.6 : "same filter panel as
 * Dossier Familles"), et depuis le 10/09/2026 (Section A3 du refactor) par
 * familles/index.blade.php et nouvelles.blade.php elles-mêmes (voir
 * FamilleFiltresBar.vue). Toutes les clés sont optionnelles : un filtre
 * vide n'est simplement pas envoyé (voir buildQuery()).
 *
 * se_deplace (25/09/2026, prompt de cette date) : n'est PLUS une clé
 * App\Support\FamilleFilters (se_deplace a été retiré de Famille) — reste
 * ici seulement pour les 2 écrans où il désigne désormais un filtre sur
 * livraisons.se_deplace, PAR CAMPAGNE (voir FamilleFilterPanel.vue, prop
 * avecSeDeplace) : ContactsQueue.vue (ContactTrackingController::queteBase())
 * et BuildRouteFlow.vue (LiveBoardController::nonCouvertesTable()). Ces
 * deux écrans listent des Livraison déjà existantes pour la campagne
 * choisie — sans objet ailleurs (CampagneDetail.vue "familles éligibles",
 * FamilleFiltresBar.vue) où aucune Livraison n'existe encore pour la
 * famille : la case n'y est simplement pas affichée (prop absente,
 * défaut false).
 */
export interface FamilleFiltres {
    id_ville?: number | '';
    id_secteur?: number | '';
    id_quartier?: number | '';
    criticite?: number[];
    se_deplace?: boolean;
    est_hotel?: boolean;
    etudiant?: boolean;
    zakat_el_fitr?: boolean;
    sadaqa?: boolean;
    id_organisation_origine?: number | '';
    id_organisation_rattachee?: number | '';
    recherche?: string;
    // Champs Dossier Familles uniquement (voir FamilleFilterPanel.vue,
    // props avecStatut/avecAutocompletion — ajoutés le 10/09/2026, Section
    // A3 du refactor) : etat_dossier n'est PAS couvert par
    // App\Support\FamilleFilters (voir son docblock), chaque appelant
    // l'applique séparément avec son propre défaut. nom/telephone/
    // id_selection sont l'alternative à `recherche` utilisée par Dossier
    // Familles (autocomplétion — voir FamillesController::rechercheSuggestions()),
    // mutuellement exclusive avec `recherche` côté UI mais toutes deux
    // reconnues par FamilleFilters::appliquer() côté serveur.
    etat_dossier?: string;
    nom?: string;
    telephone?: string;
    id_selection?: number | '';
    // Contacts uniquement (FamilleFilterPanel.vue, prop avecAssignation —
    // 01/10/2026) : filtre sur livraisons.id_personne_assignee, voir
    // ContactTrackingController::queteBase(). non_assigne prime côté
    // serveur sur id_personne_assignee (mutuellement exclusifs en UI).
    id_personne_assignee?: number | '';
    non_assigne?: boolean;
}

/** Résultat d'une suggestion d'autocomplétion — voir
 *  FamillesController::rechercheSuggestions() et FamilleFilterPanel.vue
 *  (prop avecAutocompletion). */
export interface FamilleSuggestion {
    id: number;
    label: string;
    sous_label: string;
    valeur: string;
}

export interface Conflit {
    id: number;
    nom: string;
    raison: string;
}

export interface GenererLivraisonsResultat {
    generees: number;
    deja_existantes: number;
    conflits: Conflit[];
}

export interface NotifierBenevolesResultat {
    envoyes: number;
    echecs: number;
}

export interface GenererRoutesResultat {
    routes_creees: number;
    imposees: number;
    // Chaque valeur vient de RouteGenerationService::genererPourCreneau(),
    // pas d'un simple compteur — {routes_creees, non_couvertes} par
    // créneau, pas juste un nombre.
    par_creneau: Record<string, { routes_creees: number; non_couvertes: number }>;
}

export interface FamilleResume {
    id: number;
    nom: string;
    prenom: string;
    // telephone/email : présents seulement quand le contrôleur les
    // sélectionne explicitement (ex. ContactTrackingController::queue()
    // charge 'famille:id,nom,prenom,telephone,email') ; le contexte
    // tournées (LiveBoardController::routes(), etapes.livraison.famille)
    // charge 'famille:id,nom,prenom,adresse' — pas de colonnes
    // téléphone/email dans ce cas. D'où optionnels plutôt qu'obligatoires.
    telephone?: string;
    telephone_bis?: string | null;
    email?: string | null;
    adresse?: string;
    code_postal?: string | null;
    ville_texte?: string | null;
}

export interface PersonneResume {
    id: number;
    nom: string;
    prenom: string;
    // id_vehicule_type/vehicule_type (09/09/2026, prompt de cette date
    // §5.1.2/5.2.1) : présents seulement pour les bénévoles (voir
    // PickersController::personnes()) — le picker chauffeur de
    // BuildRouteFlow.vue/RoutesPanel.vue n'a plus de VehiculePicker à
    // côté, le véhicule est dérivé du profil du bénévole choisi.
    id_vehicule_type?: number | null;
    vehicule_type?: string | null;
}

/**
 * Rôles équipe_* affectables PAR CAMPAGNE (08/09/2026, voir
 * App\Models\CampagneEquipeMembre::ROLES et
 * EquipeMembresQueue.vue) — distinct du rôle global de même nom
 * (ref_personnes_roles), voir le docblock de ce composant.
 */
export const EQUIPE_ROLES = {
    equipe_reception: 'Réception',
    equipe_pesee: 'Pesée',
    equipe_packaging: 'Packaging',
    equipe_chargement: 'Chargement',
} as const;

export type EquipeRole = keyof typeof EQUIPE_ROLES;

// a_contacter est l'état initial (jamais posté par le front, seulement
// lu) — seuls contacte/injoignable/confirme sont acceptés par
// ContactTrackingController::contacterManuel() (voir sa validation).
export const STATUTS_CONTACT_INITIAL = 'a_contacter' as const;
export const STATUTS_CONTACT = ['a_contacter', 'contacte', 'injoignable', 'confirme'] as const;
export type StatutContact = (typeof STATUTS_CONTACT)[number];
/** Sous-ensemble réellement postable à .../contacter-manuel. */
// 'rejetee'/'archive' ajoutés le 03/09/2026 (voir le prompt de cette
// date §2.5 et App\Models\Livraison::STATUTS_CONTACT_EFFETS côté PHP,
// source de vérité) — liste de départ volontairement amenée à
// s'enrichir, donc gardée séparée d'un enum strict côté validation
// serveur (voir Livraison::STATUTS_CONTACT_POSTABLES).
// 'contacte' retiré des postables le 05/09/2026 (prompt §2.3 : "Delete the
// Contacte status and keep only confirme") — reste un statut affichable
// pour les lignes déjà en base (voir Livraison::STATUTS_CONTACT côté PHP),
// mais plus proposable dans le formulaire de contact manuel.
export const STATUTS_CONTACT_POSTABLES = ['injoignable', 'confirme', 'rejetee', 'archive'] as const;
export type StatutContactPostable = (typeof STATUTS_CONTACT_POSTABLES)[number];

// Créneaux horaires fixes — source de vérité PHP : app/Support/Creneau.php
// (8h→19h par blocs de 2h, dernier bloc 18h-19h). Repris ici tel quel
// plutôt qu'inventé côté front : la version placeholder codait déjà ces
// six créneaux en dur dans contacts.blade.php, donc CRENEAUX doit matcher
// exactement Creneau::TOUS pour rester valide côté validation serveur.
export const CRENEAUX = ['08-10', '10-12', '12-14', '14-16', '16-18', '18-19'] as const;
export type Creneau = (typeof CRENEAUX)[number];

export const CRENEAU_LIBELLES: Record<Creneau, string> = {
    '08-10': '8h - 10h',
    '10-12': '10h - 12h',
    '12-14': '12h - 14h',
    '14-16': '14h - 16h',
    '16-18': '16h - 18h',
    '18-19': '18h - 19h',
};

/**
 * Regroupement visuel matin/après-midi (05/09/2026, prompt §2.8) —
 * PUREMENT côté affichage : les créneaux eux-mêmes restent les 6 blocs de
 * 2h de App\Support\Creneau (voir CRENEAUX ci-dessus), inchangés côté
 * validation serveur. 12-14 chevauche la coupure 13h symbolique du
 * prompt ("8-13 was just an example") — rangé côté matin, choix
 * arbitraire mais assumé plutôt que de casser ce bloc en deux.
 */
export const CRENEAUX_MATIN: Creneau[] = ['08-10', '10-12', '12-14'];
export const CRENEAUX_APRES_MIDI: Creneau[] = ['14-16', '16-18', '18-19'];

/**
 * Livraison telle qu'utilisée par la file de contact et les écrans
 * tableau de bord. Les relations personne_assignee/campagne ne sont
 * chargées que par ContactTrackingController::queue() — absentes (pas
 * juste null : la clé n'existe pas dans le JSON) dans le contexte
 * tournées (LiveBoardController::routes()/nonCouvertes()), d'où
 * optionnelles plutôt qu'obligatoires ici.
 */
export interface Livraison {
    id: number;
    id_campagne: number;
    id_campagne_journee?: number | null;
    statut_contact: StatutContact;
    statut: string;
    id_personne_assignee: number | null;
    personne_assignee?: PersonneResume | null;
    famille: FamilleResume;
    campagne?: Campagne;
    adresse_confirmee: string | null;
    code_postal_confirme: string | null;
    ville_confirmee: string | null;
    nombre_adulte_confirme: number | null;
    nombre_enfant_confirme: number | null;
    creneaux?: { creneau: Creneau }[];
    // se_deplace (25/09/2026, prompt de cette date) : propriété pure de la
    // campagne (livraisons.se_deplace, NOT NULL, false par défaut) — plus
    // de notion d'override/valeur effective à résoudre, on lit/écrit
    // directement cette valeur. Présent sur toute réponse Livraison
    // sérialisée (jamais optionnel, contrairement à l'ancien
    // se_deplace_override qui pouvait être null).
    se_deplace: boolean;
}

export interface VehiculeType {
    id: number;
    type: string;
    capacite_kg: number;
    nombre_part_max: number;
}

/**
 * Un arrêt d'une tournée — id_livraison peut être null côté DB pour un
 * arrêt "retour QG" (voir EtapeRoute), d'où livraison nullable ici. statut
 * de l'étape elle-même (en_attente|en_cours|livree|ignoree, voir
 * EtapeRoute::STATUTS) est distinct de livraison.statut. 'en_cours' ajouté
 * le 09/09/2026 (prompt de cette date §5.2.3) — jamais posé par le
 * parcours bénévole, seulement via l'override manuel gestionnaire (voir
 * changerStatutEtape() dans RoutesPanel.vue).
 */
export const STATUTS_ETAPE = ['en_attente', 'en_cours', 'livree', 'ignoree'] as const;
export type StatutEtape = (typeof STATUTS_ETAPE)[number];

export const LIBELLES_STATUT_ETAPE: Record<StatutEtape, string> = {
    en_attente: 'Restante',
    en_cours: 'En cours',
    livree: 'Livrée',
    ignoree: 'Ignorée',
};

/**
 * Pastilles de statut d'arrêt — une couleur franche par statut (29/09/2026,
 * prompt §6.3) : restante = ambre (en attente), en cours = ciel, livrée =
 * émeraude, ignorée = rose. L'ancienne pastille « Restante » en stone
 * paraissait « sans couleur » à côté des autres.
 */
export const STYLES_STATUT_ETAPE: Record<StatutEtape, string> = {
    en_attente: 'bg-amber-100 text-amber-800 ring-1 ring-inset ring-amber-200',
    en_cours: 'bg-sky-100 text-sky-700 ring-1 ring-inset ring-sky-200',
    livree: 'bg-emerald-100 text-emerald-700 ring-1 ring-inset ring-emerald-200',
    ignoree: 'bg-rose-100 text-rose-700 ring-1 ring-inset ring-rose-200',
};

export interface Etape {
    id: number;
    ordre: number;
    statut: StatutEtape;
    livraison: Livraison | null;
}

/**
 * 'annulee' ajouté le 09/09/2026 (prompt de cette date §5.2.2) — soft-cancel
 * (voir RouteMutationService::supprimer()), la tournée reste visible en
 * historique sur Suivi livraison.
 */
export const STATUTS_ROUTE = ['planifiee', 'chargement', 'charge', 'en_cours', 'livraisons_terminees', 'terminee', 'packaging_annule', 'annulee'] as const;
export type StatutRoute = (typeof STATUTS_ROUTE)[number];

export const LIBELLES_STATUT_ROUTE: Record<StatutRoute, string> = {
    planifiee: 'Planifiée',
    chargement: 'Chargement',
    charge: 'Chargée',
    en_cours: 'En cours',
    livraisons_terminees: 'Livraisons terminées',
    terminee: 'Terminée',
    packaging_annule: 'Packaging annulé',
    annulee: 'Annulée',
};

/**
 * Pastilles de statut tournée — UNE couleur par statut (29/09/2026, prompt
 * de cette date §6.1 : « Chargement et Chargée ont la même couleur »).
 * Avant : chargement/charge en ambre, livraisons_terminees/terminee en
 * émeraude, packaging_annule/annulee en rose. Progression : stone (à venir)
 * → ambre (chargement) → indigo (chargée, prête à partir) → ciel (en route)
 * → sarcelle (livraisons faites) → émeraude (clôturée) ; orange = packaging
 * annulé (à reprendre), rose = annulée. Source unique : les vues Blade du
 * chargement (chargement-route.blade.php, chargement.blade.php) reprennent
 * les mêmes classes pour « Chargée » / « Packaging annulé ».
 */
export const STYLES_STATUT_ROUTE: Record<StatutRoute, string> = {
    planifiee: 'bg-stone-100 text-stone-700 ring-1 ring-inset ring-stone-200',
    chargement: 'bg-amber-100 text-amber-800 ring-1 ring-inset ring-amber-200',
    charge: 'bg-indigo-100 text-indigo-700 ring-1 ring-inset ring-indigo-200',
    en_cours: 'bg-sky-100 text-sky-700 ring-1 ring-inset ring-sky-200',
    livraisons_terminees: 'bg-teal-100 text-teal-700 ring-1 ring-inset ring-teal-200',
    terminee: 'bg-emerald-100 text-emerald-700 ring-1 ring-inset ring-emerald-200',
    packaging_annule: 'bg-orange-100 text-orange-700 ring-1 ring-inset ring-orange-200',
    annulee: 'bg-rose-100 text-rose-700 ring-1 ring-inset ring-rose-200',
};

export interface RouteLivraison {
    id: number;
    id_campagne: number;
    statut: StatutRoute;
    creneau: Creneau | null;
    benevole: PersonneResume | null;
    // vehiculeType() côté modèle → Eloquent snake_case automatiquement le
    // nom de la relation dans le JSON sérialisé (relationsToArray()) :
    // la clé réelle est vehicule_type, pas vehiculeType.
    vehicule_type: VehiculeType | null;
    etapes: Etape[];
}

// Source de vérité PHP : RouteIncident::TYPES. Seul benevole_absent
// déclenche un re-cluster (voir LiveBoardController::resoudre() et
// RouteIncident::TYPES_SANS_STATUT pour chargement_termine, qui est
// informationnel et n'a pas d'état "résolu" au sens propre).
export const TYPES_INCIDENT = ['benevole_absent', 'capacite', 'chargement_termine', 'livraison_ignoree', 'packaging_annule'] as const;
export type TypeIncident = (typeof TYPES_INCIDENT)[number];

export interface RouteIncident {
    id: number;
    type: TypeIncident;
    // 'ignore' (03/10/2026) : fermé sans traitement, voir RouteIncident::STATUTS.
    statut: 'ouvert' | 'ignore' | 'resolu';
    route: RouteLivraison | null;
    livraison: Livraison | null;
    created_at: string;
}

export interface ResoudreIncidentResultat {
    routes_creees: number;
    non_couvertes: number;
}

export interface StatistiquesDonnees {
    nombre_menages: number;
    poids_collecte_kg: number;
    livraisons_total: number;
    livraisons_par_statut: Record<string, number>;
    poids_livre_kg: number;
    routes_total: number;
    routes_par_statut: Record<string, number>;
    distance_totale_km: number;
    taux_livraison: number;
    // Ventilation par journée (05/09/2026) — indexée par id_campagne_journee
    // (clé JSON = string même si numérique, voir CampagneStatsService).
    // Absente/vide pour les campagnes créées avant cette évolution.
    par_journee: Record<string, StatistiquesParJournee>;
}

export interface StatistiquesParJournee {
    label: string | null;
    date: string;
    livraisons_total: number;
    livraisons_par_statut: Record<string, number>;
    poids_livre_kg: number;
    routes_total: number;
    routes_par_statut: Record<string, number>;
    distance_totale_km: number;
    taux_livraison: number;
}

/**
 * Cartes statistiques de Suivi livraison — ajoutées le 09/09/2026 (prompt
 * de cette date §5.2.4). Voir LiveBoardController::statistiques().
 */
export interface SuiviLivraisonStatistiques {
    tournees_total: number;
    tournees_actives: number;
    tournees_annulees: number;
    tournees_terminees: number;
    livraisons_restantes: number;
    livraisons_en_cours: number;
    livraisons_livrees: number;
    livraisons_ignorees: number;
    avancement_pct: number;
}

/**
 * Avancement d'une campagne (CampagnesController::avancement()) — alimente
 * les badges des cartes du hub. Déplacé de CampagneProgressBar.vue (barre de
 * progression retirée du hub le 03/10/2026).
 */
export interface AvancementCampagne {
    livraisons_generees: boolean;
    contacts_termines: boolean;
    contacts_en_cours: boolean;
    benevoles_notifies: boolean;
    routes_generees: boolean;
    reception_demarree: boolean;
    pesee_demarree: boolean;
    packaging_termine: boolean;
    chargement_termine: boolean;
    livraison_en_cours: boolean;
    terminee: boolean;
    compteurs: {
        livraisons_total: number;
        livraisons_confirmees: number;
        routes_total: number;
        routes_terminees: number;
        incidents_ouverts: number;
    };
}

/** Ligne de la page Incidents d'une campagne (IncidentsController::index()). */
export interface LigneIncident {
    id: number;
    type: TypeIncident;
    type_label: string;
    statut: 'ouvert' | 'ignore' | 'resolu';
    description: string;
    guide: string | null;
    notes: string | null;
    id_route: number;
    chauffeur: string | null;
    famille: string | null;
    signale_par: string | null;
    created_at: string | null;
}
