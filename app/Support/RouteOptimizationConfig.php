<?php
// app/Support/RouteOptimizationConfig.php

declare(strict_types=1);

namespace App\Support;

use Amana\Shared\Models\Setting;

/**
 * Lecture des réglages du clustering/assignation/TSP — voir
 * 2026_08_31_000300_seed_route_optimization_settings.php pour les
 * valeurs par défaut et leur origine (CONFIG_ROUTE_OPTIMIZATION,
 * amana_livraison).
 *
 * Setting::cast() (amana/shared) n'a pas de type décimal — les valeurs
 * numériques sont stockées en 'string' et converties ici, pas dans le
 * paquet partagé (voir le fallback `?? défaut` sur chaque accesseur, qui
 * protège aussi contre une ligne ref_settings supprimée par erreur).
 */
final class RouteOptimizationConfig
{
    public static function distanceProximiteKm(): float
    {
        return (float) (Setting::get('route_distance_proximite_km', 'familles') ?? 2.5);
    }

    public static function maxClusterDiameterKm(): float
    {
        return (float) (Setting::get('route_max_cluster_diameter_km', 'familles') ?? 5);
    }

    public static function sameBuildingThresholdKm(): float
    {
        $metres = (float) (Setting::get('route_same_building_threshold_m', 'familles') ?? 50);
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
        return (float) (Setting::get('route_min_compactness_ratio', 'familles') ?? 0.4);
    }

    public static function maxLivraisonsParRoute(): int
    {
        return (int) (Setting::get('route_max_livraisons_par_route', 'familles') ?? 15);
    }

    /**
     * Coordonnées du QG (point de départ des tournées) — AUCUN défaut sûr
     * n'existe (propre à AMANA), voir
     * 2026_08_31_000300_seed_route_optimization_settings.php. Renvoie
     * null tant que l'admin ne les a pas renseignées ; à
     * RouteGenerationService de refuser de lancer un clustering dans ce
     * cas plutôt que de calculer des distances depuis (0, 0).
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function coordonneesHq(): ?array
    {
        $lat = Setting::get('route_hq_latitude', 'familles');
        $lng = Setting::get('route_hq_longitude', 'familles');

        if (!is_string($lat) || !is_string($lng) || $lat === '' || $lng === '') {
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }
}
