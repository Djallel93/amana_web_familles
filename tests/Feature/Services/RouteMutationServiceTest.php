<?php
// tests/Feature/Services/RouteMutationServiceTest.php

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

/**
 * DB-backed: RouteLivraison/EtapeRoute/Campagne/Livraison are all real
 * Eloquent models here (no in-memory shortcuts, unlike the Phase 1
 * RouteGenerationServiceTest) since these methods persist their changes
 * (DB::transaction, ->update(), ->delete()) and the whole point is
 * verifying what actually lands in the database.
 */
class RouteMutationServiceTest extends TestCase
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

    private function creerCampagne(array $overrides = []): Campagne
    {
        return Campagne::create(array_merge([
            'type' => 'zakat_el_fitr',
            'statut' => 'en_cours',
            'date_livraison' => now()->addWeek()->toDateString(),
            'hq_latitude' => 0.0,
            'hq_longitude' => 0.0,
        ], $overrides));
    }

    private function creerLivraison(Campagne $campagne, float $lat = 0.0, float $lng = 0.0, array $overrides = []): Livraison
    {
        $famille = Famille::factory()->create();
        $famille->latitude = $lat;
        $famille->longitude = $lng;
        $famille->save();

        return Livraison::create(array_merge([
            'id_famille' => $famille->id,
            'id_campagne' => $campagne->id,
            'statut' => 'assignee',
            'statut_conditionnement' => 'en_attente',
            'nombre_personnes' => 1,
            'poids_kg' => 10.0,
            'statut_contact' => 'a_contacter',
        ], $overrides));
    }

    private function creerRoute(Campagne $campagne, int $idBenevole, array $overrides = []): RouteLivraison
    {
        return RouteLivraison::create(array_merge([
            'id_campagne' => $campagne->id,
            'id_benevole' => $idBenevole,
            'id_vehicule_type' => 1, // ref_vehicules.id, cross-DB, no FK — see migration comment
            'creneau' => Creneau::MATIN_1,
            'statut' => 'planifiee',
        ], $overrides));
    }

    private function creerEtape(RouteLivraison $route, Livraison $livraison, int $ordre, string $statut = 'en_attente'): EtapeRoute
    {
        return EtapeRoute::create([
            'id_route' => $route->id,
            'id_livraison' => $livraison->id,
            'ordre' => $ordre,
            'statut' => $statut,
        ]);
    }

    // ── supprimer() — Phase 2 brief: verify this is a SOFT-cancel ──────────

    public function test_supprimer_est_un_soft_cancel_pas_une_suppression_physique(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id);
        $livraison = $this->creerLivraison($campagne);
        $this->creerEtape($route, $livraison, 1);

        $this->mutation->supprimer($route);

        $this->assertDatabaseHas('routes', ['id' => $route->id, 'statut' => 'annulee']);
        // Still there — a soft-cancel, not a hard delete.
        $this->assertDatabaseHas('etapes_route', ['id_route' => $route->id]);
    }

    public function test_supprimer_remet_chaque_livraison_couverte_a_non_assignee(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id);
        $livraison1 = $this->creerLivraison($campagne);
        $livraison2 = $this->creerLivraison($campagne);
        $this->creerEtape($route, $livraison1, 1);
        $this->creerEtape($route, $livraison2, 2);

        $this->mutation->supprimer($route);

        $this->assertSame('non_assignee', $livraison1->fresh()->statut);
        $this->assertSame('non_assignee', $livraison2->fresh()->statut);
    }

    public function test_supprimer_refuse_une_tournee_qui_nest_plus_planifiee(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, ['statut' => 'chargement']);

        $this->expectException(\RuntimeException::class);

        $this->mutation->supprimer($route);
    }

    // ── changerStatutEtape() ────────────────────────────────────────────────

    public function test_changer_statut_etape_accepte_en_cours_meme_poste_jamais_par_le_benevole(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id);
        $livraison = $this->creerLivraison($campagne);
        $etape = $this->creerEtape($route, $livraison, 1);

        $resultat = $this->mutation->changerStatutEtape($etape, 'en_cours');

        $this->assertSame('en_cours', $resultat->statut);
        $this->assertDatabaseHas('etapes_route', ['id' => $etape->id, 'statut' => 'en_cours']);
    }

    public function test_changer_statut_etape_rejette_un_statut_invalide(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id);
        $livraison = $this->creerLivraison($campagne);
        $etape = $this->creerEtape($route, $livraison, 1);

        $this->expectException(\RuntimeException::class);

        $this->mutation->changerStatutEtape($etape, 'statut_qui_nexiste_pas');
    }

    // ── reassigner() ─────────────────────────────────────────────────────

    public function test_reassigner_met_a_jour_le_benevole_et_le_type_de_vehicule(): void
    {
        $ancienBenevole = $this->creerPersonne(['benevole']);
        $nouveauBenevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $ancienBenevole->id, ['id_vehicule_type' => 1]);

        $resultat = $this->mutation->reassigner($route, $nouveauBenevole->id, 2);

        $this->assertSame($nouveauBenevole->id, $resultat->id_benevole);
        $this->assertSame(2, $resultat->id_vehicule_type);
    }

    public function test_reassigner_ne_verifie_pas_la_capacite_du_nouveau_vehicule(): void
    {
        // Docblock: "ne vérifie PAS que la nouvelle capacité suffit" —
        // reassigner() must succeed even with an arbitrary/implausible
        // id_vehicule_type, since it's a manual admin action.
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id);

        $resultat = $this->mutation->reassigner($route, $benevole->id, 999999);

        $this->assertSame(999999, $resultat->id_vehicule_type);
    }

    // ── diviser() ────────────────────────────────────────────────────────

    public function test_diviser_cree_une_seconde_tournee_avec_le_meme_benevole(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, ['id_vehicule_type' => 5, 'creneau' => Creneau::MATIN_1]);

        $l1 = $this->creerLivraison($campagne, 0.0, 0.0);
        $l2 = $this->creerLivraison($campagne, 0.1, 0.0);
        $l3 = $this->creerLivraison($campagne, 0.2, 0.0);
        $l4 = $this->creerLivraison($campagne, 0.3, 0.0);
        $this->creerEtape($route, $l1, 1);
        $this->creerEtape($route, $l2, 2);
        $this->creerEtape($route, $l3, 3);
        $this->creerEtape($route, $l4, 4);

        $nouvelleRoute = $this->mutation->diviser($route);

        $this->assertSame($benevole->id, $nouvelleRoute->id_benevole);
        $this->assertSame(5, $nouvelleRoute->id_vehicule_type);
        $this->assertSame(Creneau::MATIN_1, $nouvelleRoute->creneau);
        // 4 stops split at intdiv(4,2)=2 — 2 remain on the original, 2 move.
        $this->assertCount(2, $route->fresh()->etapes);
        $this->assertCount(2, $nouvelleRoute->etapes);
    }

    public function test_diviser_refuse_une_tournee_avec_moins_de_deux_arrets(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id);
        $livraison = $this->creerLivraison($campagne);
        $this->creerEtape($route, $livraison, 1);

        $this->expectException(\RuntimeException::class);

        $this->mutation->diviser($route);
    }
}
