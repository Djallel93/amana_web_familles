<?php
// tests/Feature/Services/LivraisonChangementServiceTest.php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Notifications\RetraitHqAnnuleNotification;
use App\Notifications\RetraitHqNotification;
use App\Services\LivraisonChangementService;
use App\Services\RetraitHqSchedulingService;
use App\Support\Creneau;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsDisponibiliteFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Changements EN COURS DE CAMPAGNE (06/10/2026) : se_deplace qui bascule,
 * « prendre en charge » (chauffeur imposé), confirmation tardive. Voir
 * LivraisonChangementService pour les règles (refus quand la tournée est
 * chargée/en cours ou la famille déjà livrée, rendez-vous QG recalculés et
 * emails, une famille ne peut pas être à la fois se_deplace et imposée).
 */
class LivraisonChangementServiceTest extends TestCase
{
    use BuildsDisponibiliteFixtures;
    use SeedsCommunFixtures;

    private LivraisonChangementService $service;

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
        $this->service = app(LivraisonChangementService::class);
    }

    private function livraison(array $surcharge = [], float $lat = 0.01, float $lng = 0.01): Livraison
    {
        $famille = Famille::factory()->create(['email' => 'famille' . uniqid() . '@amana-test.fr', 'langue' => 'fr']);
        $famille->latitude = $lat;
        $famille->longitude = $lng;
        $famille->save();

        return Livraison::create(array_merge([
            'id_famille' => $famille->id,
            'id_campagne' => $this->campagne->id,
            'id_campagne_journee' => $this->journee->id,
            'statut' => 'non_assignee',
            'statut_conditionnement' => 'en_attente',
            'nombre_personnes' => 2,
            'poids_kg' => 10.0,
            'statut_contact' => 'confirme',
            'se_deplace' => false,
        ], $surcharge));
    }

    private function route(string $statut = 'planifiee', ?int $idBenevole = null, ?string $creneau = Creneau::MATIN_1): RouteLivraison
    {
        return RouteLivraison::create([
            'id_campagne' => $this->campagne->id,
            'id_campagne_journee' => $this->journee->id,
            'id_benevole' => $idBenevole ?? $this->creerPersonne(['benevole'])->id,
            'id_vehicule_type' => $this->vehicules['voiture'],
            'creneau' => $creneau,
            'statut' => $statut,
        ]);
    }

    private function dansRoute(RouteLivraison $route, Livraison $livraison, string $statutEtape = 'en_attente'): EtapeRoute
    {
        $livraison->update(['statut' => 'assignee']);

        return EtapeRoute::create([
            'id_route' => $route->id,
            'id_livraison' => $livraison->id,
            'ordre' => 1,
            'statut' => $statutEtape,
        ]);
    }

    // ── se_deplace ───────────────────────────────────────────────────────

    public function test_se_deplace_vrai_retire_la_famille_de_sa_tournee_et_lui_donne_un_rendez_vous(): void
    {
        $route = $this->route();
        $livraison = $this->livraison();
        $etape = $this->dansRoute($route, $livraison);

        $this->service->changerSeDeplace($livraison, true);

        $livraison->refresh();
        $this->assertTrue($livraison->se_deplace);
        $this->assertSame('non_assignee', $livraison->statut);
        $this->assertNotNull($livraison->heure_arrivee_prevue_hq);
        $this->assertDatabaseMissing('etapes_route', ['id' => $etape->id]);
        // Campagne démarrée : la famille reçoit son rendez-vous par email.
        Notification::assertSentOnDemand(RetraitHqNotification::class);
    }

    public function test_se_deplace_vrai_est_refuse_quand_la_tournee_est_chargee(): void
    {
        $route = $this->route('charge');
        $livraison = $this->livraison();
        $etape = $this->dansRoute($route, $livraison);

        try {
            $this->service->changerSeDeplace($livraison, true);
            $this->fail('Un changement sur une tournée chargée doit être refusé.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tournée #' . $route->id, $e->getMessage());
        }

        $this->assertFalse($livraison->fresh()->se_deplace);
        $this->assertDatabaseHas('etapes_route', ['id' => $etape->id]);
        Notification::assertNothingSent();
    }

    public function test_se_deplace_vrai_est_refuse_quand_larret_est_deja_livre(): void
    {
        $route = $this->route('en_cours');
        $livraison = $this->livraison();
        $this->dansRoute($route, $livraison, 'livree');
        $livraison->update(['statut' => 'livree']);

        $this->expectException(\RuntimeException::class);
        $this->service->changerSeDeplace($livraison, true);
    }

    public function test_un_arret_ignore_ne_bloque_pas_le_passage_au_retrait_qg(): void
    {
        $route = $this->route('en_cours');
        $livraison = $this->livraison();
        $etape = $this->dansRoute($route, $livraison, 'ignoree');
        $livraison->update(['statut' => 'ignoree']);

        $this->service->changerSeDeplace($livraison, true);

        $this->assertTrue($livraison->fresh()->se_deplace);
        $this->assertSame('non_assignee', $livraison->fresh()->statut);
        // L'arrêt ignoré reste dans l'historique de sa tournée.
        $this->assertDatabaseHas('etapes_route', ['id' => $etape->id]);
    }

    public function test_se_deplace_est_refuse_pour_une_famille_imposee(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        $livraison = $this->livraison(['id_benevole_impose' => $benevole->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->changerSeDeplace($livraison, true);
    }

    public function test_se_deplace_faux_efface_le_rendez_vous_replanifie_les_autres_et_previent_les_familles(): void
    {
        $partante = $this->livraison(['se_deplace' => true]);
        $restante = $this->livraison(['se_deplace' => true]);
        // État de départ réaliste : planning posé.
        app(RetraitHqSchedulingService::class)->planifierPour($this->campagne, $this->journee);
        $this->assertNotNull($partante->fresh()->heure_arrivee_prevue_hq);
        $ancienneHeure = $restante->fresh()->heure_arrivee_prevue_hq;

        // Instance relue : comme dans le contrôleur (liaison de route), le rendez-vous est déjà en base.
        $this->service->changerSeDeplace($partante->fresh(), false);

        $partante->refresh();
        $this->assertFalse($partante->se_deplace);
        $this->assertNull($partante->heure_arrivee_prevue_hq);
        $this->assertNull($partante->statut_retrait_hq);
        $this->assertSame('non_assignee', $partante->statut);
        // Seule famille se_deplace restante : elle prend le début de la fenêtre.
        $this->assertTrue($restante->fresh()->heure_arrivee_prevue_hq->lessThanOrEqualTo($ancienneHeure));
        Notification::assertSentOnDemand(RetraitHqAnnuleNotification::class);
    }

    public function test_se_deplace_faux_est_refuse_quand_le_colis_a_deja_ete_remis_au_qg(): void
    {
        $livraison = $this->livraison(['se_deplace' => true, 'statut' => 'livree', 'statut_retrait_hq' => 'delivre']);

        $this->expectException(\RuntimeException::class);
        $this->service->changerSeDeplace($livraison, false);
    }

    public function test_pas_dannulation_envoyee_tant_que_la_campagne_nest_pas_demarree(): void
    {
        $this->campagne->update(['statut' => 'preparation']);
        $livraison = $this->livraison(['se_deplace' => true]);
        app(RetraitHqSchedulingService::class)->planifierPour($this->campagne, $this->journee);

        $this->service->changerSeDeplace($livraison->fresh(), false);

        Notification::assertNothingSent();
    }

    // ── Prendre en charge (chauffeur imposé) ─────────────────────────────

    public function test_prendre_en_charge_cree_la_tournee_imposee_sans_creneau(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        $livraison = $this->livraison();

        $this->service->prendreEnCharge($livraison, $benevole->id);

        $this->assertSame($benevole->id, $livraison->fresh()->id_benevole_impose);
        $this->assertSame('assignee', $livraison->fresh()->statut);
        $route = RouteLivraison::where('id_campagne', $this->campagne->id)->where('id_benevole', $benevole->id)->sole();
        $this->assertNull($route->creneau);
        $this->assertSame($this->vehicules['voiture'], $route->id_vehicule_type);
        $this->assertDatabaseHas('etapes_route', ['id_route' => $route->id, 'id_livraison' => $livraison->id]);
    }

    public function test_une_seconde_famille_rejoint_la_meme_tournee_imposee(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);

        $this->service->prendreEnCharge($this->livraison(), $benevole->id);
        $this->service->prendreEnCharge($this->livraison(), $benevole->id);

        $route = RouteLivraison::where('id_benevole', $benevole->id)->sole();
        $this->assertCount(2, $route->etapes);
    }

    public function test_prendre_en_charge_sort_la_famille_de_sa_tournee_classique(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        $ancienne = $this->route('planifiee');
        $livraison = $this->livraison();
        $etape = $this->dansRoute($ancienne, $livraison);

        $this->service->prendreEnCharge($livraison, $benevole->id);

        $this->assertDatabaseMissing('etapes_route', ['id' => $etape->id]);
        $this->assertDatabaseHas('etapes_route', ['id_livraison' => $livraison->id]);
        $this->assertSame($benevole->id, $livraison->fresh()->id_benevole_impose);
    }

    public function test_prendre_en_charge_est_refuse_quand_la_tournee_actuelle_est_chargee(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        $livraison = $this->livraison();
        $this->dansRoute($this->route('charge'), $livraison);

        try {
            $this->service->prendreEnCharge($livraison, $benevole->id);
            $this->fail('Refus attendu.');
        } catch (\RuntimeException) {
            $this->assertNull($livraison->fresh()->id_benevole_impose);
        }
    }

    public function test_prendre_en_charge_est_refuse_pour_une_famille_qui_se_deplace(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        $livraison = $this->livraison(['se_deplace' => true]);

        $this->expectException(\RuntimeException::class);
        $this->service->prendreEnCharge($livraison, $benevole->id);
    }

    public function test_prendre_en_charge_est_refuse_pour_un_benevole_sans_vehicule(): void
    {
        $sansVehicule = $this->creerBenevole($this->vehicules['non_vehicule']);
        $livraison = $this->livraison();

        $this->expectException(\RuntimeException::class);
        $this->service->prendreEnCharge($livraison, $sansVehicule->id);
    }

    public function test_une_famille_imposee_non_confirmee_n_est_routee_qua_sa_confirmation(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        $livraison = $this->livraison(['statut_contact' => 'a_contacter']);

        $this->service->prendreEnCharge($livraison, $benevole->id);
        $this->assertSame(0, RouteLivraison::where('id_benevole', $benevole->id)->count());

        $livraison->update(['statut_contact' => 'confirme']);
        $this->service->apresConfirmation($livraison->fresh());

        $this->assertSame(1, RouteLivraison::where('id_benevole', $benevole->id)->count());
        $this->assertSame('assignee', $livraison->fresh()->statut);
    }

    public function test_pas_de_route_imposee_avant_le_demarrage_de_la_campagne(): void
    {
        $this->campagne->update(['statut' => 'preparation']);
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        $livraison = $this->livraison();

        $this->service->prendreEnCharge($livraison, $benevole->id);

        $this->assertSame($benevole->id, $livraison->fresh()->id_benevole_impose);
        $this->assertSame(0, RouteLivraison::where('id_campagne', $this->campagne->id)->count());
    }

    public function test_retirer_limposition_sort_la_famille_de_la_tournee_imposee(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        $livraison = $this->livraison();
        $this->service->prendreEnCharge($livraison, $benevole->id);

        $this->service->retirerImposition($livraison->fresh());

        $this->assertNull($livraison->fresh()->id_benevole_impose);
        $this->assertSame('non_assignee', $livraison->fresh()->statut);
        $this->assertDatabaseMissing('etapes_route', ['id_livraison' => $livraison->id]);
    }

    public function test_retirer_limposition_est_refuse_quand_la_tournee_imposee_est_chargee(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        $livraison = $this->livraison();
        $this->service->prendreEnCharge($livraison, $benevole->id);
        RouteLivraison::where('id_benevole', $benevole->id)->update(['statut' => 'charge']);

        try {
            $this->service->retirerImposition($livraison->fresh());
            $this->fail('Refus attendu.');
        } catch (\RuntimeException) {
            $this->assertSame($benevole->id, $livraison->fresh()->id_benevole_impose);
        }
    }

    // ── Confirmation tardive ─────────────────────────────────────────────

    public function test_une_confirmation_tardive_se_deplace_recoit_son_rendez_vous_et_un_email(): void
    {
        $livraison = $this->livraison(['se_deplace' => true]);

        $this->service->apresConfirmation($livraison);

        $this->assertNotNull($livraison->fresh()->heure_arrivee_prevue_hq);
        Notification::assertSentOnDemand(RetraitHqNotification::class);
    }

    public function test_ajouter_une_famille_non_prete_a_une_tournee_prete_la_repasse_en_planifiee(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        $premiere = $this->livraison(['statut_conditionnement' => 'prete']);
        $this->service->prendreEnCharge($premiere, $benevole->id);
        $route = RouteLivraison::where('id_benevole', $benevole->id)->sole();
        $route->update(['statut' => 'chargement']);

        $this->service->prendreEnCharge($this->livraison(['statut_conditionnement' => 'en_attente']), $benevole->id);

        $this->assertSame('planifiee', $route->fresh()->statut);
    }
    // ── Retirer une famille de la campagne (09/10/2026) ──────────────────

    public function test_retirer_supprime_la_livraison_et_sa_place_dans_une_tournee_non_chargee(): void
    {
        $route = $this->route('planifiee');
        $livraison = $this->livraison();
        $etape = $this->dansRoute($route, $livraison);

        $this->service->retirerDeLaCampagne($livraison);

        $this->assertDatabaseMissing('livraisons', ['id' => $livraison->id]);
        $this->assertDatabaseMissing('etapes_route', ['id' => $etape->id]);
    }

    public function test_retirer_une_famille_dont_larret_est_ignore_supprime_aussi_larret(): void
    {
        // etapes_route.id_livraison est en nullOnDelete : sans suppression explicite,
        // l'arrêt ignoré deviendrait un faux arrêt « retour QG » de la tournée.
        $route = $this->route('en_cours');
        $livraison = $this->livraison();
        $etape = $this->dansRoute($route, $livraison, 'ignoree');
        $livraison->update(['statut' => 'ignoree']);

        $this->service->retirerDeLaCampagne($livraison);

        $this->assertDatabaseMissing('livraisons', ['id' => $livraison->id]);
        $this->assertDatabaseMissing('etapes_route', ['id' => $etape->id]);
        $this->assertSame(0, EtapeRoute::where('id_route', $route->id)->whereNull('id_livraison')->count());
    }

    public function test_retirer_ferme_les_incidents_ouverts_de_la_famille(): void
    {
        $route = $this->route('en_cours');
        $livraison = $this->livraison();
        $this->dansRoute($route, $livraison, 'ignoree');
        $incident = RouteIncident::withoutEvents(fn() => RouteIncident::create([
            'id_route' => $route->id,
            'type' => 'livraison_ignoree',
            'id_livraison' => $livraison->id,
            'signale_par' => $route->id_benevole,
            'statut' => 'ouvert',
        ]));

        $this->service->retirerDeLaCampagne($livraison);

        $this->assertSame('resolu', $incident->fresh()->statut);
    }

    public function test_retirer_est_refuse_quand_la_famille_est_livree(): void
    {
        $livraison = $this->livraison(['statut' => 'livree']);

        $this->expectException(\RuntimeException::class);
        $this->service->retirerDeLaCampagne($livraison);
    }

    public function test_retirer_est_refuse_quand_la_tournee_est_chargee_et_larret_en_attente(): void
    {
        $livraison = $this->livraison();
        $this->dansRoute($this->route('charge'), $livraison);

        try {
            $this->service->retirerDeLaCampagne($livraison);
            $this->fail('Refus attendu.');
        } catch (\RuntimeException) {
            $this->assertDatabaseHas('livraisons', ['id' => $livraison->id]);
        }
    }

    public function test_retirer_est_refuse_quand_le_conditionnement_a_commence(): void
    {
        foreach (['en_cours', 'prete'] as $statut) {
            $livraison = $this->livraison(['statut_conditionnement' => $statut]);

            try {
                $this->service->retirerDeLaCampagne($livraison);
                $this->fail('Refus attendu pour un conditionnement ' . $statut);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Packaging', $e->getMessage());
                $this->assertDatabaseHas('livraisons', ['id' => $livraison->id]);
            }
        }
    }

    public function test_retirer_une_famille_au_qg_annule_son_rendez_vous_et_replanifie_la_journee(): void
    {
        $partante = $this->livraison(['se_deplace' => true]);
        $restante = $this->livraison(['se_deplace' => true]);
        app(RetraitHqSchedulingService::class)->planifierPour($this->campagne, $this->journee);
        Notification::fake();

        $this->service->retirerDeLaCampagne($partante->fresh());

        $this->assertDatabaseMissing('livraisons', ['id' => $partante->id]);
        $this->assertNotNull($restante->fresh()->heure_arrivee_prevue_hq);
        Notification::assertSentOnDemand(RetraitHqAnnuleNotification::class);
    }

    // ── Réinitialiser une famille confirmée (09/10/2026) ─────────────────

    public function test_reinitialiser_remet_la_famille_a_contacter_et_efface_ses_choix(): void
    {
        $chauffeur = $this->creerBenevole($this->vehicules['voiture']);
        $livraison = $this->livraison();
        $livraison->creneaux()->create(['creneau' => Creneau::MATIN_1]);
        $this->service->prendreEnCharge($livraison, $chauffeur->id);

        $this->service->reinitialiserContact($livraison->fresh());

        $livraison->refresh();
        $this->assertSame('a_contacter', $livraison->statut_contact);
        $this->assertNull($livraison->id_benevole_impose);
        $this->assertSame('non_assignee', $livraison->statut);
        $this->assertFalse($livraison->se_deplace);
        $this->assertCount(0, $livraison->creneaux);
        $this->assertDatabaseMissing('etapes_route', ['id_livraison' => $livraison->id]);
    }

    public function test_reinitialiser_une_famille_au_qg_annule_son_rendez_vous(): void
    {
        $livraison = $this->livraison(['se_deplace' => true]);
        app(RetraitHqSchedulingService::class)->planifierPour($this->campagne, $this->journee);
        Notification::fake();

        $this->service->reinitialiserContact($livraison->fresh());

        $livraison->refresh();
        $this->assertFalse($livraison->se_deplace);
        $this->assertNull($livraison->heure_arrivee_prevue_hq);
        $this->assertNull($livraison->statut_retrait_hq);
        Notification::assertSentOnDemand(RetraitHqAnnuleNotification::class);
    }

    public function test_reinitialiser_est_refuse_pour_une_famille_non_confirmee_livree_ou_en_packaging(): void
    {
        $cas = [
            $this->livraison(['statut_contact' => 'a_contacter']),
            $this->livraison(['statut' => 'livree']),
            $this->livraison(['statut_conditionnement' => 'prete']),
        ];

        foreach ($cas as $livraison) {
            try {
                $this->service->reinitialiserContact($livraison);
                $this->fail('Refus attendu.');
            } catch (\RuntimeException) {
                $this->assertSame($livraison->statut_contact, $livraison->fresh()->statut_contact);
            }
        }
    }

    public function test_reinitialiser_est_refuse_quand_la_tournee_est_chargee(): void
    {
        $livraison = $this->livraison();
        $this->dansRoute($this->route('charge'), $livraison);

        $this->expectException(\RuntimeException::class);
        $this->service->reinitialiserContact($livraison);
    }
}
