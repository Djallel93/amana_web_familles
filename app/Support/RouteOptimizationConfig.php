<?php
// app/Support/RouteOptimizationConfig.php

declare(strict_types=1);

namespace App\Support;

use Amana\Shared\Models\Setting;
use App\Models\Campagne;

/**
 * Lecture des réglages du clustering/assignation/TSP — voir
 * 2026_08_31_000300_seed_route_optimization_settings.php pour les
 * valeurs par défaut et leur origine (CONFIG_ROUTE_OPTIMIZATION,
 * amana_livraison).
 *
 * Les 5 réglages numériques ci-dessous sont de type natif 'float'/
 * 'integer' depuis le 07/09/2026 (voir la migration de seed) :
 * Setting::get() renvoie déjà un float/int, plus de cast manuel ici. Le
 * fallback `?? défaut` sur chaque accesseur protège contre une ligne
 * ref_settings supprimée par erreur (Setting::get() renvoie alors null).
 */
final class RouteOptimizationConfig
{
    public static function distanceProximiteKm(): float
    {
        return Setting::get('route_distance_proximite_km', 'familles') ?? 2.5;
    }

    public static function maxClusterDiameterKm(): float
    {
        return Setting::get('route_max_cluster_diameter_km', 'familles') ?? 5;
    }

    public static function sameBuildingThresholdKm(): float
    {
        $metres = Setting::get('route_same_building_threshold_m', 'familles') ?? 50;
        return $metres / 1000;
    }

    public static function quartierPreference(): bool
    {
        $valeur = Setting::get('route_quartier_preference', 'familles');
        return $valeur === null ? true : (bool) $valeur;
    }

    public static function allowCrossQuartier(): bool
    {
        $valeur = Setting::get('route_allow_cross_quartier', 'familles');
        return $valeur === null ? true : (bool) $valeur;
    }

    public static function minCompactnessRatio(): float
    {
        return Setting::get('route_min_compactness_ratio', 'familles') ?? 0.4;
    }

    public static function maxLivraisonsParRoute(): int
    {
        return Setting::get('route_max_livraisons_par_route', 'familles') ?? 15;
    }

    /**
     * Coordonnées du QG (point de départ des tournées) — AUCUN défaut sûr
     * n'existe (propre à AMANA), voir
     * 2026_08_31_000300_seed_route_optimization_settings.php. Renvoie
     * null tant que l'admin ne les a pas renseignées ; à
     * RouteGenerationService de refuser de lancer un clustering dans ce
     * cas plutôt que de calculer des distances depuis (0, 0).
     *
     * route_hq_latitude/route_hq_longitude sont de type 'float' (depuis le
     * 05/09/2026, voir la migration de seed) : Setting::get() renvoie déjà
     * un float, ou null tant que la valeur stockée est une chaîne vide
     * (Setting::cast() ne caste plus '' en 0.0, voir amana/shared) — plus
     * besoin de vérifier is_string()/chaîne vide ici comme avant leur
     * passage en type natif.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function coordonneesHq(): ?array
    {
        $lat = Setting::get('route_hq_latitude', 'familles');
        $lng = Setting::get('route_hq_longitude', 'familles');

        if ($lat === null || $lng === null) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }

    /**
     * Coordonnées du QG à utiliser pour LE CLUSTERING/TSP d'une campagne
     * précise — voir le prompt du 05/09/2026 §1.2 : chaque campagne peut
     * désormais surcharger le HQ global (campagnes.hq_latitude/
     * hq_longitude, préremplies au réglage global à la création, voir
     * CampagnesController::store()). Prend le HQ propre à la campagne
     * s'il est renseigné, sinon retombe sur coordonneesHq() (réglage
     * global) — utilisé partout où coordonneesHq() l'était jusqu'ici pour
     * un calcul de tournée (RouteGenerationService/RouteMutationService),
     * plutôt que de garder ces services aveugles au HQ par campagne.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function coordonneesHqPourCampagne(Campagne $campagne): ?array
    {
        if ($campagne->hq_latitude !== null && $campagne->hq_longitude !== null) {
            return ['lat' => (float) $campagne->hq_latitude, 'lng' => (float) $campagne->hq_longitude];
        }

        return self::coordonneesHq();
    }

    /**
     * Cap de livraisons/tournée à utiliser pour LE CLUSTERING d'une
     * campagne précise — même logique que coordonneesHqPourCampagne()
     * ci-dessus (prompt du 08/09/2026 §2.2.3) : chaque campagne peut
     * surcharger le réglage global (campagnes.livraisons_max_par_tournee,
     * préremplie au réglage global à la création, voir
     * CampagnesController::store()). Prend la valeur propre à la
     * campagne si renseignée, sinon retombe sur maxLivraisonsParRoute()
     * (réglage global) — à utiliser à la place de maxLivraisonsParRoute()
     * partout où RouteGenerationService connaît déjà la campagne
     * concernée (VehicleAssignmentService::assigner()/
     * ClusterSplitService::scinder() restent, eux, agnostiques de
     * Campagne — voir leurs docblocks — et reçoivent la valeur déjà
     * résolue en paramètre plutôt que de la relire elles-mêmes).
     */
    public static function maxLivraisonsParRoutePourCampagne(Campagne $campagne): int
    {
        return $campagne->livraisons_max_par_tournee ?? self::maxLivraisonsParRoute();
    }
}
