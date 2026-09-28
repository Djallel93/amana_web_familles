<?php
// tests/Unit/Services/ClusterSplitServiceTest.php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ClusterSplitService;
use App\Services\GeoCalculationService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests, no DB — scinder() always receives an explicit
 * $maxLivraisonsParRoute in every test here, so RouteOptimizationConfig
 * (which hits the `commun` DB via Setting::get()) is never reached; the
 * `??=` fallback in ClusterSplitService::scinder() only fires when that
 * argument is omitted.
 */
class ClusterSplitServiceTest extends TestCase
{
    private ClusterSplitService $splitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->splitter = new ClusterSplitService(new GeoCalculationService());
    }

    private function livraison(int $id, float $lat, float $lng, float $poids = 10.0, int $parts = 1): array
    {
        return ['id_livraison' => $id, 'latitude' => $lat, 'longitude' => $lng, 'nombre_personnes' => $parts, 'poids_kg' => $poids];
    }

    /**
     * Regression for the explicit design decision (31/08/2026 docblock):
     * prefer the subset using the MOST livraisons first, then break ties
     * by minimum diameter — NOT the legacy's list-order fill. Three
     * livraisons fit two-at-a-time by weight; the geographically tight
     * pair (not the first two in list order) must be the one retained.
     */
    public function test_scinder_prefers_max_count_then_min_diameter_not_list_order(): void
    {
        // Capacity for exactly 2 of these 10kg livraisons (20kg / 2 parts).
        $vehicule = ['capacite_kg' => 20.0, 'nombre_part_max' => 2];

        // Listed in an order where the FIRST two (id 1, 2) are far apart,
        // and the geographically tight pair (2, 3) comes later in the
        // array — a list-order split would wrongly retain [1, 2].
        $cluster = [
            'livraisons' => [
                $this->livraison(1, 0.0, 0.0),
                $this->livraison(2, 10.0, 10.0), // far from 1
                $this->livraison(3, 10.01, 10.01), // very close to 2
            ],
        ];

        $resultat = $this->splitter->scinder($cluster, $vehicule, maxLivraisonsParRoute: 15);

        $idsRetenus = array_column($resultat['retenu'], 'id_livraison');
        sort($idsRetenus);
        $this->assertSame([2, 3], $idsRetenus, 'Expected the geographically tight pair (2,3), not list order (1,2)');
        $this->assertSame([1], array_column($resultat['reste'], 'id_livraison'));
    }

    public function test_scinder_prefers_more_livraisons_over_a_smaller_tighter_subset(): void
    {
        // Capacity fits either 1 far-apart pair (weight-wise) or all 3 if
        // weight allows — here all 3 fit both weight and parts, so the
        // "most livraisons" rule should retain all 3 even though a
        // 2-subset would have a smaller diameter.
        $vehicule = ['capacite_kg' => 30.0, 'nombre_part_max' => 3];

        $cluster = [
            'livraisons' => [
                $this->livraison(1, 0.0, 0.0),
                $this->livraison(2, 0.001, 0.001),
                $this->livraison(3, 10.0, 10.0), // far outlier
            ],
        ];

        $resultat = $this->splitter->scinder($cluster, $vehicule, maxLivraisonsParRoute: 15);

        $idsRetenus = array_column($resultat['retenu'], 'id_livraison');
        sort($idsRetenus);
        $this->assertSame([1, 2, 3], $idsRetenus);
        $this->assertSame([], $resultat['reste']);
    }

    public function test_scinder_respects_the_max_livraisons_par_route_cap(): void
    {
        $vehicule = ['capacite_kg' => 1000.0, 'nombre_part_max' => 100];

        $cluster = [
            'livraisons' => [
                $this->livraison(1, 0.0, 0.0),
                $this->livraison(2, 0.001, 0.001),
                $this->livraison(3, 0.002, 0.002),
            ],
        ];

        $resultat = $this->splitter->scinder($cluster, $vehicule, maxLivraisonsParRoute: 2);

        $this->assertCount(2, $resultat['retenu']);
        $this->assertCount(1, $resultat['reste']);
    }

    public function test_scinder_returns_nothing_retained_when_even_one_livraison_exceeds_capacity(): void
    {
        $vehicule = ['capacite_kg' => 5.0, 'nombre_part_max' => 1];

        $cluster = [
            'livraisons' => [
                $this->livraison(1, 0.0, 0.0, poids: 10.0),
            ],
        ];

        $resultat = $this->splitter->scinder($cluster, $vehicule, maxLivraisonsParRoute: 15);

        $this->assertSame([], $resultat['retenu']);
        $this->assertSame([1], array_column($resultat['reste'], 'id_livraison'));
    }

    public function test_scinder_respects_the_parts_cap_as_well_as_weight(): void
    {
        // Weight easily fits both, but nombre_part_max only allows 1 part
        // total, and each livraison alone carries 2 parts — so neither
        // livraison individually fits either.
        $vehicule = ['capacite_kg' => 100.0, 'nombre_part_max' => 1];

        $cluster = [
            'livraisons' => [
                $this->livraison(1, 0.0, 0.0, poids: 5.0, parts: 2),
                $this->livraison(2, 0.001, 0.001, poids: 5.0, parts: 2),
            ],
        ];

        $resultat = $this->splitter->scinder($cluster, $vehicule, maxLivraisonsParRoute: 15);

        $this->assertSame([], $resultat['retenu']);
        $this->assertCount(2, $resultat['reste']);
    }
}
