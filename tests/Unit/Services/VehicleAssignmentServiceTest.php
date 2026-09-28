<?php
// tests/Unit/Services/VehicleAssignmentServiceTest.php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ClusterSplitService;
use App\Services\GeoCalculationService;
use App\Services\VehicleAssignmentService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests, no DB — assigner() always receives an explicit
 * $maxLivraisonsParRoute in every test here (see ClusterSplitServiceTest's
 * docblock for why this keeps RouteOptimizationConfig's DB-backed
 * fallback out of the picture).
 */
class VehicleAssignmentServiceTest extends TestCase
{
    private VehicleAssignmentService $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $geo = new GeoCalculationService();
        $this->assignment = new VehicleAssignmentService(new ClusterSplitService($geo), $geo);
    }

    /**
     * @param array<int, array{id_livraison: int, poids_kg?: float, nombre_personnes?: int}> $livraisons
     */
    private function cluster(string $id, array $livraisons, ?int $quartierId = null): array
    {
        return [
            'id' => $id,
            'centre' => ['lat' => 0.0, 'lng' => 0.0],
            'livraisons' => array_map(fn (array $l) => array_merge([
                'latitude' => 0.0, 'longitude' => 0.0, 'nombre_personnes' => 1, 'poids_kg' => 10.0,
            ], $l), $livraisons),
            'quartier_id' => $quartierId,
            'distance_hq' => 1.0,
            'nombre_livraisons' => count($livraisons),
            'nombre_parts' => array_sum(array_column($livraisons, 'nombre_personnes')) ?: count($livraisons),
            'poids_total' => array_sum(array_column($livraisons, 'poids_kg')) ?: count($livraisons) * 10.0,
        ];
    }

    /**
     * Best-fit: among all vehicles that can take the cluster, the
     * SMALLEST sufficient one is chosen, not the first or the largest.
     */
    public function test_assigner_picks_the_smallest_sufficient_vehicle_best_fit(): void
    {
        $cluster = $this->cluster('C1', [['id_livraison' => 1, 'poids_kg' => 10.0]]);

        $vehicules = [
            ['id_benevole' => 1, 'id_vehicule_type' => 1, 'capacite_kg' => 500.0, 'nombre_part_max' => 10],
            ['id_benevole' => 2, 'id_vehicule_type' => 2, 'capacite_kg' => 20.0, 'nombre_part_max' => 10], // smallest sufficient
            ['id_benevole' => 3, 'id_vehicule_type' => 3, 'capacite_kg' => 100.0, 'nombre_part_max' => 10],
        ];

        $resultat = $this->assignment->assigner([$cluster], $vehicules, maxLivraisonsParRoute: 15);

        $this->assertCount(1, $resultat['assignations']);
        $this->assertSame(2, $resultat['assignations'][0]['vehicule']['id_benevole']);
        $this->assertSame([], $resultat['non_places']);
    }

    /**
     * Each vehicle gets at most ONE route per call (docblock: "chaque
     * véhicule reçoit au plus UNE tournée par appel").
     */
    public function test_assigner_never_assigns_more_than_one_cluster_to_the_same_vehicle(): void
    {
        $c1 = $this->cluster('C1', [['id_livraison' => 1, 'poids_kg' => 5.0]]);
        $c2 = $this->cluster('C2', [['id_livraison' => 2, 'poids_kg' => 5.0]]);

        $vehicules = [
            ['id_benevole' => 1, 'id_vehicule_type' => 1, 'capacite_kg' => 500.0, 'nombre_part_max' => 10],
        ];

        $resultat = $this->assignment->assigner([$c1, $c2], $vehicules, maxLivraisonsParRoute: 15);

        $this->assertCount(1, $resultat['assignations']);
        $benevolesUtilises = array_column(array_column($resultat['assignations'], 'vehicule'), 'id_benevole');
        $this->assertSame(array_unique($benevolesUtilises), $benevolesUtilises);
        // The second cluster couldn't be placed since the only vehicle is used.
        $this->assertCount(1, $resultat['non_places']);
    }

    /**
     * No single vehicle fits the cluster alone → falls back to splitting
     * via the largest free vehicle (ClusterSplitService), retaining what
     * fits and requeuing the remainder.
     */
    public function test_assigner_splits_via_largest_free_vehicle_when_none_fits_alone(): void
    {
        // Total weight 30kg — no single vehicle below has capacity for
        // both livraisons together (max capacity is 20kg), so a split is
        // required; the retained sub-cluster must respect that vehicle's
        // capacity.
        $cluster = $this->cluster('C1', [
            ['id_livraison' => 1, 'poids_kg' => 15.0],
            ['id_livraison' => 2, 'poids_kg' => 15.0],
        ]);

        $vehicules = [
            ['id_benevole' => 1, 'id_vehicule_type' => 1, 'capacite_kg' => 20.0, 'nombre_part_max' => 10],
        ];

        $resultat = $this->assignment->assigner([$cluster], $vehicules, maxLivraisonsParRoute: 15);

        $this->assertCount(1, $resultat['assignations']);
        $sousCluster = $resultat['assignations'][0]['cluster'];
        $this->assertLessThanOrEqual(20.0, $sousCluster['poids_total']);
        $this->assertCount(1, $sousCluster['livraisons']);
        // The remainder (the other 15kg livraison) couldn't be placed —
        // no more vehicles left.
        $this->assertCount(1, $resultat['non_places']);
    }

    /**
     * Even the largest free vehicle can't take a single oversized
     * livraison — it's reported in non_places rather than silently
     * creating an empty route (docblock: explicitly NOT the legacy
     * behavior).
     */
    public function test_assigner_reports_non_places_when_even_the_largest_vehicle_cannot_help(): void
    {
        $cluster = $this->cluster('C1', [
            ['id_livraison' => 1, 'poids_kg' => 100.0],
        ]);

        $vehicules = [
            ['id_benevole' => 1, 'id_vehicule_type' => 1, 'capacite_kg' => 10.0, 'nombre_part_max' => 10],
        ];

        $resultat = $this->assignment->assigner([$cluster], $vehicules, maxLivraisonsParRoute: 15);

        $this->assertSame([], $resultat['assignations']);
        $this->assertCount(1, $resultat['non_places']);
        $this->assertSame(1, $resultat['non_places'][0]['livraisons'][0]['id_livraison']);
    }

    public function test_assigner_rejects_a_cluster_exceeding_max_livraisons_par_route(): void
    {
        $cluster = $this->cluster('C1', [
            ['id_livraison' => 1],
            ['id_livraison' => 2],
            ['id_livraison' => 3],
        ]);

        $vehicules = [
            ['id_benevole' => 1, 'id_vehicule_type' => 1, 'capacite_kg' => 500.0, 'nombre_part_max' => 100],
        ];

        // Cap of 2 stops/route, cluster has 3 — vehiculeCompatible() must
        // reject it outright (not just weight/parts).
        $resultat = $this->assignment->assigner([$cluster], $vehicules, maxLivraisonsParRoute: 2);

        // No vehicle can take the whole 3-livraison cluster directly, and
        // the split path still yields sub-clusters capped at 2 (scinder()
        // receives the same cap), so at least one part remains unplaced
        // or split off — key assertion: no assignation exceeds the cap.
        foreach ($resultat['assignations'] as $assignation) {
            $this->assertLessThanOrEqual(2, $assignation['cluster']['nombre_livraisons']);
        }
    }
}
