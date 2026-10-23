<?php
// tests/Feature/Http/IncidentsAidesResolutionTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Jobs\ResoudreAdresseFamille;
use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Notifications\RouteIncidentNotification;
use App\Support\Creneau;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsDisponibiliteFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * 23/10/2026 — aides à la résolution des incidents (docs/incidents.md) : actions directes par type,
 * guide et coordonnées dans la liste, garde contre un double clic, auto-résolution d'un chargement annulé
 * et contenu de la notification (plus de « Tournée # » vide, lien vers le hub de la campagne).
 */
class IncidentsAidesResolutionTest extends TestCase
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

    private function route(string $statut = 'planifiee', array $surcharge = []): RouteLivraison
    {
        return RouteLivraison::create(array_merge([
            'id_campagne' => $this->campagne->id,
            'id_campagne_journee' => $this->journee->id,
            'id_benevole' => $this->creerBenevole($this->vehicules['voiture'])->id,
            'id_vehicule_type' => $this->vehicules['voiture'],
            'creneau' => Creneau::MATIN_1,
            'statut' => $statut,
        ], $surcharge));
    }

    private function livraison(array $surcharge = [], array $famille = []): Livraison
    {
        return Livraison::create(array_merge([
            'id_famille' => Famille::factory()->create(array_merge(['latitude' => 48.85, 'longitude' => 2.35, 'email' => 'f' . uniqid() . '@amana-test.fr'], $famille))->id,
            'id_campagne' => $this->campagne->id,
            'id_campagne_journee' => $this->journee->id,
            'statut' => 'non_assignee',
            'statut_contact' => 'confirme',
            'nombre_personnes' => 2,
            'poids_kg' => 10.0,
        ], $surcharge));
    }

    private function incident(string $type, ?RouteLivraison $route = null, ?Livraison $livraison = null, string $statut = 'ouvert'): RouteIncident
    {
        return RouteIncident::withoutEvents(fn() => RouteIncident::create([
            'id_route' => $route?->id,
            'id_campagne' => $this->campagne->id,
            'type' => $type,
            'id_livraison' => $livraison?->id,
            'signale_par' => 0,
            'statut' => $statut,
        ]));
    }

    private function suite(RouteIncident $incident, array $donnees)
    {
        return $this->actingAs($this->gestionnaire())->postJson(route('livraison.incidents.resoudre-suite', $incident), $donnees);
    }

    // ── Liste ───────────────────────────────────────────────────────────

    public function test_la_liste_expose_guide_contacts_et_marque_les_incidents_detectes(): void
    {
        $livraison = $this->livraison([], ['telephone' => '0612345678', 'telephone_bis' => null]);
        $this->incident('famille_injoignable', null, $livraison);

        $ligne = $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.campagnes.incidents-liste', $this->campagne))
            ->assertOk()
            ->json(0);

        $this->assertStringStartsWith('1. ', $ligne['guide']);
        $this->assertSame(['+33 6 12 34 56 78'], $ligne['famille_telephone']);
        $this->assertSame($livraison->famille->email, $ligne['famille_email']);
        $this->assertTrue($ligne['detecte']);
    }

    // ── Capacité ────────────────────────────────────────────────────────

    public function test_capacite_les_options_donnent_les_arrets_et_les_chauffeurs_assez_grands(): void
    {
        $route = $this->route('planifiee', ['poids_total_kg' => 300]);
        $livraison = $this->livraison(['statut' => 'assignee', 'poids_kg' => 300]);
        $etape = EtapeRoute::create(['id_route' => $route->id, 'id_livraison' => $livraison->id, 'ordre' => 1, 'statut' => 'en_attente']);
        $incident = $this->incident('capacite', $route);

        $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.incidents.options', $incident))
            ->assertOk()
            ->assertJsonPath('resoluble', true)
            ->assertJsonPath('capacite_kg', 200)
            ->assertJsonPath('arrets.0.id_etape', $etape->id);
    }

    public function test_capacite_retirer_des_familles_les_remet_a_planifier_et_resout(): void
    {
        $route = $this->route('planifiee', ['poids_total_kg' => 300]);
        $livraison = $this->livraison(['statut' => 'assignee', 'poids_kg' => 300]);
        $etape = EtapeRoute::create(['id_route' => $route->id, 'id_livraison' => $livraison->id, 'ordre' => 1, 'statut' => 'en_attente']);
        $incident = $this->incident('capacite', $route);

        $this->suite($incident, ['action' => 'retirer', 'ids_etapes' => [$etape->id]])->assertOk();

        $this->assertSame('non_assignee', $livraison->fresh()->statut);
        $this->assertSame(0, EtapeRoute::where('id_route', $route->id)->count());
        $this->assertSame('resolu', $incident->fresh()->statut);
    }

    public function test_capacite_retirer_sans_selection_ou_sur_tournee_partie_est_refuse(): void
    {
        $incident = $this->incident('capacite', $this->route('planifiee'));
        $this->suite($incident, ['action' => 'retirer', 'ids_etapes' => []])->assertStatus(422);

        $partie = $this->incident('capacite', $this->route('en_cours'));
        $this->suite($partie, ['action' => 'diviser'])->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame('ouvert', $partie->fresh()->statut);
    }

    // ── Se déplace remplacé ─────────────────────────────────────────────

    public function test_se_deplace_remplace_prevenue_clot_l_incident(): void
    {
        $incident = $this->incident('se_deplace_remplace', null, $this->livraison(['id_benevole_impose' => $this->creerBenevole($this->vehicules['voiture'])->id]));

        $this->suite($incident, ['action' => 'prevenue'])->assertOk();

        $this->assertSame('resolu', $incident->fresh()->statut);
    }

    // ── Familles sans tournée ───────────────────────────────────────────

    public function test_familles_sans_tournee_les_faire_venir_au_qg(): void
    {
        $a = $this->livraison();
        $b = $this->livraison();
        $deja = $this->livraison(['se_deplace' => true]);
        $incident = $this->incident('livraisons_non_couvertes');

        $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.incidents.options', $incident))
            ->assertOk()
            ->assertJsonCount(2, 'familles');

        $this->suite($incident, ['action' => 'retrait_qg'])->assertOk();

        $this->assertTrue($a->fresh()->se_deplace);
        $this->assertTrue($b->fresh()->se_deplace);
        $this->assertSame('resolu', $incident->fresh()->statut);
        $this->assertTrue($deja->fresh()->se_deplace);
    }

    public function test_familles_sans_tournee_sans_famille_restante_est_refuse(): void
    {
        $incident = $this->incident('livraisons_non_couvertes');

        $this->suite($incident, ['action' => 'retrait_qg'])->assertStatus(422);
    }

    // ── Famille non localisée / injoignable ─────────────────────────────

    public function test_regeocoder_relance_le_job_sans_cloturer_l_incident(): void
    {
        Bus::fake();
        $livraison = $this->livraison([], ['latitude' => null, 'longitude' => null]);
        $incident = $this->incident('famille_non_geocodee', null, $livraison);

        $this->suite($incident, ['action' => 'regeocoder'])->assertOk();

        Bus::assertDispatched(ResoudreAdresseFamille::class);
        $this->assertSame('ouvert', $incident->fresh()->statut);
    }

    public function test_famille_injoignable_a_recontacter_et_retirer(): void
    {
        $a = $this->livraison(['statut_contact' => 'injoignable']);
        $incidentA = $this->incident('famille_injoignable', null, $a);
        $this->suite($incidentA, ['action' => 'a_recontacter'])->assertOk();
        $this->assertSame('a_contacter', $a->fresh()->statut_contact);
        $this->assertSame('resolu', $incidentA->fresh()->statut);

        $b = $this->livraison(['statut_contact' => 'injoignable']);
        $incidentB = $this->incident('famille_injoignable', null, $b);
        $this->suite($incidentB, ['action' => 'retirer'])->assertOk();
        $this->assertNull(Livraison::find($b->id));
        $this->assertSame('resolu', $incidentB->fresh()->statut);
    }

    // ── Email en échec ──────────────────────────────────────────────────

    public function test_email_echec_renvoyer_refuse_sans_rendez_au_qg(): void
    {
        $incident = $this->incident('email_echec', null, $this->livraison());

        $this->suite($incident, ['action' => 'renvoyer'])->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame('ouvert', $incident->fresh()->statut);
    }

    public function test_une_action_inconnue_ou_sur_un_incident_ferme_est_refusee(): void
    {
        $ouvert = $this->incident('famille_injoignable', null, $this->livraison());
        $this->suite($ouvert, ['action' => 'diviser'])->assertStatus(422);

        $ferme = $this->incident('famille_injoignable', null, $this->livraison(), 'resolu');
        $this->suite($ferme, ['action' => 'a_recontacter'])->assertStatus(422);
    }

    // ── Garde de LiveBoardController::resoudreIncident ──────────────────

    public function test_resoudre_un_incident_deja_ferme_est_refuse(): void
    {
        $incident = $this->incident('capacite', $this->route(), null, 'ignore');

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.incidents.resoudre', $incident))
            ->assertStatus(422);

        $this->assertSame('ignore', $incident->fresh()->statut);
    }

    // ── Chargement annulé puis reconfirmé ───────────────────────────────

    public function test_reconfirmer_le_chargement_resout_l_incident_chargement_annule(): void
    {
        $route = $this->route('chargement');
        $incident = $this->incident('chargement_annule', $route);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.chargement.confirmer', $route))
            ->assertOk();

        $this->assertSame('resolu', $incident->fresh()->statut);
        $this->assertStringContainsString('[auto-resolu]', $incident->fresh()->notes);
    }

    // ── Notification ────────────────────────────────────────────────────

    public function test_la_notification_d_un_incident_sans_tournee_n_affiche_pas_tournee_et_pointe_vers_le_hub(): void
    {
        $incident = $this->incident('famille_non_geocodee', null, $this->livraison());
        $incident->update(['notes' => 'Aucune coordonnée.']);
        $notification = new RouteIncidentNotification($incident);
        $notifiable = $this->creerPersonne(['gestionnaire']);

        $donnees = $notification->toDatabase($notifiable);

        $this->assertSame('Adresse non localisée', $donnees['titre']);
        $this->assertSame('Aucune coordonnée.', $donnees['message']);
        $this->assertSame(route('livraison.campagnes.show', $this->campagne->id), $donnees['url']);

        $avecTournee = new RouteIncidentNotification($this->incident('capacite', $this->route()));
        $this->assertStringStartsWith('Tournée #', $avecTournee->toDatabase($notifiable)['message']);
    }
}
