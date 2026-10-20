<?php
// tests/Feature/Http/RetourQgReponseTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Campagne;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Support\Creneau;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/** 18/10/2026 — le « Non » à « Revenez-vous au QG ? » est enregistré ; « Remettre dans le pool » (gestionnaire). */
class RetourQgReponseTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
    }

    private function route(int $idBenevole, string $statut = 'livraisons_terminees', ?Campagne $campagne = null): RouteLivraison
    {
        $campagne ??= Campagne::create(['type' => 'zakat_el_fitr', 'statut' => 'en_cours', 'date_livraison' => '2026-11-10']);

        return RouteLivraison::create([
            'id_campagne' => $campagne->id,
            'id_benevole' => $idBenevole,
            'id_vehicule_type' => 1,
            'creneau' => Creneau::MATIN_1,
            'statut' => $statut,
        ]);
    }

    public function test_le_non_est_enregistre_sans_terminer_la_tournee(): void
    {
        $chauffeur = $this->creerPersonne(['benevole']);
        $route = $this->route($chauffeur->id);

        $this->actingAs($chauffeur)
            ->postJson(route('livraison.benevole.routes.retour-qg', $route), ['retour' => false])
            ->assertOk()
            ->assertJson(['success' => true, 'retour' => false]);

        $route->refresh();
        $this->assertFalse($route->retour_qg);
        $this->assertSame('livraisons_terminees', $route->statut);
        $this->assertDatabaseMissing('benevole_retours_qg', ['id_route_origine' => $route->id]);
    }

    public function test_sans_champ_retour_la_reponse_reste_un_oui(): void
    {
        $chauffeur = $this->creerPersonne(['benevole']);
        $route = $this->route($chauffeur->id);

        $this->actingAs($chauffeur)
            ->postJson(route('livraison.benevole.routes.retour-qg', $route))
            ->assertOk()
            ->assertJson(['retour' => true]);

        $this->assertTrue($route->fresh()->retour_qg);
        $this->assertSame('terminee', $route->fresh()->statut);
    }

    public function test_un_oui_tardif_est_possible_apres_un_non(): void
    {
        $chauffeur = $this->creerPersonne(['benevole']);
        $route = $this->route($chauffeur->id);

        $this->actingAs($chauffeur)->postJson(route('livraison.benevole.routes.retour-qg', $route), ['retour' => false])->assertOk();
        $this->actingAs($chauffeur)->postJson(route('livraison.benevole.routes.retour-qg', $route), ['retour' => true])->assertOk();

        $this->assertSame('terminee', $route->fresh()->statut);
        $this->assertDatabaseHas('benevole_retours_qg', ['id_route_origine' => $route->id, 'id_personne' => $chauffeur->id]);
    }

    public function test_la_reponse_est_refusee_tant_que_la_tournee_est_en_cours(): void
    {
        $chauffeur = $this->creerPersonne(['benevole']);
        $route = $this->route($chauffeur->id, 'en_cours');

        $this->actingAs($chauffeur)
            ->postJson(route('livraison.benevole.routes.retour-qg', $route), ['retour' => false])
            ->assertStatus(422);

        $this->assertNull($route->fresh()->retour_qg);
    }

    public function test_un_non_leve_un_incident_pour_la_tournee_a_venir_du_chauffeur(): void
    {
        $chauffeur = $this->creerPersonne(['benevole']);
        $terminee = $this->route($chauffeur->id);
        $aVenir = $this->route($chauffeur->id, 'planifiee', $terminee->campagne);

        $this->actingAs($chauffeur)
            ->postJson(route('livraison.benevole.routes.retour-qg', $terminee), ['retour' => false])
            ->assertOk();

        $this->assertDatabaseHas('route_incidents', [
            'id_route' => $aVenir->id,
            'type' => 'chauffeur_indisponible',
            'statut' => 'ouvert',
        ]);
        $this->assertSame(1, RouteIncident::where('type', 'chauffeur_indisponible')->count());
    }

    public function test_un_autre_benevole_ne_peut_pas_repondre_a_la_place_du_chauffeur(): void
    {
        $chauffeur = $this->creerPersonne(['benevole']);
        $autre = $this->creerPersonne(['benevole']);
        $route = $this->route($chauffeur->id);

        $this->actingAs($autre)
            ->postJson(route('livraison.benevole.routes.retour-qg', $route), ['retour' => false])
            ->assertStatus(422);

        $this->assertNull($route->fresh()->retour_qg);
    }

    public function test_un_gestionnaire_remet_un_chauffeur_dans_le_pool(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $chauffeur = $this->creerPersonne(['benevole']);
        $route = $this->route($chauffeur->id);
        $this->actingAs($chauffeur)->postJson(route('livraison.benevole.routes.retour-qg', $route), ['retour' => false])->assertOk();

        $this->actingAs($gestionnaire)
            ->postJson(route('livraison.routes.remettre-dans-le-pool', $route))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue($route->fresh()->retour_qg);
        $this->assertDatabaseHas('benevole_retours_qg', ['id_route_origine' => $route->id, 'id_personne' => $chauffeur->id]);
    }

    public function test_remettre_dans_le_pool_est_refuse_sans_reponse_non(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $route = $this->route($this->creerPersonne(['benevole'])->id);

        $this->actingAs($gestionnaire)
            ->postJson(route('livraison.routes.remettre-dans-le-pool', $route))
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_un_benevole_ne_peut_pas_remettre_un_chauffeur_dans_le_pool(): void
    {
        $chauffeur = $this->creerPersonne(['benevole']);
        $route = $this->route($chauffeur->id);
        $this->actingAs($chauffeur)->postJson(route('livraison.benevole.routes.retour-qg', $route), ['retour' => false])->assertOk();

        $this->actingAs($chauffeur)
            ->postJson(route('livraison.routes.remettre-dans-le-pool', $route))
            ->assertRedirect(); // le middleware de rôle renvoie un non-gestionnaire vers l'accueil (302), pas un 403

        $this->assertFalse($route->fresh()->retour_qg);
    }

    public function test_rouvrir_une_tournee_efface_la_reponse_au_retour(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $chauffeur = $this->creerPersonne(['benevole']);
        $route = $this->route($chauffeur->id);
        $this->actingAs($chauffeur)->postJson(route('livraison.benevole.routes.retour-qg', $route), ['retour' => true])->assertOk();

        $this->actingAs($admin)->postJson(route('livraison.routes.rouvrir', $route))->assertOk();

        $route->refresh();
        $this->assertSame('livraisons_terminees', $route->statut);
        $this->assertNull($route->retour_qg);
        $this->assertNull($route->retour_qg_le);
    }
}
