<?php
// tests/Feature/Http/RouteReouvertureEtVerrouTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\BenevoleRetourQg;
use App\Models\Campagne;
use App\Models\RouteLivraison;
use App\Services\RouteMutationService;
use App\Support\Creneau;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Lot du 11/10/2026 : réouverture d'une tournée terminée (admin uniquement) et verrou des
 * modifications de composition sur une tournée chargée (sauf admin).
 */
class RouteReouvertureEtVerrouTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
    }

    private function campagne(string $statut = 'en_cours'): Campagne
    {
        return Campagne::create([
            'type' => 'zakat_el_fitr',
            'statut' => $statut,
            'date_livraison' => now()->addWeek()->toDateString(),
            'hq_latitude' => 0.0,
            'hq_longitude' => 0.0,
        ]);
    }

    private function route(Campagne $campagne, string $statut): RouteLivraison
    {
        return RouteLivraison::create([
            'id_campagne' => $campagne->id,
            'id_benevole' => $this->creerPersonne(['benevole'])->id,
            'id_vehicule_type' => 1,
            'creneau' => Creneau::MATIN_1,
            'statut' => $statut,
        ]);
    }

    // ── Réouverture ──────────────────────────────────────────────────────

    public function test_un_admin_rouvre_une_tournee_terminee_et_le_retour_au_qg_est_efface(): void
    {
        $campagne = $this->campagne();
        $route = $this->route($campagne, 'terminee');
        BenevoleRetourQg::create([
            'id_campagne' => $campagne->id,
            'id_personne' => $route->id_benevole,
            'id_route_origine' => $route->id,
            'disponible_depuis' => now(),
        ]);

        $this->actingAs($this->creerPersonne(['admin']))
            ->postJson(route('livraison.routes.rouvrir', $route))
            ->assertOk();

        $this->assertDatabaseHas('routes', ['id' => $route->id, 'statut' => 'livraisons_terminees']);
        $this->assertSame(0, BenevoleRetourQg::where('id_route_origine', $route->id)->count());
    }

    public function test_un_gestionnaire_ne_peut_pas_rouvrir_une_tournee(): void
    {
        $route = $this->route($this->campagne(), 'terminee');

        // Le middleware role:admin redirige (302) plutôt que de répondre 403 : l'important est
        // que rien ne change.
        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->postJson(route('livraison.routes.rouvrir', $route))
            ->assertRedirect();

        $this->assertDatabaseHas('routes', ['id' => $route->id, 'statut' => 'terminee']);
    }

    public function test_on_ne_rouvre_qu_une_tournee_terminee(): void
    {
        $route = $this->route($this->campagne(), 'en_cours');

        $this->actingAs($this->creerPersonne(['admin']))
            ->postJson(route('livraison.routes.rouvrir', $route))
            ->assertStatus(422);
    }

    public function test_la_reouverture_est_refusee_quand_la_campagne_est_terminee(): void
    {
        $route = $this->route($this->campagne('terminee'), 'terminee');

        $this->actingAs($this->creerPersonne(['admin']))
            ->postJson(route('livraison.routes.rouvrir', $route))
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'La campagne est terminée : rouvrez-la avant de rouvrir une tournée.']);

        $this->assertDatabaseHas('routes', ['id' => $route->id, 'statut' => 'terminee']);
    }

    public function test_le_bouton_de_reouverture_n_apparait_que_pour_un_admin_sur_une_tournee_terminee(): void
    {
        $route = $this->route($this->campagne(), 'terminee');

        $this->actingAs($this->creerPersonne(['admin']))
            ->get(route('livraison.routes.vue-chauffeur', $route))
            ->assertOk()
            ->assertSee('onclick="rouvrirTournee(', false);

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->get(route('livraison.routes.vue-chauffeur', $route))
            ->assertOk()
            // Le titre « Rouvrir la tournée » existe aussi dans le script de la page : on teste le bouton.
            ->assertDontSee('onclick="rouvrirTournee(', false);
    }

    // ── Verrou des modifications de composition ──────────────────────────

    public function test_une_tournee_chargee_ou_plus_loin_n_est_modifiable_que_par_un_admin(): void
    {
        $service = app(RouteMutationService::class);
        $admin = $this->creerPersonne(['admin']);
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $campagne = $this->campagne();

        foreach (['charge', 'en_cours', 'livraisons_terminees', 'terminee'] as $statut) {
            $route = $this->route($campagne, $statut);

            $service->refuserSiVerrouillee($route, $admin);
            $this->addToAssertionCount(1);

            try {
                $service->refuserSiVerrouillee($route, $gestionnaire);
                $this->fail("Un gestionnaire ne doit pas modifier une tournée « {$statut} ».");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('seul un admin', $e->getMessage());
            }
        }
    }

    public function test_une_tournee_non_chargee_reste_modifiable_par_un_gestionnaire(): void
    {
        $service = app(RouteMutationService::class);
        $gestionnaire = $this->creerPersonne(['gestionnaire']);

        foreach (['planifiee', 'chargement', 'packaging_annule'] as $statut) {
            $service->refuserSiVerrouillee($this->route($this->campagne(), $statut), $gestionnaire);
            $this->addToAssertionCount(1);
        }
    }

    public function test_diviser_une_tournee_chargee_est_refuse_a_un_gestionnaire(): void
    {
        $route = $this->route($this->campagne(), 'charge');

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->postJson(route('livraison.chargement.routes.diviser', $route))
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }
}
