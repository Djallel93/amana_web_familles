<?php
// tests/Unit/Services/TspOptimizationServiceTest.php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\GeoCalculationService;
use App\Services\TspOptimizationService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests, no DB — TspOptimizationService only depends on
 * GeoCalculationService (also pure).
 */
class TspOptimizationServiceTest extends TestCase
{
    private TspOptimizationService $tsp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tsp = new TspOptimizationService(new GeoCalculationService());
    }

    public function test_optimiser_returns_empty_array_for_no_livraisons(): void
    {
        $this->assertSame([], $this->tsp->optimiser([], ['lat' => 0.0, 'lng' => 0.0]));
    }

    public function test_optimiser_returns_the_single_livraison_unchanged(): void
    {
        $livraisons = [['id_livraison' => 1, 'latitude' => 1.0, 'longitude' => 1.0]];

        $this->assertSame($livraisons, $this->tsp->optimiser($livraisons, ['lat' => 0.0, 'lng' => 0.0]));
    }

    /**
     * Nearest-neighbor construction alone (< 4 stops skips 2-opt entirely,
     * see ameliorationDeuxOpt()'s early return) — starting from HQ at
     * (0,0), the closest stop should be visited first.
     */
    public function test_optimiser_visits_nearest_stop_first(): void
    {
        $hq = ['lat' => 0.0, 'lng' => 0.0];
        $proche = ['id_livraison' => 1, 'latitude' => 1.0, 'longitude' => 0.0];
        $loin = ['id_livraison' => 2, 'latitude' => 5.0, 'longitude' => 0.0];

        $route = $this->tsp->optimiser([$loin, $proche], $hq);

        $this->assertSame(1, $route[0]['id_livraison']);
        $this->assertSame(2, $route[1]['id_livraison']);
    }

    /**
     * Regression (31/08/2026 docblock): the 2-opt objective must NOT
     * include a return-to-HQ leg for the last segment — locks in the fix
     * for the legacy divergence (routeTspOptimization.js's own
     * calculateSegmentDistance() still closed the loop; this port
     * deliberately doesn't, matching routeGeometryService.js).
     *
     * Construct 4 points forming an obvious "crossed" nearest-neighbor
     * route that 2-opt should uncross — a zigzag along one axis, offset
     * on a second axis so nearest-neighbor (which only looks one step
     * ahead) picks a crossing order that 2-opt can improve.
     */
    public function test_optimiser_improves_a_crossed_route_via_two_opt(): void
    {
        $hq = ['lat' => 0.0, 'lng' => 0.0];

        // Points laid out so the greedy nearest-neighbor pass, started
        // from HQ, produces a visibly suboptimal (crossing) order that a
        // correct 2-opt pass shortens.
        $a = ['id_livraison' => 'A', 'latitude' => 1.0, 'longitude' => 0.0];
        $b = ['id_livraison' => 'B', 'latitude' => 1.0, 'longitude' => 3.0];
        $c = ['id_livraison' => 'C', 'latitude' => 0.9, 'longitude' => 1.0];
        $d = ['id_livraison' => 'D', 'latitude' => 0.9, 'longitude' => 2.0];

        $geo = new GeoCalculationService();
        $route = $this->tsp->optimiser([$a, $b, $c, $d], $hq);

        $this->assertCount(4, $route);

        $distanceFinale = $geo->distanceTotaleRoute($route, $hq);
        // Every permutation's total (no-HQ-return) distance — the
        // optimized route must be the shortest (or tied) among all of
        // them, not merely different from nearest-neighbor's raw output.
        $meilleure = null;
        foreach ($this->permutations([$a, $b, $c, $d]) as $permutation) {
            $distance = $geo->distanceTotaleRoute($permutation, $hq);
            if ($meilleure === null || $distance < $meilleure) {
                $meilleure = $distance;
            }
        }

        $this->assertEqualsWithDelta($meilleure, $distanceFinale, 0.0001);
    }

    /**
     * @return iterable<int, array>
     */
    private function permutations(array $items): iterable
    {
        if (count($items) <= 1) {
            yield $items;

            return;
        }

        foreach ($items as $i => $item) {
            $reste = $items;
            unset($reste[$i]);
            foreach ($this->permutations(array_values($reste)) as $permutationReste) {
                yield [$item, ...$permutationReste];
            }
        }
    }
}
