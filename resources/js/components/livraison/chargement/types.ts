// resources/js/components/livraison/chargement/types.ts
//
// Formes renvoyées par ChargementController (index() et liste()) — écran Chargement, 11/10/2026.

export type EtatChargement =
    "restante" | "en_preparation" | "prete" | "chargee" | "packaging_annule" | "en_livraison" | "livree";

/** Statut d'un arrêt de tournée (route_etapes.statut) — lu une fois la tournée partie. */
export type StatutArret = "en_attente" | "en_cours" | "livree" | "ignoree";

export interface EtapeChargement {
    id: number;
    id_livraison: number;
    famille_id: number;
    etat: EtatChargement;
    etat_libelle: string;
    etat_style: string;
    etudiant: boolean;
    est_hotel: boolean;
    nombre_enfant: number;
    prioritaire: boolean;
    statut_arret: StatutArret;
}

export interface RouteChargement {
    id: number;
    statut: string;
    etat: EtatChargement;
    etat_libelle: string;
    etat_style: string;
    urgence: "famille" | "benevole" | null;
    /** Chauffeur pas encore rentré au QG (18/10/2026) : « en_livraison » ou « parti » (a répondu « Non »). */
    chauffeur_alerte: "en_livraison" | "parti" | null;
    vide: boolean;
    /** Tournée déjà partie (en livraison / terminée) : lecture seule. */
    partie: boolean;
    creneau_libelle: string;
    benevole: { id: number; prenom: string; nom: string; telephone: string | null } | null;
    vehicule: string | null;
    colis: number;
    verrouillee: boolean;
    peut_confirmer: boolean;
    peut_annuler_chargement: boolean;
    peut_prioriser: boolean;
    peut_supprimer: boolean;
    etapes: EtapeChargement[];
}

export interface FamilleSansTournee {
    id: number;
    famille_id: number;
    etat: EtatChargement;
    etat_libelle: string;
    etat_style: string;
    etudiant: boolean;
    est_hotel: boolean;
    nombre_enfant: number;
}

export interface StatsChargement {
    chargees: number;
    restantes: number;
    en_preparation: number;
    pretes: number;
    en_livraison: number;
    terminees: number;
    vides: number;
}

export interface RappelRoutes {
    demarree: boolean;
    peut_generer: boolean;
    url_generer: string;
    url_campagne: string;
}

export interface UrlsChargement {
    liste: string;
    confirmer: string;
    annulerChargement: string;
    benevoleAbsent: string;
    capacite: string;
    prioriser: string;
    ajouterFamille: string;
    retirerFamille: string;
    reassigner: string;
    diviser: string;
    supprimer: string;
}

export type FiltreChargement =
    "toutes" | "restantes" | "en_preparation" | "pretes" | "chargees" | "en_livraison" | "terminees" | "vides";
