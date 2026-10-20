<?php
// tests/Feature/Services/IncidentChauffeurIndisponibleTest.php

declare(strict_types=1);

namespace Tests\Feature\Services;

use Amana\Shared\Models\VehiculeType;
use App\Models\BenevoleDisponibilite;
use App\Models\CampagneJournee;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Services\IncidentResolutionService;
use App\Support\Creneau;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsDisponibiliteFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * 18/10/2026 — incident « chauffeur indisponible » : candidats (même type de véhicule ou plus grand), réassignation
 * et répartition entre plusieurs chauffeurs.
 */
class IncidentChauffeurIndisponibleTest extends TestCase
{
    use BuildsDisponibiliteFixtures;
    use SeedsCommunFixtures;

    private array $vehicules;

    private CampagneJournee $journee;

    private RouteLivraison $route;

    private RouteIncident $incident;

    private int $idChauffeurInitial;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
        $this->vehicules = $this->creerVehicules();
        [, $this->journee] = $this->creerCampagneAvecJournee();

        $this->idChauffeurInitial = $this->creerBenevole($this->vehicules['voiture'])->id;
        $this->route = RouteLivraison::create([
            'id_campagne' => $this->journee->id_campagne,
            'id_campagne_journee' => $this->journee->id,
            'id_benevole' => $this->idChauffeurInitial,
            'id_vehicule_type' => $this->vehicules['voiture'],
            'creneau' => Creneau::MATIN_1,
            'statut' => 'planifiee',
        ]);

        for ($i = 1; $i <= 4; $i++) {
            $famille = Famille::factory()->create();
            $famille->latitude = 0.01 * $i;
            $famille->longitude = 0.01 * $i;
            $famille->save();
            $livraison = Livraison::create([
                'id_famille' => $famille->id,
                'id_campagne' => $this->journee->id_campagne,
                'statut' => 'assignee',
                'statut_conditionnement' => 'en_attente',
                'nombre_personnes' => 1,
                'poids_kg' => 10.0,
                'statut_contact' => 'confirme',
            ]);
            EtapeRoute::create(['id_route' => $this->route->id, 'id_livraison' => $livraison->id, 'ordre' => $i, 'statut' => 'en_attente']);
        }

