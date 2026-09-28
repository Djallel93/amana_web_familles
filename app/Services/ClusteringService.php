<?php
// app/Services/ClusteringService.php

declare(strict_types=1);

namespace App\Services;

use App\Support\RouteOptimizationConfig;

/**
 * Clustering géographique — port direct de routeClusteringService.js
 * (amana_livraison : grouperParBatiment, identifierClusters,
 * mettreAJourCentre), avec UN changement délibéré demandé par le prompt
 * du 30/08/2026 §3.3 point 2 : le legacy est "clustering purement
 * géographique — contraintes de capacité vérifiées à l'assignation"
 * (voir le commentaire d'origine, conservé ci-dessous pour traçabilité).
 * Ici, un plafond de poids (soft ceiling, dérivé du plus grand véhicule
 * confirmé pour le créneau en cours — voir RouteGenerationService, qui le
 * calcule et le passe en paramètre) est vérifié DÈS la fusion des
 * groupes, pas seulement à l'assignation — pour que les scissions
 * (ClusterSplitService) restent l'exception plutôt que la norme.
 *
 * Toutes les autres vérifications (distance au centre, diamètre max,
 * compacité min, préférence quartier) et le calcul de score sont portés
 * à l'identique.
 *
 * Livraison au format array : @param array{id_livraison: int, latitude:
 * float, longitude: float, nombre_personnes: int, poids_kg: float,
 * id_quartier: ?int} — même forme que le reste du pipeline (voir
 * RouteGenerationService), reflétant fidèlement les objets JS d'origine.
 */
class ClusteringService
{
    public function __construct(
        private readonly GeoCalculationService $geo,
    ) {
    }

    /**
     * Identifie les clusters géographiques — port de identifierClusters().
     *
     * @param array<int, array{id_livraison: int, latitude: float, longitude: float, nombre_personnes: int, poids_kg: float, id_quartier: ?int}> $livraisons
     * @param array{lat: float, lng: float} $hq
     * @param float $plafondPoidsKg Plafond de poids par cluster — CHANGEMENT du 31/08/2026, dérivé
     *        du plus grand véhicule confirmé pour le créneau en cours (voir RouteGenerationService).
     * @return array<int, array{id: string, centre: array{lat: float, lng: float}, livraisons: array, quartier_id: ?int, distance_hq: float, nombre_livraisons: int, nombre_parts: int, poids_total: float}>
     */
    public function identifierClusters(array $livraisons, array $hq, float $plafondPoidsKg): array
    {
        $clusters = [];

        $distanceProximite = RouteOptimizationConfig::distanceProximiteKm();
        $maxDiametre = RouteOptimizationConfig::maxClusterDiameterKm();
        $minCompacite = RouteOptimizationConfig::minCompactnessRatio();
        $preferenceQuartier = RouteOptimizationConfig::quartierPreference();
        $autoriserInterQuartier = RouteOptimizationConfig::allowCrossQuartier();

        // Étape 1 : Grouper les livraisons au même bâtiment.
        $groupesBatiment = $this->grouperParBatiment($livraisons, $hq);

        // Étape 2 : Créer les clusters avec contrôles géographiques stricts.
        foreach ($groupesBatiment as $groupe) {
            $meilleurCluster = null;
            $meilleurScore = -1;
            $meilleurIndex = null;

            foreach ($clusters as $index => $cluster) {
                // Vérification 1 : Distance au centre.
                $distCentre = $this->geo->distanceHaversine(
                    $cluster['centre']['lat'], $cluster['centre']['lng'],
                    $groupe['centre']['lat'], $groupe['centre']['lng'],
                );
                if ($distCentre > $distanceProximite) {
                    continue;
                }

                // Vérification 2 : Diamètre maximum.
                $nouveauDiametre = $this->geo->diametreMaximum($cluster['livraisons'], $groupe['livraisons']);
                if ($nouveauDiametre > $maxDiametre) {
                    continue;
                }

                // Vérification 3 : Compacité minimale.
                $nouvelleCompacite = $this->geo->compacite([...$cluster['livraisons'], ...$groupe['livraisons']]);
                if ($nouvelleCompacite < $minCompacite) {
                    continue;
                }

                // Vérification 4 (AJOUT du 31/08/2026, absente du legacy) :
                // plafond de poids — capacity-aware dès le clustering, pas
                // seulement à l'assignation (voir docblock de classe).
                $nouveauPoids = $cluster['poids_total'] + $groupe['poids_total'];
                if ($nouveauPoids > $plafondPoidsKg) {
                    continue;
                }

                // Vérification 5 : Préférence quartier.
                $memeQuartier = $cluster['quartier_id'] === $groupe['quartier_id'];
                if (!$memeQuartier && !$autoriserInterQuartier) {
                    continue;
                }

                // Calcul du score.
                $score = 0.0;
                if ($memeQuartier && $preferenceQuartier) {
                    $score += 10;
                }
                $score += ($distanceProximite - $distCentre) * 2;
                $score += $nouvelleCompacite * 5;

                if ($score > $meilleurScore) {
                    $meilleurScore = $score;
                    $meilleurCluster = $cluster;
                    $meilleurIndex = $index;
                }
            }

            if ($meilleurCluster !== null) {
                // Fusionner le groupe dans le cluster existant.
                $meilleurCluster['livraisons'] = [...$meilleurCluster['livraisons'], ...$groupe['livraisons']];
                $meilleurCluster['nombre_livraisons'] += count($groupe['livraisons']);
                $meilleurCluster['nombre_parts'] += $groupe['nombre_parts'];
                $meilleurCluster['poids_total'] = array_sum(array_column($meilleurCluster['livraisons'], 'poids_kg'));

                $meilleurCluster = $this->mettreAJourCentre($meilleurCluster, $hq);

                $clusters[$meilleurIndex] = $meilleurCluster;
            } else {
                $clusters[] = [
                    'id' => 'C' . (count($clusters) + 1),
                    'centre' => $groupe['centre'],
                    'livraisons' => $groupe['livraisons'],
                    'quartier_id' => $groupe['quartier_id'],
                    'distance_hq' => $groupe['distance_hq'],
                    'nombre_livraisons' => count($groupe['livraisons']),
                    'nombre_parts' => $groupe['nombre_parts'],
                    'poids_total' => $groupe['poids_total'],
                ];
            }
        }

        // Étape 3 : Trier par distance du QG (le plus loin en premier).
        usort($clusters, fn ($a, $b) => $b['distance_hq'] <=> $a['distance_hq']);

        return $clusters;
    }

