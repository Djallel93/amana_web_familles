<?php
// tests/Feature/Http/IncidentsReouvertureEtSuiteTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\BenevoleDisponibilite;
use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Notifications\RouteIncidentNotification;
use App\Support\Creneau;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsDisponibiliteFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * 09/10/2026 — Incidents : réouverture (sauf campagne terminée) et suite à
 * donner à la famille d'une « livraison ignorée » / d'un « retrait QG non livré ».
 */
class IncidentsReouvertureEtSuiteTest extends TestCase
{
    use BuildsDisponibiliteFixtures;
    use SeedsCommunFixtures;

    private Campagne $campagne;

    private CampagneJournee $journee;

    /** @var array{voiture: int, utilitaire: int, sans_permis: int, non_vehicule: int} */
    private array $vehicules;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
        $this->vehicules = $this->creerVehicules();
        [$this->campagne, $this->journee] = $this->creerCampagneAvecJournee();
    }

    private function gestionnaire()
    {
        return $this->creerPersonne(['gestionnaire']);
    }

    private function route(string $statut = 'planifiee'): RouteLivraison
    {
        return RouteLivraison::create([
            'id_campagne' => $this->campagne->id,
            'id_benevole' => $this->creerBenevole($this->vehicules['voiture'])->id,
            'id_vehicule_type' => $this->vehicules['voiture'],
            'creneau' => Creneau::MATIN_1,
            'statut' => $statut,
        ]);
    }

    private function livraison(array $surcharge = []): Livraison
    {
        return Livraison::create(array_merge([
            'id_famille' => Famille::factory()->create(['latitude' => 48.85, 'longitude' => 2.35, 'email' => 'f' . uniqid() . '@amana-test.fr'])->id,
            'id_campagne' => $this->campagne->id,
            'id_campagne_journee' => $this->journee->id,
            'statut' => 'ignoree',
            'statut_contact' => 'confirme',
            'nombre_personnes' => 2,
            'poids_kg' => 10.0,
        ], $surcharge));
    }

    private function incident(string $type, ?RouteLivraison $route = null, ?Livraison $livraison = null, string $statut = 'ouvert'): RouteIncident
    {
        return RouteIncident::withoutEvents(fn() => RouteIncident::create([
            'id_route' => $route?->id,
            'id_campagne' => $route === null ? $this->campagne->id : null,
            'type' => $type,
            'id_livraison' => $livraison?->id,
            'signale_par' => $route?->id_benevole ?? $this->creerPersonne(['benevole'])->id,
            'statut' => $statut,
        ]));
    }

    /** Incident « livraison ignorée » complet : famille ignorée dans une tournée en cours. */
    private function livraisonIgnoree(): array
    {
        $route = $this->route('en_cours');
        $livraison = $this->livraison();
        EtapeRoute::create(['id_route' => $route->id, 'id_livraison' => $livraison->id, 'ordre' => 1, 'statut' => 'ignoree']);

        return [$this->incident('livraison_ignoree', $route, $livraison), $livraison, $route];
    }

    private function confirmerChauffeur(int $idPersonne): void
    {
        $dispo = BenevoleDisponibilite::create([
            'id_personne' => $idPersonne,
            'id_campagne_journee' => $this->journee->id,
            'statut' => 'confirme',
        ]);
        $dispo->creneaux()->create(['creneau' => '08-10']);
    }

    // ── Rouvrir ──────────────────────────────────────────────────────────

    public function test_rouvrir_un_incident_resolu_ou_ignore(): void
    {
        foreach (['resolu', 'ignore'] as $statut) {
            $incident = $this->incident('capacite', $this->route(), null, $statut);

            $this->actingAs($this->gestionnaire())
                ->postJson(route('livraison.incidents.rouvrir', $incident))
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertSame('ouvert', $incident->fresh()->statut);
            $this->assertStringContainsString('Rouvert', $incident->fresh()->notes);
        }
    }

    public function test_rouvrir_previent_a_nouveau_les_gestionnaires(): void
    {
        $gestionnaire = $this->gestionnaire();
        $incident = $this->incident('capacite', $this->route(), null, 'resolu');

        $this->actingAs($gestionnaire)->postJson(route('livraison.incidents.rouvrir', $incident))->assertOk();

        // Un gestionnaire dans la base : une seule notification, recréée à la réouverture
        // (assertSentTo() indexe par classe exacte du notifiable, d'où assertSentTimes()).
        Notification::assertSentTimes(RouteIncidentNotification::class, 1);
    }

    public function test_rouvrir_un_incident_deja_ouvert_est_refuse(): void
    {
        $incident = $this->incident('capacite', $this->route());

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.incidents.rouvrir', $incident))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_rouvrir_est_refuse_quand_la_campagne_est_terminee(): void
    {
        $incident = $this->incident('capacite', $this->route(), null, 'resolu');
        $this->campagne->update(['statut' => 'terminee']);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.incidents.rouvrir', $incident))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('resolu', $incident->fresh()->statut);
    }

    public function test_rouvrir_un_incident_de_retrait_qg_refuse_aussi_sur_campagne_terminee(): void
    {
        $incident = $this->incident('retrait_hq_non_livre', null, $this->livraison(['se_deplace' => true]), 'ignore');
        $this->campagne->update(['statut' => 'terminee']);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.incidents.rouvrir', $incident))
            ->assertStatus(422);
    }

    public function test_rouvrir_un_benevole_absent_resolu_avertit_que_le_reclustering_nest_pas_defait(): void
    {
        $incident = $this->incident('benevole_absent', $this->route(), null, 'resolu');

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.incidents.rouvrir', $incident))
            ->assertOk()
            ->assertJsonStructure(['avertissement']);
    }

    // ── Suite : livraison ignorée ────────────────────────────────────────

    public function test_suite_reinitialiser_remet_la_famille_a_planifier_et_resout_lincident(): void
    {
        [$incident, $livraison] = $this->livraisonIgnoree();

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.incidents.resoudre-suite', $incident), ['action' => 'reinitialiser'])
            ->assertOk();

        $this->assertSame('non_assignee', $livraison->fresh()->statut);
        $this->assertSame('resolu', $incident->fresh()->statut);
    }

    public function test_suite_tournee_ajoute_la_famille_a_une_tournee_existante(): void
    {
        [$incident, $livraison] = $this->livraisonIgnoree();
        $cible = $this->route('planifiee');

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.incidents.resoudre-suite', $incident), ['action' => 'tournee', 'id_route' => $cible->id])
            ->assertOk();

        $this->assertSame('resolu', $incident->fresh()->statut);
        $this->assertSame(1, EtapeRoute::where('id_route', $cible->id)->where('id_livraison', $livraison->id)->count());
        $this->assertSame('assignee', $livraison->fresh()->statut);
    }

    public function test_suite_tournee_est_refusee_pour_une_tournee_chargee_ou_dune_autre_campagne(): void
    {
        [$incident, $livraison] = $this->livraisonIgnoree();
        $chargee = $this->route('charge');
        $autreCampagne = Campagne::create(['type' => 'zakat_el_fitr', 'statut' => 'preparation', 'date_livraison' => '2026-11-12', 'poids_moyen_kg' => 5]);
        $ailleurs = RouteLivraison::create([
            'id_campagne' => $autreCampagne->id,
            'id_benevole' => $this->creerBenevole($this->vehicules['voiture'])->id,
            'id_vehicule_type' => $this->vehicules['voiture'],
            'creneau' => Creneau::MATIN_1,
            'statut' => 'planifiee',
        ]);
        $gestionnaire = $this->gestionnaire();

        foreach ([$chargee, $ailleurs] as $route) {
            $this->actingAs($gestionnaire)
                ->postJson(route('livraison.incidents.resoudre-suite', $incident), ['action' => 'tournee', 'id_route' => $route->id])
                ->assertStatus(422)
                ->assertJsonPath('success', false);
        }

        $this->assertSame('ouvert', $incident->fresh()->statut);
        $this->assertSame('ignoree', $livraison->fresh()->statut);
    }

    public function test_suite_retrait_qg_bascule_la_famille_au_qg(): void
    {
        [$incident, $livraison] = $this->livraisonIgnoree();

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.incidents.resoudre-suite', $incident), ['action' => 'retrait_qg'])
            ->assertOk();

        $this->assertTrue($livraison->fresh()->se_deplace);
        $this->assertSame('resolu', $incident->fresh()->statut);
    }

    public function test_suite_chauffeur_impose_la_famille_a_un_chauffeur_confirme(): void
    {
        [$incident, $livraison] = $this->livraisonIgnoree();
        $chauffeur = $this->creerBenevole($this->vehicules['voiture']);
        $this->confirmerChauffeur($chauffeur->id);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.incidents.resoudre-suite', $incident), ['action' => 'chauffeur', 'id_benevole' => $chauffeur->id])
            ->assertOk();

        $this->assertSame($chauffeur->id, $livraison->fresh()->id_benevole_impose);
        $this->assertSame('resolu', $incident->fresh()->statut);
    }

    public function test_suite_chauffeur_refuse_un_benevole_non_confirme(): void
    {
        [$incident, $livraison] = $this->livraisonIgnoree();
        $chauffeur = $this->creerBenevole($this->vehicules['voiture']);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.incidents.resoudre-suite', $incident), ['action' => 'chauffeur', 'id_benevole' => $chauffeur->id])
            ->assertStatus(422);

        $this->assertNull($livraison->fresh()->id_benevole_impose);
        $this->assertSame('ouvert', $incident->fresh()->statut);
    }

    public function test_une_action_inconnue_ou_non_adaptee_au_type_est_refusee(): void
    {
        [$incident] = $this->livraisonIgnoree();
        $gestionnaire = $this->gestionnaire();

        $this->actingAs($gestionnaire)
            ->postJson(route('livraison.incidents.resoudre-suite', $incident), ['action' => 'nimporte_quoi'])
            ->assertStatus(422);
        // « reessayer » est une suite de retrait QG, pas de livraison ignorée.
        $this->postJson(route('livraison.incidents.resoudre-suite', $incident), ['action' => 'reessayer'])
            ->assertStatus(422);
        $this->assertSame('ouvert', $incident->fresh()->statut);
    }

    public function test_les_options_listent_les_tournees_pas_encore_parties_de_la_campagne(): void
    {
        [$incident, , $enCours] = $this->livraisonIgnoree();
        $planifiee = $this->route('planifiee');
        $this->route('charge');
        $this->route('terminee');

        $reponse = $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.incidents.options', $incident))
            ->assertOk()
            ->assertJsonPath('id_campagne', $this->campagne->id);

        $this->assertSame([$planifiee->id], collect($reponse->json('tournees'))->pluck('id')->all());
        $this->assertNotContains($enCours->id, collect($reponse->json('tournees'))->pluck('id')->all());
    }

    // ── Suite : retrait QG non livré ─────────────────────────────────────

    public function test_suite_reessayer_remet_le_retrait_a_prete(): void
    {
        $livraison = $this->livraison(['se_deplace' => true, 'statut' => 'non_assignee', 'statut_conditionnement' => 'prete', 'statut_retrait_hq' => 'non_delivre']);
        $incident = $this->incident('retrait_hq_non_livre', null, $livraison);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.incidents.resoudre-suite', $incident), ['action' => 'reessayer'])
            ->assertOk();

        $this->assertNull($livraison->fresh()->statut_retrait_hq);
        $this->assertSame('resolu', $incident->fresh()->statut);
    }

    public function test_suite_domicile_repasse_la_famille_en_livraison_a_domicile(): void
    {
        $livraison = $this->livraison(['se_deplace' => true, 'statut' => 'non_assignee', 'statut_conditionnement' => 'prete', 'statut_retrait_hq' => 'non_delivre']);
        $incident = $this->incident('retrait_hq_non_livre', null, $livraison);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.incidents.resoudre-suite', $incident), ['action' => 'domicile'])
            ->assertOk();

        $this->assertFalse($livraison->fresh()->se_deplace);
        $this->assertSame('resolu', $incident->fresh()->statut);
    }

    // ── Réservé aux gestionnaires ────────────────────────────────────────

    public function test_rouvrir_et_suites_sont_reserves_aux_gestionnaires(): void
    {
        [$incident] = $this->livraisonIgnoree();
        $benevole = $this->creerPersonne(['benevole']);

        // Middleware d'équipe : un non-gestionnaire est renvoyé à l'accueil (302), rien n'est modifié.
        $this->actingAs($benevole)->postJson(route('livraison.incidents.rouvrir', $incident))->assertRedirect();
        $this->postJson(route('livraison.incidents.resoudre-suite', $incident), ['action' => 'reinitialiser'])->assertRedirect();
        $this->getJson(route('livraison.incidents.options', $incident))->assertRedirect();
        $this->assertSame('ouvert', $incident->fresh()->statut);
    }
}
