<?php
// app/Services/TspOptimizationService.php

declare(strict_types=1);

namespace App\Services;

/**
 * Ordonnancement des arrêts d'une tournée — port de
 * routeTspOptimization.js (amana_livraison) : nearestNeighborTSP()
 * (construction gloutonne) + twoOptImprovement() (élimination des
 * croisements par inversion de segments).
 *
 * CORRECTION DU 31/08/2026 (pas un changement demandé par le prompt, mais
 * une incohérence trouvée DANS le legacy lui-même) : routeGeometryService.js
 * documente avoir supprimé la jambe de retour au QG des calculs de
 * distance, mais routeTspOptimization.js contenait encore sa PROPRE copie
 * de calculateSegmentDistance() qui, elle, refermait la boucle vers le QG
 * pour le dernier segment — jamais corrigée côté legacy (deux définitions
 * divergentes du même nom de fonction dans deux fichiers). Porté ici de
 * façon cohérente : AUCUNE jambe de retour au QG, ni dans la construction
 * gloutonne (sans objet, elle ne boucle déjà pas), ni dans l'objectif du
 * 2-opt (calculerDistanceSegment ci-dessous, corrigé).
 */
class TspOptimizationService
{
    private const MAX_ITERATIONS_2OPT = 200;

    public function __construct(
        private readonly GeoCalculationService $geo,
    ) {
    }

    /**
     * Point d'entrée — port de optimizeDeliveryOrder().
     *
     * @param array<int, array{id_livraison: int, latitude: float, longitude: float}> $livraisons
     * @param array{lat: float, lng: float} $hq
     * @return array<int, array{id_livraison: int, latitude: float, longitude: float}>
     */
    public function optimiser(array $livraisons, array $hq): array
    {
        if (count($livraisons) === 0) {
            return [];
        }

        if (count($livraisons) === 1) {
            return $livraisons;
        }

        $route = $this->plusProcheVoisin($livraisons, $hq);

        return $this->ameliorationDeuxOpt($route, $hq);
    }

    /**
     * Construction gloutonne : part du QG, visite toujours la livraison
     * non-visitée la plus proche — port de nearestNeighborTSP().
     */
    private function plusProcheVoisin(array $livraisons, array $hq): array
    {
        $visitees = [];
        $route = [];

        $latCourante = $hq['lat'];
        $lngCourante = $hq['lng'];

        $n = count($livraisons);

        for ($i = 0; $i < $n; $i++) {
            $indexProche = -1;
            $distanceMin = INF;

            foreach ($livraisons as $j => $livraison) {
                if (in_array($j, $visitees, true)) {
                    continue;
                }

                $distance = $this->geo->distanceHaversine(
                    $latCourante, $lngCourante,
                    $livraison['latitude'], $livraison['longitude'],
                );

                if ($distance < $distanceMin) {
                    $distanceMin = $distance;
                    $indexProche = $j;
                }
            }

            if ($indexProche === -1) {
                break;
            }

            $visitees[] = $indexProche;
            $route[] = $livraisons[$indexProche];

            $latCourante = $livraisons[$indexProche]['latitude'];
            $lngCourante = $livraisons[$indexProche]['longitude'];
        }

        return $route;
    }

    /**
     * Élimination des croisements par inversion de segments — port de
     * twoOptImprovement().
     */
    private function ameliorationDeuxOpt(array $route, array $hq): array
    {
        if (count($route) < 4) {
            return $route;
        }

        $ameliore = true;
        $iterations = 0;

        while ($ameliore && $iterations < self::MAX_ITERATIONS_2OPT) {
            $ameliore = false;
            $iterations++;

            for ($i = 0; $i < count($route) - 1; $i++) {
                for ($j = $i + 2; $j < count($route); $j++) {
                    $distanceActuelle = $this->calculerDistanceSegment($route, $i, $j, $hq);

                    $nouvelleRoute = $this->inverserSegment($route, $i + 1, $j);

                    $nouvelleDistance = $this->calculerDistanceSegment($nouvelleRoute, $i, $j, $hq);

                    if ($nouvelleDistance < $distanceActuelle) {
                        $route = $nouvelleRoute;
                        $ameliore = true;
                        break;
                    }
                }

                if ($ameliore) {
                    break;
                }
            }
        }

        return $route;
    }

    /**
     * Distance d'un segment de la tournée — port de
     * calculateSegmentDistance(), CORRIGÉ (voir docblock de classe) :
     * aucune jambe de retour au QG quand $j est le dernier arrêt — la
     * tournée s'arrête simplement là, elle ne boucle pas.
     */
    private function calculerDistanceSegment(array $route, int $i, int $j, array $hq): float
    {
        $distance = 0.0;

        if ($i === 0) {
            $distance += $this->geo->distanceHaversine(
                $hq['lat'], $hq['lng'],
                $route[0]['latitude'], $route[0]['longitude'],
            );
        } else {
            $distance += $this->geo->distanceHaversine(
                $route[$i - 1]['latitude'], $route[$i - 1]['longitude'],
                $route[$i]['latitude'], $route[$i]['longitude'],
            );
        }

        for ($k = $i; $k < $j; $k++) {
            $distance += $this->geo->distanceHaversine(
                $route[$k]['latitude'], $route[$k]['longitude'],
                $route[$k + 1]['latitude'], $route[$k + 1]['longitude'],
            );
        }

        // CORRIGÉ : plus de "else { ... retour au QG }" ici — si $j est le
        // dernier arrêt, la tournée s'arrête, rien à ajouter.
        if ($j < count($route) - 1) {
            $distance += $this->geo->distanceHaversine(
                $route[$j]['latitude'], $route[$j]['longitude'],
                $route[$j + 1]['latitude'], $route[$j + 1]['longitude'],
            );
        }

        return $distance;
    }

    /**
     * Inverse route[start..end] (inclusif) — port de reverseSegment().
     */
    private function inverserSegment(array $route, int $start, int $end): array
    {
        while ($start < $end) {
            [$route[$start], $route[$end]] = [$route[$end], $route[$start]];
            $start++;
            $end--;
        }

        return $route;
    }
}
