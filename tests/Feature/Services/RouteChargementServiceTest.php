<?php
// tests/Feature/Services/RouteChargementServiceTest.php

declare(strict_types=1);

namespace Tests\Feature\Services;

use Amana\Shared\Models\Personne as PersonnePartagee;
use App\Models\Campagne;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteLivraison;
use App\Notifications\RoutePretePourChargementNotification;
use App\Services\GeoCalculationService;
use App\Services\RouteChargementService;
use App\Services\RouteMutationService;
use App\Services\TspOptimizationService;
use App\Support\Creneau;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Régression du 01/10/2026 : colis déjà tous prêts AVANT la génération des
 * tournées → les tournées restaient 'planifiee' pour toujours et l'écran
 * chargement n'affichait aucune ligne (seul le dernier colis coché
 * déclenchait la bascule en 'chargement').
 */
class RouteChargementServiceTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->chargerRolesFamilles();
    }

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

    private function creerLivraison(Campagne $campagne, string $conditionnement, string $statut = 'assignee'): Livraison
    {
        $famille = Famille::factory()->create();
        $famille->latitude = 0.01;
        $famille->longitude = 0.01;
        $famille->save();

        return Livraison::create([
            'id_famille' => $famille->id,
            'id_campagne' => $campagne->id,
            'statut' => $statut,
            'statut_conditionnement' => $conditionnement,
            'nombre_personnes' => 1,
            'poids_kg' => 10.0,
            'statut_contact' => 'confirme',
        ]);
    }

    private function creerRoute(Campagne $campagne, int $idBenevole, array $livraisons, string $statut = 'planifiee'): RouteLivraison
    {
        $route = RouteLivraison::create([
            'id_campagne' => $campagne->id,
            'id_benevole' => $idBenevole,
            'id_vehicule_type' => 1,
            'creneau' => Creneau::MATIN_1,
            'statut' => $statut,
        ]);

        foreach ($livraisons as $i => $livraison) {
            EtapeRoute::create([
                'id_route' => $route->id,
                'id_livraison' => $livraison->id,
                'ordre' => $i + 1,
                'statut' => 'en_attente',
            ]);
        }

        return $route;
    }

    public function test_tournee_dont_tous_les_colis_sont_prets_passe_en_chargement_et_notifie(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, [
            $this->creerLivraison($campagne, 'prete'),
            $this->creerLivraison($campagne, 'prete'),
        ]);

        $resultat = app(RouteChargementService::class)->promouvoirSiPrete($route);

        $this->assertTrue($resultat);
        $this->assertSame('chargement', $route->fresh()->statut);
        // assertSentTo() indexe par classe EXACTE du notifiable : le service
        // notifie Amana\Shared\Models\Personne (comme l'ancien code de
        // PackagingController), alors que creerPersonne() renvoie la
        // sous-classe App\Models\Personne — on recharge donc la personne via
        // la classe partagée.
        Notification::assertSentTo(
            PersonnePartagee::findOrFail($benevole->id),
            RoutePretePourChargementNotification::class,
        );
    }

    public function test_tournee_avec_un_colis_non_pret_reste_planifiee(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, [
            $this->creerLivraison($campagne, 'prete'),
            $this->creerLivraison($campagne, 'en_attente'),
        ]);

        $resultat = app(RouteChargementService::class)->promouvoirSiPrete($route);

        $this->assertFalse($resultat);
        $this->assertSame('planifiee', $route->fresh()->statut);
        Notification::assertNothingSent();
    }

    public function test_tournee_sans_etape_nest_jamais_promue(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $route = $this->creerRoute($this->creerCampagne(), $benevole->id, []);

        $this->assertFalse(app(RouteChargementService::class)->promouvoirSiPrete($route));
        $this->assertSame('planifiee', $route->fresh()->statut);
    }

    public function test_tournee_deja_en_cours_nest_pas_touchee(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, [
            $this->creerLivraison($campagne, 'prete'),
        ], 'en_cours');

        $this->assertFalse(app(RouteChargementService::class)->promouvoirSiPrete($route));
        $this->assertSame('en_cours', $route->fresh()->statut);
    }

    public function test_tournee_personnalisee_creee_apres_conditionnement_complet_est_directement_en_chargement(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $livraison1 = $this->creerLivraison($campagne, 'prete', 'non_assignee');
        $livraison2 = $this->creerLivraison($campagne, 'prete', 'non_assignee');

        $geo = new GeoCalculationService();
        $mutation = new RouteMutationService(new TspOptimizationService($geo), $geo);

        $route = $mutation->construirePersonnalisee(
            $campagne,
            $benevole->id,
            1,
            [$livraison1->id, $livraison2->id],
            Creneau::MATIN_1,
        );

        $this->assertSame('chargement', $route->statut);
    }

    public function test_commande_de_rattrapage_promeut_les_tournees_bloquees(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $bloquee = $this->creerRoute($campagne, $benevole->id, [$this->creerLivraison($campagne, 'prete')]);
        $enAttente = $this->creerRoute($campagne, $benevole->id, [$this->creerLivraison($campagne, 'en_attente')]);

        $this->artisan('livraison:promouvoir-tournees-pretes')
            ->expectsOutput('Tournées basculées en chargement : 1')
            ->assertExitCode(0);

        $this->assertSame('chargement', $bloquee->fresh()->statut);
        $this->assertSame('planifiee', $enAttente->fresh()->statut);
    }
}
