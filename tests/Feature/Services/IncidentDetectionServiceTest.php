<?php
// tests/Feature/Services/IncidentDetectionServiceTest.php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\BenevoleDisponibilite;
use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Services\IncidentDetectionService;
use App\Support\Creneau;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsDisponibiliteFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * 23/10/2026 — détection automatique des incidents (IncidentDetectionService) : un test par détecteur,
 * plus l'idempotence, l'auto-résolution et le respect d'une décision humaine.
 */
class IncidentDetectionServiceTest extends TestCase
{
    use BuildsDisponibiliteFixtures;
    use SeedsCommunFixtures;

    private Campagne $campagne;

    private CampagneJournee $journee;

    /** @var array{voiture: int, utilitaire: int, sans_permis: int, non_vehicule: int} */
    private array $vehicules;

    private IncidentDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
        $this->vehicules = $this->creerVehicules();
        [$this->campagne, $this->journee] = $this->creerCampagneAvecJournee('2026-11-10');
        $this->service = app(IncidentDetectionService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function livraison(array $surcharge = [], array $famille = []): Livraison
    {
        return Livraison::create(array_merge([
            'id_famille' => Famille::factory()->create(array_merge(['latitude' => 48.85, 'longitude' => 2.35], $famille))->id,
            'id_campagne' => $this->campagne->id,
            'id_campagne_journee' => $this->journee->id,
            'statut' => 'non_assignee',
            'statut_contact' => 'confirme',
            'nombre_personnes' => 2,
            'poids_kg' => 10.0,
        ], $surcharge));
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

    private function confirmerChauffeur(int $idPersonne): void
    {
        $dispo = BenevoleDisponibilite::create(['id_personne' => $idPersonne, 'id_campagne_journee' => $this->journee->id, 'statut' => 'confirme']);
        $dispo->creneaux()->create(['creneau' => '08-10']);
    }

    private function incidents(string $type)
    {
        return RouteIncident::where('type', $type)->get();
    }

    private function maintenant(string $horaire): Carbon
    {
        return Carbon::parse("2026-11-10 {$horaire}", config('app.timezone'));
    }

    public function test_une_famille_sans_coordonnees_ouvre_un_incident_et_il_se_ferme_quand_l_adresse_est_localisee(): void
    {
        $livraison = $this->livraison([], ['latitude' => null, 'longitude' => null]);

        $this->service->detecter($this->campagne);
        $incident = $this->incidents('famille_non_geocodee')->sole();
        $this->assertSame($livraison->id, $incident->id_livraison);
        $this->assertSame('ouvert', $incident->statut);
        $this->assertSame(0, $incident->signale_par);

        $livraison->famille->forceFill(['latitude' => 48.1, 'longitude' => 2.1])->save();
        $this->assertSame(1, $this->service->detecter($this->campagne)['auto_resolus']);
        $this->assertSame('resolu', $incident->fresh()->statut);
        $this->assertStringContainsString(IncidentDetectionService::MARQUEUR_AUTO_RESOLU, $incident->fresh()->notes);
    }

    public function test_la_detection_est_idempotente(): void
    {
        $this->livraison([], ['latitude' => null, 'longitude' => null]);

        $this->service->detecter($this->campagne);
        $this->service->detecter($this->campagne);
        $this->service->detecter($this->campagne);

        $this->assertCount(1, $this->incidents('famille_non_geocodee'));
    }

    public function test_un_incident_ignore_ou_resolu_a_la_main_n_est_pas_recree(): void
    {
        $this->livraison([], ['latitude' => null, 'longitude' => null]);
        $this->service->detecter($this->campagne);

        $incident = $this->incidents('famille_non_geocodee')->sole();
        $incident->update(['statut' => 'ignore']);
        $this->service->detecter($this->campagne);
        $this->assertCount(1, $this->incidents('famille_non_geocodee'));

        $incident->update(['statut' => 'resolu']);
        $this->service->detecter($this->campagne);
        $this->assertCount(1, $this->incidents('famille_non_geocodee'));
    }

    public function test_un_incident_auto_resolu_est_recree_si_le_probleme_revient(): void
    {
        $livraison = $this->livraison([], ['latitude' => null, 'longitude' => null]);
        $this->service->detecter($this->campagne);
        $livraison->famille->forceFill(['latitude' => 48.1, 'longitude' => 2.1])->save();
        $this->service->detecter($this->campagne);

        $livraison->famille->forceFill(['latitude' => null, 'longitude' => null])->save();
        $this->service->detecter($this->campagne);

        $this->assertCount(2, $this->incidents('famille_non_geocodee'));
        $this->assertSame(['resolu', 'ouvert'], $this->incidents('famille_non_geocodee')->pluck('statut')->all());
    }

    public function test_les_familles_sans_tournee_ne_sont_signalees_que_si_des_tournees_existent(): void
    {
        $this->livraison();
        $this->service->detecter($this->campagne);
        $this->assertCount(0, $this->incidents('livraisons_non_couvertes'));

        $this->route();
        $this->service->detecter($this->campagne);
        $incident = $this->incidents('livraisons_non_couvertes')->sole();
        $this->assertStringContainsString('1 famille(s) sans tournée', $incident->notes);
    }

    public function test_une_famille_qui_vient_au_qg_ou_sans_coordonnees_n_est_pas_comptee_comme_non_couverte(): void
    {
        $this->route();
        $this->livraison(['se_deplace' => true]);
        $this->livraison([], ['latitude' => null, 'longitude' => null]);

        $this->service->detecter($this->campagne);

        $this->assertCount(0, $this->incidents('livraisons_non_couvertes'));
        $this->assertCount(1, $this->incidents('famille_non_geocodee'));
    }

    public function test_une_famille_injoignable_depuis_longtemps_est_signalee(): void
    {
        $recente = $this->livraison(['statut_contact' => 'injoignable']);
        $ancienne = $this->livraison(['statut_contact' => 'injoignable']);
        Livraison::whereKey($ancienne->id)->update(['updated_at' => now()->subHours(30)]);

        $this->service->detecter($this->campagne);

        $this->assertSame([$ancienne->id], $this->incidents('famille_injoignable')->pluck('id_livraison')->all());
        $this->assertNotContains($recente->id, $this->incidents('famille_injoignable')->pluck('id_livraison')->all());
    }

    public function test_un_retrait_au_qg_en_retard_est_signale_puis_se_ferme_quand_la_famille_est_livree(): void
    {
        $livraison = $this->livraison(['se_deplace' => true, 'heure_arrivee_prevue_hq' => $this->maintenant('10:00')]);

        $this->service->detecter($this->campagne, $this->maintenant('10:20'));
        $this->assertCount(0, $this->incidents('retrait_hq_en_retard'), 'sous le seuil de 30 minutes');

        $this->service->detecter($this->campagne, $this->maintenant('10:45'));
        $this->assertCount(1, $this->incidents('retrait_hq_en_retard'));

        $livraison->update(['statut_retrait_hq' => 'delivre']);
        $this->service->detecter($this->campagne, $this->maintenant('10:50'));
        $this->assertSame('resolu', $this->incidents('retrait_hq_en_retard')->sole()->statut);
    }

    public function test_une_tournee_chargee_qui_ne_demarre_pas_est_signalee(): void
    {
        $route = $this->route('charge');

        $this->service->detecter($this->campagne, $this->maintenant('08:20'));
        $this->assertCount(0, $this->incidents('tournee_non_demarree'));

        $this->service->detecter($this->campagne, $this->maintenant('08:40'));
        $this->assertSame($route->id, $this->incidents('tournee_non_demarree')->sole()->id_route);

        $route->update(['statut' => 'en_cours']);
        $this->service->detecter($this->campagne, $this->maintenant('08:45'));
        $this->assertSame('resolu', $this->incidents('tournee_non_demarree')->sole()->statut);
    }

    public function test_un_packaging_en_retard_est_signale_avant_le_creneau(): void
    {
        $route = $this->route();
        $livraison = $this->livraison(['statut' => 'assignee', 'statut_conditionnement' => 'en_attente']);
        EtapeRoute::create(['id_route' => $route->id, 'id_livraison' => $livraison->id, 'ordre' => 1, 'statut' => 'en_attente']);

        $this->service->detecter($this->campagne, $this->maintenant('07:00'));
        $this->assertCount(0, $this->incidents('packaging_en_retard'));

        $this->service->detecter($this->campagne, $this->maintenant('07:40'));
        $this->assertSame($route->id, $this->incidents('packaging_en_retard')->sole()->id_route);
    }

    public function test_un_chargement_en_retard_n_est_signale_que_si_tous_les_colis_sont_prets(): void
    {
        $route = $this->route('chargement');
        $livraison = $this->livraison(['statut' => 'assignee', 'statut_conditionnement' => 'prete']);
        EtapeRoute::create(['id_route' => $route->id, 'id_livraison' => $livraison->id, 'ordre' => 1, 'statut' => 'en_attente']);

        $this->service->detecter($this->campagne, $this->maintenant('08:05'));

        $this->assertCount(1, $this->incidents('chargement_en_retard'));
        $this->assertCount(0, $this->incidents('packaging_en_retard'));
    }

    public function test_une_tournee_au_dessus_de_la_capacite_de_son_vehicule_ouvre_un_incident_capacite(): void
    {
        $route = $this->route('planifiee', ['poids_total_kg' => 350]);

        $this->service->detecter($this->campagne);

        $incident = $this->incidents('capacite')->sole();
        $this->assertSame($route->id, $incident->id_route);
        $this->assertStringContainsString('350', $incident->notes);
    }

    public function test_un_chauffeur_qui_n_est_plus_confirme_est_signale(): void
    {
        $route = $this->route();
        $this->service->detecter($this->campagne);
        $this->assertSame($route->id, $this->incidents('chauffeur_retire')->sole()->id_route);

        $this->confirmerChauffeur($route->id_benevole);
        $this->service->detecter($this->campagne);
        $this->assertSame('resolu', $this->incidents('chauffeur_retire')->sole()->statut);
    }

    public function test_trop_peu_de_chauffeurs_confirmes_est_signale(): void
    {
        $this->livraison();
        $this->service->detecter($this->campagne);
        $this->assertCount(1, $this->incidents('chauffeurs_insuffisants'), 'aucun chauffeur confirmé');

        $chauffeur = $this->creerBenevole($this->vehicules['voiture']);
        $this->confirmerChauffeur($chauffeur->id);
        $this->service->detecter($this->campagne);
        $this->assertSame('resolu', $this->incidents('chauffeurs_insuffisants')->sole()->statut);
    }

    public function test_les_incidents_d_une_autre_campagne_ne_sont_pas_touches(): void
    {
        $this->livraison([], ['latitude' => null, 'longitude' => null]);
        [$autre] = $this->creerCampagneAvecJournee('2026-12-01');

        $this->assertSame(['crees' => 0, 'auto_resolus' => 0], $this->service->detecter($autre));
        $this->assertCount(0, $this->incidents('famille_non_geocodee'));
    }

    public function test_lever_un_incident_d_evenement_est_idempotent(): void
    {
        $livraison = $this->livraison();

        $this->service->signalerEchecEmail($livraison, 'retrait QG', 'Connection refused');
        $this->service->signalerEchecEmail($livraison, 'retrait QG', 'Connection refused');

        $incident = $this->incidents('email_echec')->sole();
        $this->assertSame($livraison->id, $incident->id_livraison);
        $this->assertStringContainsString('Connection refused', $incident->notes);

        // Jamais auto-résolu : rien à re-tester pour un événement passé.
        $this->service->detecter($this->campagne);
        $this->assertSame('ouvert', $incident->fresh()->statut);
    }

    public function test_un_job_en_echec_s_attache_aux_campagnes_actives_de_la_famille(): void
    {
        $livraison = $this->livraison();
        $terminee = Campagne::create(['type' => 'zakat_el_fitr', 'statut' => 'terminee', 'date_livraison' => '2026-01-10', 'hq_latitude' => 0, 'hq_longitude' => 0]);
        $ancienne = Livraison::create([
            'id_famille' => $livraison->id_famille, 'id_campagne' => $terminee->id,
            'statut' => 'livree', 'statut_contact' => 'confirme', 'nombre_personnes' => 2, 'poids_kg' => 5,
        ]);

        $this->service->signalerEchecJob($livraison->id_famille, 'localisation de l\'adresse', 'boom');

        $incident = $this->incidents('job_echec')->sole();
        $this->assertSame($livraison->id, $incident->id_livraison);
        $this->assertNotSame($ancienne->id, $incident->id_livraison);
    }

    public function test_la_commande_ne_traite_que_les_campagnes_en_cours_sauf_option(): void
    {
        $this->livraison([], ['latitude' => null, 'longitude' => null]);
        $this->campagne->update(['statut' => 'preparation']);

        Artisan::call('livraison:detecter-incidents');
        $this->assertCount(0, $this->incidents('famille_non_geocodee'));

        Artisan::call('livraison:detecter-incidents', ['--campagne' => $this->campagne->id]);
        $this->assertCount(1, $this->incidents('famille_non_geocodee'));

        $this->campagne->update(['statut' => 'en_cours']);
        Artisan::call('livraison:detecter-incidents');
        $this->assertCount(1, $this->incidents('famille_non_geocodee'));
        $this->assertStringContainsString('Incidents créés : 0', Artisan::output());
    }
}
