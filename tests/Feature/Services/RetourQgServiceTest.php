<?php
// tests/Feature/Services/RetourQgServiceTest.php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\BenevoleRetourQg;
use App\Models\Campagne;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Services\RetourQgService;
use App\Support\Creneau;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/** 18/10/2026 — réponse « Revenez-vous au QG ? » : le « Non » est enregistré et peut lever un incident. */
class RetourQgServiceTest extends TestCase
{
    use SeedsCommunFixtures;

    private RetourQgService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
        $this->service = app(RetourQgService::class);
    }

    private function campagne(): Campagne
    {
        return Campagne::create(['type' => 'zakat_el_fitr', 'statut' => 'en_cours', 'date_livraison' => '2026-11-10']);
    }

    private function route(Campagne $campagne, int $idBenevole, string $statut): RouteLivraison
    {
        return RouteLivraison::create([
            'id_campagne' => $campagne->id,
            'id_benevole' => $idBenevole,
            'id_vehicule_type' => 1,
            'creneau' => Creneau::MATIN_1,
            'statut' => $statut,
        ]);
    }

    public function test_le_non_est_enregistre_et_la_tournee_reste_livraisons_terminees(): void
    {
        $campagne = $this->campagne();
        $route = $this->route($campagne, $this->creerPersonne(['benevole'])->id, 'livraisons_terminees');

        $this->service->enregistrer($route, false);

        $route->refresh();
        $this->assertFalse($route->retour_qg);
        $this->assertNotNull($route->retour_qg_le);
        $this->assertSame('livraisons_terminees', $route->statut);
        $this->assertSame(0, BenevoleRetourQg::count());
    }

    public function test_un_non_sans_autre_tournee_ne_leve_aucun_incident(): void
    {
        $campagne = $this->campagne();
        $route = $this->route($campagne, $this->creerPersonne(['benevole'])->id, 'livraisons_terminees');

        $this->service->enregistrer($route, false);

        $this->assertSame(0, RouteIncident::where('type', 'chauffeur_indisponible')->count());
    }

    public function test_un_non_leve_un_incident_par_tournee_pas_encore_partie_du_chauffeur(): void
    {
        $campagne = $this->campagne();
        $idBenevole = $this->creerPersonne(['benevole'])->id;
        $terminee = $this->route($campagne, $idBenevole, 'livraisons_terminees');
        $aVenir = $this->route($campagne, $idBenevole, 'planifiee');
        $chargee = $this->route($campagne, $idBenevole, 'charge');
        $this->route($campagne, $idBenevole, 'en_cours');
        $this->route($campagne, $this->creerPersonne(['benevole'])->id, 'planifiee'); // autre chauffeur

        $this->service->enregistrer($terminee, false);

        $incidents = RouteIncident::where('type', 'chauffeur_indisponible')->orderBy('id_route')->get();
        $this->assertSame([$aVenir->id, $chargee->id], $incidents->pluck('id_route')->all());
        $this->assertSame(['ouvert', 'ouvert'], $incidents->pluck('statut')->all());
    }

    public function test_un_second_non_ne_duplique_pas_l_incident(): void
    {
        $campagne = $this->campagne();
        $idBenevole = $this->creerPersonne(['benevole'])->id;
        $terminee = $this->route($campagne, $idBenevole, 'livraisons_terminees');
        $this->route($campagne, $idBenevole, 'planifiee');

        $this->service->enregistrer($terminee, false);
        $this->service->enregistrer($terminee->fresh(), false);

        $this->assertSame(1, RouteIncident::where('type', 'chauffeur_indisponible')->count());
    }

    public function test_le_oui_termine_la_tournee_et_remet_le_chauffeur_disponible(): void
    {
        $campagne = $this->campagne();
        $idBenevole = $this->creerPersonne(['benevole'])->id;
        $route = $this->route($campagne, $idBenevole, 'livraisons_terminees');

        $this->service->enregistrer($route, true);

        $route->refresh();
        $this->assertTrue($route->retour_qg);
        $this->assertSame('terminee', $route->statut);
        $this->assertDatabaseHas('benevole_retours_qg', ['id_personne' => $idBenevole, 'id_route_origine' => $route->id]);
    }

    public function test_un_oui_tardif_apres_un_non_resout_les_incidents_du_chauffeur(): void
    {
        $campagne = $this->campagne();
        $idBenevole = $this->creerPersonne(['benevole'])->id;
        $terminee = $this->route($campagne, $idBenevole, 'livraisons_terminees');
        $this->route($campagne, $idBenevole, 'planifiee');
        $this->service->enregistrer($terminee, false);

        $this->service->enregistrer($terminee->fresh(), true);

        $incident = RouteIncident::where('type', 'chauffeur_indisponible')->firstOrFail();
        $this->assertSame('resolu', $incident->statut);
        $this->assertSame('terminee', $terminee->fresh()->statut);
    }

    public function test_remettre_dans_le_pool_est_refuse_sans_reponse_non(): void
    {
        $campagne = $this->campagne();
        $route = $this->route($campagne, $this->creerPersonne(['benevole'])->id, 'livraisons_terminees');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Non');

        $this->service->remettreDansLePool($route);
    }

    public function test_remettre_dans_le_pool_apres_un_non_equivaut_a_un_oui(): void
    {
        $campagne = $this->campagne();
        $route = $this->route($campagne, $this->creerPersonne(['benevole'])->id, 'livraisons_terminees');
        $this->service->enregistrer($route, false);

        $this->service->remettreDansLePool($route->fresh());

        $this->assertTrue($route->fresh()->retour_qg);
        $this->assertSame('terminee', $route->fresh()->statut);
    }

    public function test_la_reponse_est_refusee_tant_que_les_livraisons_ne_sont_pas_terminees(): void
    {
        $campagne = $this->campagne();
        $route = $this->route($campagne, $this->creerPersonne(['benevole'])->id, 'en_cours');

        $this->expectException(\RuntimeException::class);

        $this->service->enregistrer($route, false);
    }
}
