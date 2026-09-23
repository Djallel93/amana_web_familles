<?php
// tests/Unit/Services/GeoCalculationServiceTest.php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\GeoCalculationService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests, no DB — GeoCalculationService takes/returns plain
 * arrays and floats only (see its class docblock).
 */
class GeoCalculationServiceTest extends TestCase
{
    private GeoCalculationService $geo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geo = new GeoCalculationService();
    }

    public function test_distance_haversine_between_identical_points_is_zero(): void
    {
        $this->assertSame(0.0, $this->geo->distanceHaversine(47.2184, -1.5536, 47.2184, -1.5536));
    }

    public function test_distance_haversine_matches_known_reference_distance(): void
    {
        // Nantes (47.2184, -1.5536) to Paris (48.8566, 2.3522) — ~343 km
        // great-circle distance, widely published as a reference figure.
        $distance = $this->geo->distanceHaversine(47.2184, -1.5536, 48.8566, 2.3522);

        $this->assertEqualsWithDelta(343.0, $distance, 5.0);
    }

    public function test_diametre_maximum_returns_zero_for_fewer_than_two_points(): void
    {
        $this->assertSame(0.0, $this->geo->diametreMaximum([], []));
        $this->assertSame(0.0, $this->geo->diametreMaximum([['latitude' => 47.2, 'longitude' => -1.5]], []));
    }

    public function test_diametre_maximum_is_the_largest_pairwise_distance_across_both_sets(): void
    {
        $existantes = [['latitude' => 47.2184, 'longitude' => -1.5536]];
        $nouvelles = [
            ['latitude' => 47.2184, 'longitude' => -1.5536], // same point, distance 0
            ['latitude' => 48.8566, 'longitude' => 2.3522],  // far point
        ];

        $diametre = $this->geo->diametreMaximum($existantes, $nouvelles);

        $this->assertEqualsWithDelta(343.0, $diametre, 5.0);
    }

    public function test_diametre_cluster_delegates_to_diametre_maximum_with_no_additions(): void
    {
        $livraisons = [
            ['latitude' => 47.2184, 'longitude' => -1.5536],
            ['latitude' => 47.2200, 'longitude' => -1.5500],
        ];

        $this->assertSame(
            $this->geo->diametreMaximum($livraisons, []),
            $this->geo->diametreCluster($livraisons),
        );
    }

    public function test_compacite_is_one_for_fewer_than_two_points(): void
    {
        $this->assertSame(1.0, $this->geo->compacite([]));
        $this->assertSame(1.0, $this->geo->compacite([['latitude' => 47.2, 'longitude' => -1.5]]));
    }

    public function test_compacite_is_one_when_all_points_are_equidistant_from_centre(): void
    {
        // Four points symmetric around (0,0): every point is equidistant
        // from the centroid, so mean/max distance == 1.
        $livraisons = [
            ['latitude' => 0.01, 'longitude' => 0.0],
            ['latitude' => -0.01, 'longitude' => 0.0],
            ['latitude' => 0.0, 'longitude' => 0.01],
            ['latitude' => 0.0, 'longitude' => -0.01],
        ];

        $this->assertEqualsWithDelta(1.0, $this->geo->compacite($livraisons), 0.001);
    }

    public function test_compacite_is_lower_for_a_stretched_out_set_of_points(): void
    {
        // One point far from a tight trio: mean distance to centroid is
        // well below the max distance, so compactness should be < 1.
        $livraisons = [
            ['latitude' => 47.2184, 'longitude' => -1.5536],
            ['latitude' => 47.2185, 'longitude' => -1.5537],
            ['latitude' => 47.2186, 'longitude' => -1.5538],
            ['latitude' => 48.8566, 'longitude' => 2.3522],
        ];

        $this->assertLessThan(1.0, $this->geo->compacite($livraisons));
        $this->assertGreaterThan(0.0, $this->geo->compacite($livraisons));
    }

    public function test_centre_is_the_barycentre_of_all_points(): void
    {
        $livraisons = [
            ['latitude' => 0.0, 'longitude' => 0.0],
            ['latitude' => 2.0, 'longitude' => 4.0],
        ];

        $this->assertSame(['lat' => 1.0, 'lng' => 2.0], $this->geo->centre($livraisons));
    }

    public function test_centre_of_empty_set_is_zero_zero(): void
    {
        $this->assertSame(['lat' => 0.0, 'lng' => 0.0], $this->geo->centre([]));
    }

    public function test_distance_totale_route_is_zero_for_no_livraisons(): void
    {
        $this->assertSame(0.0, $this->geo->distanceTotaleRoute([], ['lat' => 47.2, 'lng' => -1.5]));
    }

    /**
     * Regression (31/08/2026 docblock): NO HQ-return leg — the total is
     * HQ→stop1→stop2→...→stopN, and must NOT add a final stopN→HQ leg.
     * This locks in the fix for the divergent legacy bug the class
     * docblock describes (routeTspOptimization.js still closed the loop
     * for the last segment; routeGeometryService.js didn't).
     */
    public function test_distance_totale_route_excludes_the_hq_return_leg(): void
    {
        $hq = ['lat' => 0.0, 'lng' => 0.0];
        // Three points on the same meridian, each 1 degree of latitude
        // apart (~111.2 km/degree at the equator) — easy to reason about.
        $livraisons = [
            ['latitude' => 1.0, 'longitude' => 0.0],
            ['latitude' => 2.0, 'longitude' => 0.0],
            ['latitude' => 3.0, 'longitude' => 0.0],
        ];

        $distanceAvecRetour = $this->geo->distanceHaversine(0.0, 0.0, 1.0, 0.0)
            + $this->geo->distanceHaversine(1.0, 0.0, 2.0, 0.0)
            + $this->geo->distanceHaversine(2.0, 0.0, 3.0, 0.0)
            + $this->geo->distanceHaversine(3.0, 0.0, 0.0, 0.0); // the leg that must NOT be included

        $distance = $this->geo->distanceTotaleRoute($livraisons, $hq);

        $this->assertLessThan($distanceAvecRetour, $distance);
        // HQ→1 + 1→2 + 2→3, no 3→HQ.
        $attendue = $this->geo->distanceHaversine(0.0, 0.0, 1.0, 0.0)
            + $this->geo->distanceHaversine(1.0, 0.0, 2.0, 0.0)
            + $this->geo->distanceHaversine(2.0, 0.0, 3.0, 0.0);
        $this->assertEqualsWithDelta($attendue, $distance, 0.0001);
    }

    public function test_construire_lien_maps_returns_empty_string_for_no_livraisons(): void
    {
        $this->assertSame('', $this->geo->construireLienMaps([], ['lat' => 0.0, 'lng' => 0.0]));
    }

    /**
     * Regression companion to distanceTotaleRoute(): the Maps link's
     * destination is the LAST livraison, not the HQ — no return-to-HQ leg
     * in the generated link either.
     */
    public function test_construire_lien_maps_uses_last_livraison_as_destination_not_hq(): void
    {
        $hq = ['lat' => 0.0, 'lng' => 0.0];
        $livraisons = [
            ['latitude' => 1.0, 'longitude' => 0.0],
            ['latitude' => 2.0, 'longitude' => 0.0],
        ];

        $lien = $this->geo->construireLienMaps($livraisons, $hq);

        $this->assertStringContainsString('origin=0,0', $lien);
        $this->assertStringContainsString('destination=2,0', $lien);
        $this->assertStringContainsString('waypoints=1,0', $lien);
        $this->assertStringNotContainsString('destination=0,0', $lien);
    }
}
