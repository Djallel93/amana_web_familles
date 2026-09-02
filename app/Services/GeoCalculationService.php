<?php
// app/Services/GeoCalculationService.php

declare(strict_types=1);

namespace App\Services;

/**
 * Calculs géométriques purs pour le clustering/TSP — port direct de
 * routeGeometryService.js (amana_livraison), voir
 * calculerDistanceHaversine/calculateMaxDiameter/calculateCompactness.
 *
 * PAS de jambe de retour au QG dans les calculs de distance de tournée
 * (calculerDistanceTotaleRoute côté legacy) — décision du 31/08/2026,
 * confirmée après avoir trouvé une incohérence dans le legacy lui-même :
 * routeGeometryService.js documente avoir supprimé cette jambe de retour
 * ("Modifications: ... suppression de la jambe de retour QG"), mais
 * routeTspOptimization.js contient encore sa PROPRE copie de
 * calculateSegmentDistance qui, elle, referme la boucle vers le QG pour
 * le dernier segment — un bug de divergence jamais corrigé côté legacy.
 * Port ici la version corrigée et cohérente partout : jamais de retour au
 * QG, ni dans le total ni dans l'objectif d'optimisation du 2-opt (voir
 * TspOptimizationService).
 *
 * Méthodes en array plutôt qu'un DTO Livraison dédié : ce service opère
 * sur la forme `array{latitude: float, longitude: float, ...}` utilisée
 * par tout le pipeline de clustering (voir ClusteringService), qui
 * elle-même reflète fidèlement les objets JS du système legacy — pas de
 * nouvelle abstraction introduite pour un port qui n'en avait pas besoin.
 */
class GeoCalculationService
{
    private const RAYON_TERRE_KM = 6371;

    /**
     * Distance Haversine entre deux points GPS, en km.
     */
    public function distanceHaversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::RAYON_TERRE_KM * $c;
    }

    /**
     * Diamètre maximum (plus grande distance entre deux points quelconques)
     * d'un ensemble combiné de livraisons existantes + nouvelles — port de
     * calculateMaxDiameter().
     *
     * @param array<int, array{latitude: float, longitude: float}> $existantes
     * @param array<int, array{latitude: float, longitude: float}> $nouvelles
     */
    public function diametreMaximum(array $existantes, array $nouvelles): float
    {
        $toutes = [...$existantes, ...$nouvelles];

        if (count($toutes) < 2) {
            return 0.0;
        }

        $maxDist = 0.0;
        $n = count($toutes);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $dist = $this->distanceHaversine(
                    $toutes[$i]['latitude'], $toutes[$i]['longitude'],
                    $toutes[$j]['latitude'], $toutes[$j]['longitude'],
                );
                $maxDist = max($maxDist, $dist);
            }
        }

        return $maxDist;
    }

    /**
     * Diamètre d'un cluster seul (sans ajout) — port de
     * calculateClusterDiameter().
     *
     * @param array<int, array{latitude: float, longitude: float}> $livraisons
     */
    public function diametreCluster(array $livraisons): float
    {
        return $this->diametreMaximum($livraisons, []);
    }

    /**
     * Compacité d'un cluster : ratio distance_moyenne/distance_max au
     * centre — plus proche de 1 = plus compact. Port de
     * calculateCompactness().
     *
     * @param array<int, array{latitude: float, longitude: float}> $livraisons
     */
    public function compacite(array $livraisons): float
    {
        if (count($livraisons) < 2) {
            return 1.0;
        }

        $centreLat = array_sum(array_column($livraisons, 'latitude')) / count($livraisons);
        $centreLng = array_sum(array_column($livraisons, 'longitude')) / count($livraisons);

        $distances = array_map(
            fn (array $l) => $this->distanceHaversine($centreLat, $centreLng, $l['latitude'], $l['longitude']),
            $livraisons,
        );

        $distanceMoyenne = array_sum($distances) / count($distances);
        $distanceMax = max($distances);

        if ($distanceMax === 0.0) {
            return 1.0;
        }

        return $distanceMoyenne / $distanceMax;
    }

    /**
     * Barycentre d'un ensemble de livraisons — port de calculerCentre()/
     * mettreAJourCentre() (partie calcul, sans la distance au QG qui est
     * recalculée séparément par l'appelant).
     *
     * @param array<int, array{latitude: float, longitude: float}> $livraisons
     * @return array{lat: float, lng: float}
     */
    public function centre(array $livraisons): array
    {
        $n = count($livraisons);

        return [
            'lat' => $n > 0 ? array_sum(array_column($livraisons, 'latitude')) / $n : 0.0,
            'lng' => $n > 0 ? array_sum(array_column($livraisons, 'longitude')) / $n : 0.0,
        ];
    }

    /**
     * Distance totale d'une tournée déjà ordonnée, SANS jambe de retour
     * au QG (voir TspOptimizationService et le docblock de cette classe
     * pour le raisonnement complet). Utilisée à la fois par
     * RouteGenerationService (génération) et RouteMutationService
     * (ajout/retrait/scission/tournée personnalisée) — un seul endroit
     * pour ce calcul, plutôt qu'un doublon entre les deux.
     *
     * @param array<int, array{latitude: float, longitude: float}> $livraisons
     * @param array{lat: float, lng: float} $hq
     */
    public function distanceTotaleRoute(array $livraisons, array $hq): float
    {
        if (empty($livraisons)) {
            return 0.0;
        }

        $distance = $this->distanceHaversine(
            $hq['lat'], $hq['lng'],
            $livraisons[0]['latitude'], $livraisons[0]['longitude'],
        );

        for ($i = 0; $i < count($livraisons) - 1; $i++) {
            $distance += $this->distanceHaversine(
                $livraisons[$i]['latitude'], $livraisons[$i]['longitude'],
                $livraisons[$i + 1]['latitude'], $livraisons[$i + 1]['longitude'],
            );
        }

        return $distance;
    }

    /**
     * Lien Google Maps multi-arrêts, QG en point de départ, PAS de retour
     * au QG en destination finale (cohérent avec l'absence de jambe de
     * retour partout ailleurs) — la dernière livraison est la
     * destination. Partagé entre RouteGenerationService et
     * RouteMutationService, même raisonnement que distanceTotaleRoute().
     *
     * @param array<int, array{latitude: float, longitude: float}> $livraisons
     * @param array{lat: float, lng: float} $hq
     */
    public function construireLienMaps(array $livraisons, array $hq): string
    {
        if (empty($livraisons)) {
            return '';
        }

        $origine = "{$hq['lat']},{$hq['lng']}";
        $destination = "{$livraisons[count($livraisons) - 1]['latitude']},{$livraisons[count($livraisons) - 1]['longitude']}";

        $etapesIntermediaires = array_slice($livraisons, 0, -1);
        $waypoints = implode('|', array_map(
            fn (array $l) => "{$l['latitude']},{$l['longitude']}",
            $etapesIntermediaires,
        ));

        $url = "https://www.google.com/maps/dir/?api=1&origin={$origine}&destination={$destination}";
        if ($waypoints !== '') {
            $url .= '&waypoints=' . $waypoints;
        }

        return $url;
    }
}
