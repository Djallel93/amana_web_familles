<?php
// app/Services/VehicleAssignmentService.php

declare(strict_types=1);

namespace App\Services;

use App\Support\RouteOptimizationConfig;

/**
 * Assignation des clusters aux véhicules — port de
 * assignerClustersAuxVehicules()/chercherMeilleurBenevole()/
 * chercherPlusGrandBenevoleLibre()/vehiculeCompatible()
 * (routeAssignmentService.js, amana_livraison). Mode incrémental
 * identique : chaque véhicule reçoit au plus UNE tournée par appel
 * (= par créneau, dans RouteGenerationService), best-fit (plus petit
 * véhicule suffisant), et scission via le plus grand véhicule libre
 * quand aucun véhicule seul ne suffit.
 *
 * SEUL changement : splitCluster() (legacy, split par ordre de liste) est
 * remplacé par ClusterSplitService::scinder() (recherche exhaustive,
 * géographique — voir le prompt §3.3 point 3 et cette classe). Le reste
 * de la boucle (file de clusters, remise du reste en tête de file via
 * unshift, garde-fou anti-boucle infinie) est porté à l'identique.
 *
 * Ne construit PAS de "route" persistée ici (creerRouteDepuisCluster côté
 * legacy écrivait directement le résultat) — cette classe renvoie
 * seulement QUI est assigné à QUOI ; RouteGenerationService s'occupe du
 * TSP puis de la persistance (RouteLivraison/EtapeRoute), pour garder
 * chaque étape testable indépendamment.
 */
class VehicleAssignmentService
{
    public function __construct(
        private readonly ClusterSplitService $splitter,
        private readonly GeoCalculationService $geo,
    ) {
    }

    /**
     * @param array<int, array{id: string, centre: array, livraisons: array, quartier_id: ?int, distance_hq: float, nombre_livraisons: int, nombre_parts: int, poids_total: float}> $clusters Triés par distance_hq DESC
     * @param array<int, array{id_benevole: int, id_vehicule_type: int, capacite_kg: float, nombre_part_max: int}> $vehicules
     * @return array{assignations: array<int, array{cluster: array, vehicule: array}>, non_places: array<int, array>}
     */
    public function assigner(array $clusters, array $vehicules): array
    {
        $fileClusters = $clusters;
        $benevolesAvecRoute = [];
        $assignations = [];
        $nonPlaces = [];

        $maxLivraisonsParRoute = RouteOptimizationConfig::maxLivraisonsParRoute();

        $gardeFou = 0;
        $maxIterations = count($clusters) * 10 + count($vehicules) * 2;

        while (count($fileClusters) > 0) {
            // Tous les véhicules ont leur tournée — on arrête même s'il
            // reste des clusters (ils repartiront en shortfall ci-dessous).
            if (count($benevolesAvecRoute) >= count($vehicules)) {
                break;
            }

            if (++$gardeFou > $maxIterations) {
                break;
            }

            $cluster = array_shift($fileClusters);

            $candidat = $this->chercherMeilleurBenevole($cluster, $vehicules, $benevolesAvecRoute, $maxLivraisonsParRoute);

            if ($candidat !== null) {
                $assignations[] = ['cluster' => $cluster, 'vehicule' => $candidat];
                $benevolesAvecRoute[] = $candidat['id_benevole'];
                continue;
            }

            // Aucun véhicule libre ne peut prendre ce cluster seul → tentative de scission.
            $spliteur = $this->chercherPlusGrandBenevoleLibre($vehicules, $benevolesAvecRoute);

            if ($spliteur === null) {
                $nonPlaces[] = $cluster;
                continue;
            }

            $resultat = $this->splitter->scinder($cluster, $spliteur);

            if (count($resultat['retenu']) === 0) {
                // Même le plus grand véhicule libre ne peut rien prendre de
                // ce cluster (ex : une livraison isolée dépasse déjà sa
                // capacité à elle seule) — signalé en shortfall plutôt que
                // de créer une tournée vide (le legacy le faisait, jugé
                // comportement non désiré plutôt que fidèle au port).
                $nonPlaces[] = $cluster;
                $benevolesAvecRoute[] = $spliteur['id_benevole'];
                continue;
            }

            $sousClusterRetenu = $this->construireSousCluster($cluster, $resultat['retenu']);
            $assignations[] = ['cluster' => $sousClusterRetenu, 'vehicule' => $spliteur];
            $benevolesAvecRoute[] = $spliteur['id_benevole'];

            if (count($resultat['reste']) > 0) {
                $reste = $this->construireSousCluster($cluster, $resultat['reste']);
                array_unshift($fileClusters, $reste);
            }
        }

        // Tout ce qui reste en file après arrêt (véhicules épuisés ou
        // garde-fou atteint) est aussi un manque à couvrir pour ce créneau.
        $nonPlaces = [...$nonPlaces, ...$fileClusters];

        return ['assignations' => $assignations, 'non_places' => $nonPlaces];
    }

    /**
     * Plus petit véhicule disponible capable de gérer le cluster — port de
     * chercherMeilleurBenevole().
     */
    private function chercherMeilleurBenevole(array $cluster, array $vehicules, array $exclus, int $maxLivraisonsParRoute): ?array
    {
        $eligibles = array_values(array_filter(
            $vehicules,
            fn (array $v) => !in_array($v['id_benevole'], $exclus, true)
                && $this->vehiculeCompatible($v, $cluster, $maxLivraisonsParRoute),
        ));

        if (empty($eligibles)) {
            return null;
        }

        usort($eligibles, fn (array $a, array $b) => $a['capacite_kg'] <=> $b['capacite_kg']);

        return $eligibles[0];
    }

    /**
     * Véhicule disponible avec la plus grande capacité — port de
     * chercherPlusGrandBenevoleLibre().
     */
    private function chercherPlusGrandBenevoleLibre(array $vehicules, array $exclus): ?array
    {
        $disponibles = array_values(array_filter(
            $vehicules,
            fn (array $v) => !in_array($v['id_benevole'], $exclus, true),
        ));

        if (empty($disponibles)) {
            return null;
        }

        usort($disponibles, fn (array $a, array $b) => $b['capacite_kg'] <=> $a['capacite_kg']);

        return $disponibles[0];
    }

    /**
     * Port de vehiculeCompatible() — poids, parts, nombre d'arrêts.
     */
    private function vehiculeCompatible(array $vehicule, array $cluster, int $maxLivraisonsParRoute): bool
    {
        if ($vehicule['capacite_kg'] < $cluster['poids_total']) {
            return false;
        }
        if ($vehicule['nombre_part_max'] < $cluster['nombre_parts']) {
            return false;
        }
        if ($cluster['nombre_livraisons'] > $maxLivraisonsParRoute) {
            return false;
        }

        return true;
    }

    private function construireSousCluster(array $clusterOriginal, array $livraisons): array
    {
        return [
            'id' => $clusterOriginal['id'] . '_' . substr(md5(serialize(array_column($livraisons, 'id_livraison'))), 0, 6),
            'centre' => count($livraisons) > 0 ? $this->geo->centre($livraisons) : $clusterOriginal['centre'],
            'livraisons' => $livraisons,
            'quartier_id' => $clusterOriginal['quartier_id'],
            'distance_hq' => $clusterOriginal['distance_hq'],
            'nombre_livraisons' => count($livraisons),
            'nombre_parts' => array_sum(array_column($livraisons, 'nombre_personnes')),
            'poids_total' => array_sum(array_column($livraisons, 'poids_kg')),
        ];
    }
}
