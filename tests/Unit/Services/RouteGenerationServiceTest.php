<?php
// tests/Unit/Services/RouteGenerationServiceTest.php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Famille;
use App\Models\Livraison;
use App\Models\LivraisonCreneau;
use App\Services\ClusteringService;
use App\Services\ClusterSplitService;
use App\Services\GeoCalculationService;
use App\Services\RouteGenerationService;
use App\Services\TspOptimizationService;
use App\Services\VehicleAssignmentService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Only prioriserInflexibles() is pure-logic-testable without a DB —
 * genererPourCampagne() and the rest of this 508-line class are DB/HTTP
 * heavy (RouteOptimizationConfig, Eloquent queries, persistence) and
 * belong in Phase 2/4 feature tests instead.
 *
 * prioriserInflexibles() is private, so this reaches it via Reflection —
 * a deliberate exception given the alternative (making it public just for
 * a test, or duplicating its logic in a public wrapper) is worse for a
 * method the class docblock explicitly flags as carrying a fixed
 * regression. Livraison/Famille instances below are constructed in memory
 * (never saved) with relations pre-set via setRelation() so no DB call is
 * made — RouteGenerationService's own constructor dependencies
 * (ClusteringService et al.) are likewise never DB-touched here since
 * their DB-hitting methods (identifierClusters() etc, via
 * RouteOptimizationConfig) are never called by prioriserInflexibles().
 */
class RouteGenerationServiceTest extends TestCase
{
    private RouteGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $geo = new GeoCalculationService();
        $this->service = new RouteGenerationService(
            new ClusteringService($geo),
            new VehicleAssignmentService(new ClusterSplitService($geo), $geo),
            new TspOptimizationService($geo),
            $geo,
        );
    }

    /**
     * @param int $nombreCreneaux 0 or 1 = inflexible; 2+ = flexible
     */
    private function livraison(float $poidsKg, int $criticite, int $nombreCreneaux): Livraison
    {
        $famille = new Famille(['criticite' => $criticite]);

        $livraison = new Livraison(['poids_kg' => $poidsKg]);
        $livraison->setRelation('famille', $famille);
        $livraison->setRelation('creneaux', new Collection(
            array_fill(0, $nombreCreneaux, new LivraisonCreneau()),
        ));

        return $livraison;
    }

    /**
     * @return Collection<int, Livraison>
     */
    private function invoke(Collection $pool, array $vehicules): Collection
    {
        $methode = (new ReflectionClass(RouteGenerationService::class))->getMethod('prioriserInflexibles');
        $methode->setAccessible(true);

        return $methode->invoke($this->service, $pool, $vehicules);
    }

    public function test_pool_is_returned_unchanged_when_total_weight_fits_capacity(): void
    {
        $pool = new Collection([
            $this->livraison(poidsKg: 5.0, criticite: 1, nombreCreneaux: 2),
            $this->livraison(poidsKg: 5.0, criticite: 5, nombreCreneaux: 1),
        ]);

        $resultat = $this->invoke($pool, [['capacite_kg' => 100.0]]);

        $this->assertCount(2, $resultat);
    }

    public function test_inflexible_livraisons_are_never_removed(): void
    {
        // Two inflexible (single-créneau) livraisons alone already exceed
        // capacity — they must both still be retained; only flexibles are
        // ever dropped.
        $inflexibleA = $this->livraison(poidsKg: 60.0, criticite: 1, nombreCreneaux: 1);
        $inflexibleB = $this->livraison(poidsKg: 60.0, criticite: 1, nombreCreneaux: 0);

        $pool = new Collection([$inflexibleA, $inflexibleB]);

        $resultat = $this->invoke($pool, [['capacite_kg' => 50.0]]);

        $this->assertTrue($resultat->contains($inflexibleA));
        $this->assertTrue($resultat->contains($inflexibleB));
    }

    /**
     * Regression (31/08/2026 docblock, "CORRECTION du 31/08/2026 :
     * sortByDesc, pas sortBy"): when the pool exceeds capacity, the MOST
     * critical flexible families (highest criticite) must be RETAINED,
     * and the least critical ones dropped first — the inverted bug this
     * locks in would have kept the least urgent and dropped the most
     * urgent instead.
     */
    public function test_most_critical_flexible_familles_are_retained_when_pool_exceeds_capacity(): void
    {
        // Capacity for exactly one 10kg flexible on top of a 0kg-weight
        // baseline (no inflexibles here) — only the single most critical
        // flexible family should survive.
        $peuCritique = $this->livraison(poidsKg: 10.0, criticite: 1, nombreCreneaux: 2);
        $moyenCritique = $this->livraison(poidsKg: 10.0, criticite: 5, nombreCreneaux: 2);
        $tresCritique = $this->livraison(poidsKg: 10.0, criticite: 9, nombreCreneaux: 2);

        $pool = new Collection([$peuCritique, $moyenCritique, $tresCritique]);

        $resultat = $this->invoke($pool, [['capacite_kg' => 10.0]]);

        $this->assertCount(1, $resultat);
        $this->assertTrue($resultat->contains($tresCritique), 'The most critical flexible family must be the one retained');
        $this->assertFalse($resultat->contains($peuCritique));
        $this->assertFalse($resultat->contains($moyenCritique));
    }

    public function test_mix_of_inflexibles_and_flexibles_keeps_all_inflexibles_and_the_most_critical_flexibles(): void
    {
        $inflexible = $this->livraison(poidsKg: 15.0, criticite: 1, nombreCreneaux: 1);
        $flexiblePeuCritique = $this->livraison(poidsKg: 10.0, criticite: 1, nombreCreneaux: 2);
        $flexibleTresCritique = $this->livraison(poidsKg: 10.0, criticite: 9, nombreCreneaux: 2);

        $pool = new Collection([$inflexible, $flexiblePeuCritique, $flexibleTresCritique]);

        // Capacity for the inflexible (15kg) + exactly one flexible (10kg) = 25kg.
        $resultat = $this->invoke($pool, [['capacite_kg' => 25.0]]);

        $this->assertTrue($resultat->contains($inflexible));
        $this->assertTrue($resultat->contains($flexibleTresCritique));
        $this->assertFalse($resultat->contains($flexiblePeuCritique));
    }
}