        $this->incident = RouteIncident::create([
            'id_route' => $this->route->id,
            'id_campagne' => $this->journee->id_campagne,
            'type' => 'chauffeur_indisponible',
            'signale_par' => $this->idChauffeurInitial,
            'statut' => 'ouvert',
        ]);
    }

    private function candidat(int $idVehicule, string $creneau = Creneau::MATIN_1): int
    {
        $benevole = $this->creerBenevole($idVehicule);
        $dispo = BenevoleDisponibilite::create([
            'id_personne' => $benevole->id,
            'id_campagne_journee' => $this->journee->id,
            'statut' => 'confirme',
            'vehicule_confirme' => true,
        ]);
        $dispo->creneaux()->create(['creneau' => $creneau]);

        return $benevole->id;
    }

    private function service(): IncidentResolutionService
    {
        return app(IncidentResolutionService::class);
    }

    // ── options ──────────────────────────────────────────────────────────

    public function test_les_options_listent_les_candidats_et_marquent_ceux_au_vehicule_assez_grand(): void
    {
        $utilitaire = $this->candidat($this->vehicules['utilitaire']);
        $voiture = $this->candidat($this->vehicules['voiture']);
        $petit = $this->candidat(VehiculeType::create(['type' => 'Vélo cargo', 'capacite_kg' => 50, 'nombre_part_max' => 2])->id);

        $options = $this->service()->optionsChauffeurIndisponible($this->incident->fresh());

        $this->assertTrue($options['resoluble']);
        $this->assertSame(4, $options['nb_arrets']);
        $this->assertSame(200.0, $options['vehicule_actuel']['capacite_kg']);

        $candidats = collect($options['candidats'])->keyBy('id_personne');
        $this->assertEqualsCanonicalizing([$utilitaire, $voiture, $petit], $candidats->keys()->all());
        $this->assertTrue($candidats[$utilitaire]['assez_grand']);
        $this->assertTrue($candidats[$voiture]['assez_grand'], 'Même type : accepté.');
        $this->assertFalse($candidats[$petit]['assez_grand']);
    }

    public function test_les_options_excluent_le_chauffeur_actuel_les_occupes_et_les_chauffeurs_partis(): void
    {
        $libre = $this->candidat($this->vehicules['voiture']);
        $occupe = $this->candidat($this->vehicules['voiture']);
        $parti = $this->candidat($this->vehicules['voiture']);

        RouteLivraison::create([
            'id_campagne' => $this->journee->id_campagne, 'id_campagne_journee' => $this->journee->id,
            'id_benevole' => $occupe, 'id_vehicule_type' => $this->vehicules['voiture'],
            'creneau' => Creneau::MATIN_1, 'statut' => 'planifiee',
        ]);
        RouteLivraison::create([
            'id_campagne' => $this->journee->id_campagne, 'id_campagne_journee' => $this->journee->id,
            'id_benevole' => $parti, 'id_vehicule_type' => $this->vehicules['voiture'],
            'creneau' => null, 'statut' => 'livraisons_terminees', 'retour_qg' => false,
        ]);

        $ids = array_column($this->service()->optionsChauffeurIndisponible($this->incident->fresh())['candidats'], 'id_personne');

        $this->assertSame([$libre], $ids);
    }

    public function test_les_options_signalent_une_tournee_deja_partie(): void
    {
        $this->route->update(['statut' => 'en_cours']);

        $options = $this->service()->optionsChauffeurIndisponible($this->incident->fresh());

        $this->assertFalse($options['resoluble']);
        $this->assertStringContainsString('déjà partie', (string) $options['message']);
    }

    public function test_les_options_donnent_la_tournee_ou_le_chauffeur_a_repondu_non(): void
    {
        $non = RouteLivraison::create([
            'id_campagne' => $this->journee->id_campagne, 'id_campagne_journee' => $this->journee->id,
            'id_benevole' => $this->idChauffeurInitial, 'id_vehicule_type' => $this->vehicules['voiture'],
            'creneau' => Creneau::MATIN_2, 'statut' => 'livraisons_terminees', 'retour_qg' => false,
        ]);

        $options = $this->service()->optionsChauffeurIndisponible($this->incident->fresh());

        $this->assertSame($non->id, $options['id_route_non_retour']);
    }

    // ── réassigner ───────────────────────────────────────────────────────

    public function test_reassigner_a_un_vehicule_plus_grand_change_le_chauffeur_et_resout_l_incident(): void
    {
        $utilitaire = $this->candidat($this->vehicules['utilitaire']);

        $this->service()->resoudreChauffeurIndisponible($this->incident, 'reassigner', ['id_benevole' => $utilitaire]);

        $route = $this->route->fresh();
        $this->assertSame($utilitaire, $route->id_benevole);
        $this->assertSame($this->vehicules['utilitaire'], $route->id_vehicule_type);
        $this->assertSame('resolu', $this->incident->fresh()->statut);
    }

    public function test_reassigner_a_un_vehicule_plus_petit_est_refuse(): void
    {
        $petit = $this->candidat(VehiculeType::create(['type' => 'Vélo cargo', 'capacite_kg' => 50, 'nombre_part_max' => 2])->id);

        try {
            $this->service()->resoudreChauffeurIndisponible($this->incident, 'reassigner', ['id_benevole' => $petit]);
            $this->fail('Une réassignation à un véhicule plus petit doit être refusée.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('plus petit', $e->getMessage());
        }

        $this->assertSame($this->idChauffeurInitial, $this->route->fresh()->id_benevole);
        $this->assertSame('ouvert', $this->incident->fresh()->statut);
    }

    public function test_reassigner_a_un_chauffeur_indisponible_est_refuse(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pas disponible');

        $this->service()->resoudreChauffeurIndisponible($this->incident, 'reassigner', ['id_benevole' => $this->creerPersonne(['benevole'])->id]);
    }

    public function test_reassigner_sans_choisir_de_chauffeur_est_refuse(): void
    {
        $this->candidat($this->vehicules['utilitaire']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Choisissez un chauffeur');

        $this->service()->resoudreChauffeurIndisponible($this->incident, 'reassigner', []);
    }

    // ── répartir ─────────────────────────────────────────────────────────

    public function test_repartir_entre_deux_chauffeurs_cree_une_tournee_et_resout_l_incident(): void
    {
        $a = $this->candidat($this->vehicules['voiture']);
        $b = $this->candidat($this->vehicules['voiture']);

        $this->service()->resoudreChauffeurIndisponible($this->incident, 'repartir', ['ids_benevoles' => [$a, $b]]);

        $routes = RouteLivraison::where('id_campagne', $this->journee->id_campagne)->where('statut', 'planifiee')->get();
        $this->assertCount(2, $routes);
        $this->assertEqualsCanonicalizing([$a, $b], $routes->pluck('id_benevole')->all());
        $this->assertSame(4, EtapeRoute::whereIn('id_route', $routes->pluck('id'))->count());
        $this->assertSame('resolu', $this->incident->fresh()->statut);
    }

    public function test_repartir_avec_un_seul_chauffeur_est_refuse(): void
    {
        $a = $this->candidat($this->vehicules['voiture']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('au moins deux chauffeurs');

        $this->service()->resoudreChauffeurIndisponible($this->incident, 'repartir', ['ids_benevoles' => [$a, $a]]);
    }

    public function test_repartir_a_un_chauffeur_indisponible_est_refuse_et_ne_modifie_rien(): void
    {
        $a = $this->candidat($this->vehicules['voiture']);

        try {
            $this->service()->resoudreChauffeurIndisponible($this->incident, 'repartir', ['ids_benevoles' => [$a, $this->creerPersonne(['benevole'])->id]]);
            $this->fail('Un chauffeur indisponible doit être refusé.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('pas disponible', $e->getMessage());
        }

        $this->assertSame(1, RouteLivraison::where('id_campagne', $this->journee->id_campagne)->count());
        $this->assertSame('ouvert', $this->incident->fresh()->statut);
    }

    public function test_une_action_inconnue_ou_un_autre_type_d_incident_est_refuse(): void
    {
        $this->candidat($this->vehicules['voiture']);

        try {
            $this->service()->resoudreChauffeurIndisponible($this->incident, 'inconnue', []);
            $this->fail('Action inconnue.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Action inconnue.', $e->getMessage());
        }

        $autre = RouteIncident::create([
            'id_route' => $this->route->id, 'id_campagne' => $this->journee->id_campagne,
            'type' => 'livraison_ignoree', 'signale_par' => $this->idChauffeurInitial, 'statut' => 'ouvert',
        ]);
        $this->expectException(\RuntimeException::class);
        $this->service()->resoudreChauffeurIndisponible($autre, 'reassigner', []);
    }
}
