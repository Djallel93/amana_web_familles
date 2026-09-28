<?php
// tests/Unit/Services/ClusteringServiceTest.php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Amana\Shared\Models\Setting;
use App\Services\ClusteringService;
use App\Services\GeoCalculationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unlike the other Phase 1 services, ClusteringService is NOT reachable
 * without a DB: identifierClusters()/grouperParBatiment() always read
 * RouteOptimizationConfig::distanceProximiteKm()/maxClusterDiameterKm()/
 * sameBuildingThresholdKm()/quartierPreference()/allowCrossQuartier()
 * (minCompactnessRatio() too), each backed by Amana\Shared\Models\
 * Setting::get() against the `commun` connection — there's no parameter
 * to bypass this the way ClusterSplitService/VehicleAssignmentService's
 * $maxLivraisonsParRoute can be passed explicitly. So this extends
 * Tests\TestCase (migrated commun connection) rather than plain PHPUnit
 * TestCase, and relies on the defaults the app's own migrations seed
 * (database/migrations/2026_08_31_000300_seed_route_optimization_settings.php):
 * distance_proximite=2.5km, max_cluster_diameter=5km,
 * same_building_threshold=50m, quartier_preference=true,
 * allow_cross_quartier=true, min_compactness_ratio=0.4.
 *
 * Setting has its own static in-process cache (Setting::$cache) that
 * survives RefreshDatabase's per-test transaction rollback — every test
 * here clears it in setUp()/whenever it overrides a setting, so no test
 * can leak a stale cached value into another.
 */
class ClusteringServiceTest extends TestCase
{
    private ClusteringService $clustering;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::clearCache();
        $this->clustering = new ClusteringService(new GeoCalculationService());
    }

    private function livraison(
        int $id,
        float $lat,
        float $lng,
        float $poidsKg = 10.0,
        int $parts = 1,
        ?int $quartierId = null,
    ): array {
        return [
            'id_livraison' => $id,
            'latitude' => $lat,
            'longitude' => $lng,
            'nombre_personnes' => $parts,
            'poids_kg' => $poidsKg,
            'id_quartier' => $quartierId,
        ];
    }

    private function surchargerReglage(string $cle, string $valeur): void
    {
        $connexion = config('amana-shared.connection', 'commun');
        $idApplication = DB::connection($connexion)->table('ref_applications')->where('code', 'familles')->value('id');

        DB::connection($connexion)->table('ref_settings')
            ->where('id_application', $idApplication)
            ->where('cle', $cle)
            ->update(['valeur' => $valeur]);

        Setting::clearCache();
    }

    public function test_deux_livraisons_au_meme_batiment_sont_regroupees_avant_le_clustering(): void
    {
        $hq = ['lat' => 0.0, 'lng' => 0.0];
        // A few metres apart — well under the 50m same-building threshold.
        $livraisons = [
            $this->livraison(1, 47.21840, -1.55360, poidsKg: 10.0),
            $this->livraison(2, 47.21841, -1.55361, poidsKg: 15.0),
        ];

        $clusters = $this->clustering->identifierClusters($livraisons, $hq, plafondPoidsKg: 1000.0);

        $this->assertCount(1, $clusters);
        $this->assertSame(2, $clusters[0]['nombre_livraisons']);
        $this->assertEqualsWithDelta(25.0, $clusters[0]['poids_total'], 0.01);
    }

    public function test_deux_livraisons_eloignees_restent_dans_des_clusters_distincts(): void
    {
        $hq = ['lat' => 0.0, 'lng' => 0.0];
        // ~11km apart (0.1° latitude) — well beyond the 2.5km proximity
        // threshold, so they can never merge into the same cluster.
        $livraisons = [
            $this->livraison(1, 0.0, 0.0),
            $this->livraison(2, 0.1, 0.0),
        ];

        $clusters = $this->clustering->identifierClusters($livraisons, $hq, plafondPoidsKg: 1000.0);

        $this->assertCount(2, $clusters);
    }

    public function test_deux_groupes_proches_fusionnent_dans_le_meme_cluster(): void
    {
        $hq = ['lat' => 0.0, 'lng' => 0.0];
        // ~555m apart: beyond the 50m same-building threshold (two
        // distinct groups), but well under the 2.5km proximity threshold
        // (should merge into one cluster during identifierClusters()).
        $livraisons = [
            $this->livraison(1, 0.0, 0.0),
            $this->livraison(2, 0.005, 0.0),
        ];

        $clusters = $this->clustering->identifierClusters($livraisons, $hq, plafondPoidsKg: 1000.0);

        $this->assertCount(1, $clusters);
        $this->assertSame(2, $clusters[0]['nombre_livraisons']);
    }

    /**
     * Regression/coverage for the 31/08/2026 addition (class docblock,
     * vérification 4): a weight ceiling checked AT MERGE TIME, not only
     * at vehicle assignment — two otherwise-mergeable groups (close,
     * compact, same quartier) must stay in separate clusters when their
     * combined weight would exceed $plafondPoidsKg.
     */
    public function test_le_plafond_de_poids_empeche_la_fusion_meme_si_geographiquement_proches(): void
    {
        $hq = ['lat' => 0.0, 'lng' => 0.0];
        $livraisons = [
            $this->livraison(1, 0.0, 0.0, poidsKg: 30.0),
            $this->livraison(2, 0.005, 0.0, poidsKg: 30.0), // ~555m away, would otherwise merge
        ];

        $clusters = $this->clustering->identifierClusters($livraisons, $hq, plafondPoidsKg: 40.0);

        $this->assertCount(2, $clusters, 'Combined weight (60kg) exceeds the 40kg ceiling — must not merge');
    }

    public function test_deux_groupes_de_quartiers_differents_ne_fusionnent_pas_quand_linter_quartier_est_desactive(): void
    {
        $this->surchargerReglage('route_allow_cross_quartier', '0');

        $hq = ['lat' => 0.0, 'lng' => 0.0];
        $livraisons = [
            $this->livraison(1, 0.0, 0.0, quartierId: 1),
            $this->livraison(2, 0.005, 0.0, quartierId: 2), // close, but different quartier
        ];

        $clusters = $this->clustering->identifierClusters($livraisons, $hq, plafondPoidsKg: 1000.0);

        $this->assertCount(2, $clusters);
    }

    public function test_clusters_sont_tries_par_distance_au_qg_decroissante(): void
    {
        $hq = ['lat' => 0.0, 'lng' => 0.0];
        // Three far-apart points (each pair > 2.5km) so every livraison
        // stays its own cluster, at three clearly different distances
        // from HQ.
        $livraisons = [
            $this->livraison(1, 0.05, 0.0),  // closest to HQ
            $this->livraison(2, 0.50, 0.0),  // farthest from HQ
            $this->livraison(3, 0.20, 0.0),  // middle
        ];

        $clusters = $this->clustering->identifierClusters($livraisons, $hq, plafondPoidsKg: 1000.0);

        $this->assertCount(3, $clusters);
        $distances = array_column($clusters, 'distance_hq');
        $trie = $distances;
        rsort($trie);
        $this->assertSame($trie, $distances, 'Clusters must be sorted by distance_hq descending');
        // Farthest (livraison 2) must be first.
        $this->assertSame(2, $clusters[0]['livraisons'][0]['id_livraison']);
    }
}
