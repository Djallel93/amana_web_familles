<?php
// tests/Feature/Services/RouteMutationRepartirTest.php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Campagne;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteLivraison;
use App\Services\GeoCalculationService;
use App\Services\RouteMutationService;
use App\Services\TspOptimizationService;
use App\Support\Creneau;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/** 18/10/2026 — répartition d'une tournée entre plusieurs chauffeurs (incident « chauffeur indisponible »). */
class RouteMutationRepartirTest extends TestCase
{
    use SeedsCommunFixtures;

    private RouteMutationService $mutation;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $geo = new GeoCalculationService();
        $this->mutation = new RouteMutationService(new TspOptimizationService($geo), $geo);
        $this->chargerRolesFamilles();
    }

    // ── partitionnerPoids() : pure ───────────────────────────────────────

    public function test_la_partition_coupe_en_troncons_contigus_au_prorata_des_capacites(): void
    {
        $this->assertSame([[0, 1], [2, 3]], RouteMutationService::partitionnerPoids([5, 5, 5, 5], [100, 100]));
        $this->assertSame([[0, 1], [2, 3, 4, 5]], RouteMutationService::partitionnerPoids([1, 1, 1, 1, 1, 1], [100, 200]));
    }

    public function test_chaque_chauffeur_recoit_au_moins_un_arret(): void
    {
        $this->assertSame([[0], [1], [2, 3]], RouteMutationService::partitionnerPoids([10, 1, 1, 1], [100, 100, 100]));
        $this->assertSame([[0], [1]], RouteMutationService::partitionnerPoids([0, 0], [100, 100]));
    }

    public function test_la_partition_refuse_moins_d_arrets_que_de_chauffeurs(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RouteMutationService::partitionnerPoids([5], [100, 100]);
    }

    // ── repartir() ───────────────────────────────────────────────────────

    private function creerCampagne(): Campagne
    {
        return Campagne::create([
            'type' => 'zakat_el_fitr',
            'statut' => 'en_cours',
            'date_livraison' => now()->addWeek()->toDateString(),
            'hq_latitude' => 0.0,
            'hq_longitude' => 0.0,
        ]);
    }

    private function routeAvecArrets(Campagne $campagne, int $nbArrets, string $statut = 'planifiee'): RouteLivraison
    {
        $route = RouteLivraison::create([
            'id_campagne' => $campagne->id,
            'id_benevole' => $this->creerPersonne(['benevole'])->id,
            'id_vehicule_type' => 1,
            'creneau' => Creneau::MATIN_1,
            'statut' => $statut,
        ]);

        for ($i = 1; $i <= $nbArrets; $i++) {
            $famille = Famille::factory()->create();
            $famille->latitude = 0.01 * $i;
            $famille->longitude = 0.01 * $i;
            $famille->save();

            $livraison = Livraison::create([
                'id_famille' => $famille->id,
                'id_campagne' => $campagne->id,
                'statut' => 'assignee',
                'statut_conditionnement' => 'en_attente',
                'nombre_personnes' => 1,
                'poids_kg' => 10.0,
                'statut_contact' => 'confirme',
            ]);
            EtapeRoute::create(['id_route' => $route->id, 'id_livraison' => $livraison->id, 'ordre' => $i, 'statut' => 'en_attente']);
        }

        return $route;
    }

    /** @return array<int, array{id_benevole: int, id_vehicule_type: int, capacite_kg: float}> */
    private function chauffeurs(int $n): array
    {
        $liste = [];
        for ($i = 0; $i < $n; $i++) {
            $liste[] = ['id_benevole' => $this->creerPersonne(['benevole'])->id, 'id_vehicule_type' => 2, 'capacite_kg' => 200.0];
        }

        return $liste;
    }

    public function test_repartir_donne_la_premiere_part_a_la_tournee_existante_et_cree_les_autres(): void
    {
        $route = $this->routeAvecArrets($this->creerCampagne(), 4);
        $chauffeurs = $this->chauffeurs(2);

        $routes = $this->mutation->repartir($route, $chauffeurs);

        $this->assertCount(2, $routes);
        $this->assertSame($route->id, $routes[0]->id);
        $this->assertSame($chauffeurs[0]['id_benevole'], $routes[0]->id_benevole);
        $this->assertSame($chauffeurs[1]['id_benevole'], $routes[1]->id_benevole);
        $this->assertNotSame($route->id, $routes[1]->id);
        $this->assertSame(2, $routes[0]->etapes()->count());
        $this->assertSame(2, $routes[1]->etapes()->count());
        $this->assertSame([1, 2], $routes[1]->etapes()->orderBy('ordre')->pluck('ordre')->all());
        $this->assertSame('planifiee', $routes[1]->statut);
        $this->assertSame(Creneau::MATIN_1, $routes[1]->creneau);
        $this->assertSame(2, $routes[1]->id_vehicule_type);
    }

    public function test_aucune_livraison_n_est_perdue_ni_dupliquee(): void
    {
        $route = $this->routeAvecArrets($this->creerCampagne(), 5);
        $avant = $route->etapes()->pluck('id_livraison')->sort()->values()->all();

        $routes = $this->mutation->repartir($route, $this->chauffeurs(3));

        $apres = collect($routes)->flatMap(fn(RouteLivraison $r) => $r->etapes()->pluck('id_livraison'))->sort()->values()->all();
        $this->assertSame($avant, $apres);
    }

    public function test_repartir_est_refuse_pour_une_tournee_deja_partie(): void
    {
        $route = $this->routeAvecArrets($this->creerCampagne(), 4, 'en_cours');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('déjà partie');

        $this->mutation->repartir($route, $this->chauffeurs(2));
    }

    public function test_repartir_est_refuse_avec_un_seul_chauffeur(): void
    {
        $route = $this->routeAvecArrets($this->creerCampagne(), 4);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('au moins deux chauffeurs');

        $this->mutation->repartir($route, $this->chauffeurs(1));
    }

    public function test_repartir_est_refuse_avec_moins_d_arrets_que_de_chauffeurs(): void
    {
        $route = $this->routeAvecArrets($this->creerCampagne(), 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('trop peu d\'arrêts');

        $this->mutation->repartir($route, $this->chauffeurs(2));
    }
}
