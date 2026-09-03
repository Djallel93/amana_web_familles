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

export interface Campagne {
    id: number;
    type: CampagneType;
    date_livraison: string;
    statut: string;
    poids_moyen_kg: number;
    poids_moyen_hotel_kg: number | null;
    poids_moyen_etudiant_kg: number | null;
}

export interface Quartier {
    id: number;
    nom: string;
}

/** Famille telle que renvoyée par GET .../eligibles (checklist paginée). */
export interface FamilleEligible {
    id: number;
    nom: string;
    prenom: string;
    telephone: string;
    nombre_adulte: number;
    nombre_enfant: number;
    criticite: number | null;
    id_quartier: number | null;
    quartier: Quartier | null;
    id_organisation: number | null;
    est_hotel: boolean;
    etudiant: boolean;
    /** Calculée côté serveur — null si jamais livrée. */
    derniere_livraison_le: string | null;
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
    email?: string | null;
    adresse?: string;
    code_postal?: string | null;
    ville_texte?: string | null;
}

export interface PersonneResume {
    id: number;
    nom: string;
    prenom: string;
}

// a_contacter est l'état initial (jamais posté par le front, seulement
// lu) — seuls contacte/injoignable/confirme sont acceptés par
// ContactTrackingController::contacterManuel() (voir sa validation).
export const STATUTS_CONTACT_INITIAL = 'a_contacter' as const;
export const STATUTS_CONTACT = ['a_contacter', 'contacte', 'injoignable', 'confirme'] as const;
export type StatutContact = (typeof STATUTS_CONTACT)[number];
/** Sous-ensemble réellement postable à .../contacter-manuel. */
export const STATUTS_CONTACT_POSTABLES = ['contacte', 'injoignable', 'confirme'] as const;
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
 * de l'étape elle-même (en_attente|livree|ignoree, voir EtapeRoute::STATUTS)
 * est distinct de livraison.statut.
 */
export interface Etape {
    id: number;
    ordre: number;
    statut: 'en_attente' | 'livree' | 'ignoree';
    livraison: Livraison | null;
}

export interface RouteLivraison {
    id: number;
    id_campagne: number;
    statut: string;
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
export const TYPES_INCIDENT = ['benevole_absent', 'capacite', 'chargement_termine', 'livraison_ignoree'] as const;
export type TypeIncident = (typeof TYPES_INCIDENT)[number];

export interface RouteIncident {
    id: number;
    type: TypeIncident;
    statut: 'ouvert' | 'resolu';
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
}