    /**
     * Groupe les livraisons par bâtiment (même adresse ≈ mêmes coordonnées
     * GPS) — port de grouperParBatiment(). Le "centre" du groupe est
     * l'ancre (première livraison rencontrée du groupe), PAS une moyenne
     * — fidèle au comportement d'origine.
     *
     * @param array<int, array{id_livraison: int, latitude: float, longitude: float, nombre_personnes: int, poids_kg: float, id_quartier: ?int}> $livraisons
     * @param array{lat: float, lng: float} $hq
     */
    private function grouperParBatiment(array $livraisons, array $hq): array
    {
        $groupes = [];
        $traites = [];
        $seuilKm = RouteOptimizationConfig::sameBuildingThresholdKm();

        foreach ($livraisons as $livraison) {
            if (in_array($livraison['id_livraison'], $traites, true)) {
                continue;
            }

            $memeBatiment = array_values(array_filter($livraisons, function ($autre) use ($livraison, $traites, $seuilKm) {
                if (in_array($autre['id_livraison'], $traites, true)) {
                    return false;
                }
                $dist = $this->geo->distanceHaversine(
                    $livraison['latitude'], $livraison['longitude'],
                    $autre['latitude'], $autre['longitude'],
                );
                return $dist < $seuilKm;
            }));

            foreach ($memeBatiment as $l) {
                $traites[] = $l['id_livraison'];
            }

            $totalParts = array_sum(array_column($memeBatiment, 'nombre_personnes'));
            $poidsTotal = array_sum(array_column($memeBatiment, 'poids_kg'));

            $distHq = $this->geo->distanceHaversine(
                $hq['lat'], $hq['lng'],
                $livraison['latitude'], $livraison['longitude'],
            );

            $groupes[] = [
                'centre' => ['lat' => $livraison['latitude'], 'lng' => $livraison['longitude']],
                'livraisons' => $memeBatiment,
                'nombre_parts' => $totalParts,
                'poids_total' => $poidsTotal,
                'quartier_id' => $livraison['id_quartier'],
                'distance_hq' => $distHq,
            ];
        }

        return $groupes;
    }

    /**
     * Met à jour le centre d'un cluster (barycentre des livraisons
     * individuelles, pas des groupes) et recalcule sa distance au QG —
     * port de mettreAJourCentre().
     *
     * @param array{lat: float, lng: float} $hq
     */
    private function mettreAJourCentre(array $cluster, array $hq): array
    {
        if (count($cluster['livraisons']) === 0) {
            return $cluster;
        }

        $cluster['centre'] = $this->geo->centre($cluster['livraisons']);

        $cluster['distance_hq'] = $this->geo->distanceHaversine(
            $hq['lat'], $hq['lng'],
            $cluster['centre']['lat'], $cluster['centre']['lng'],
        );

        return $cluster;
    }
}
