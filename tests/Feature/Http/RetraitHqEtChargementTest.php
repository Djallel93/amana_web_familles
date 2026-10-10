<?php
// tests/Feature/Http/RetraitHqEtChargementTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Campagne;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\LivraisonColis;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Support\Creneau;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsDisponibiliteFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * 09/10/2026 — Retrait QG : statuts « Restante » / « En préparation » / « Prête »
 * (comme l'écran Chargement), « Non livré » annulable + incident « retrait QG non
 * livré » ; Chargement : type de véhicule, contact du chauffeur, colis à charger.
 */
class RetraitHqEtChargementTest extends TestCase
{
    use BuildsDisponibiliteFixtures;
    use SeedsCommunFixtures;

    private Campagne $campagne;

    /** @var array{voiture: int, utilitaire: int, sans_permis: int, non_vehicule: int} */
    private array $vehicules;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
        $this->vehicules = $this->creerVehicules();
        [$this->campagne] = $this->creerCampagneAvecJournee();
    }

    private function gestionnaire()
    {
        return $this->creerPersonne(['gestionnaire']);
    }

    private function retrait(string $conditionnement, array $surcharge = []): Livraison
    {
        return Livraison::create(array_merge([
            'id_famille' => Famille::factory()->create(['email' => 'f' . uniqid() . '@amana-test.fr'])->id,
            'id_campagne' => $this->campagne->id,
            'statut' => 'non_assignee',
            'statut_conditionnement' => $conditionnement,
            'nombre_personnes' => 2,
            'poids_kg' => 10.0,
            'statut_contact' => 'confirme',
            'se_deplace' => true,
        ], $surcharge));
    }

    // ── Statuts du Retrait QG ────────────────────────────────────────────

    public function test_le_retrait_distingue_restante_en_preparation_et_prete(): void
    {
        $restante = $this->retrait('en_attente');
        $enPreparation = $this->retrait('en_cours');
        $prete = $this->retrait('prete');

        $json = $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.retrait-hq.liste', $this->campagne))
            ->assertOk()
            ->json();

        $statuts = collect($json['lignes'])->pluck('statut', 'id');
        $this->assertSame('restante', $statuts[$restante->id]);
        $this->assertSame('en_preparation', $statuts[$enPreparation->id]);
        $this->assertSame('prete', $statuts[$prete->id]);

        $this->assertSame(1, $json['stats']['restantes']);
        $this->assertSame(1, $json['stats']['en_preparation']);
        $this->assertSame(1, $json['stats']['pretes']);
        $this->assertSame(3, $json['stats']['total']);
    }

    public function test_la_liste_suit_le_conditionnement_pour_le_polling(): void
    {
        $livraison = $this->retrait('en_attente');
        $gestionnaire = $this->gestionnaire();

        $avant = $this->actingAs($gestionnaire)->getJson(route('livraison.retrait-hq.liste', $this->campagne))->json();
        $livraison->update(['statut_conditionnement' => 'en_cours']);
        $milieu = $this->getJson(route('livraison.retrait-hq.liste', $this->campagne))->json();
        $livraison->update(['statut_conditionnement' => 'prete']);
        $apres = $this->getJson(route('livraison.retrait-hq.liste', $this->campagne))->json();

        $this->assertSame('restante', $avant['lignes'][0]['statut']);
        $this->assertSame('en_preparation', $milieu['lignes'][0]['statut']);
        $this->assertSame('prete', $apres['lignes'][0]['statut']);
        $this->assertNotSame($avant['lignes'][0]['sig'], $milieu['lignes'][0]['sig']);
        $this->assertNotSame($milieu['lignes'][0]['sig'], $apres['lignes'][0]['sig']);
    }

    public function test_le_filtre_restantes_ne_garde_que_les_familles_sans_colis_pret(): void
    {
        $restante = $this->retrait('en_attente');
        $this->retrait('en_cours');

        $json = $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.retrait-hq.liste', $this->campagne) . '?filtre_retrait_hq=restante')
            ->assertOk()
            ->json();

        $this->assertSame([$restante->id], collect($json['lignes'])->pluck('id')->all());
        // Les cartes restent calculées sur l'ensemble.
        $this->assertSame(2, $json['stats']['total']);
    }

    public function test_la_page_propose_la_carte_et_le_filtre_restantes(): void
    {
        $this->retrait('en_attente');

        $this->actingAs($this->gestionnaire())
            ->get(route('livraison.retrait-hq.index', $this->campagne))
            ->assertOk()
            ->assertSee('id="stat-restantes"', false)
            ->assertSee('Restante');
    }

    // ── « Non livré » : incident + annulation ────────────────────────────

    public function test_non_livre_ouvre_un_incident_rattache_a_la_campagne(): void
    {
        $livraison = $this->retrait('prete');

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.retrait-hq.non-livre', $livraison))
            ->assertOk();

        $this->assertSame('non_delivre', $livraison->fresh()->statut_retrait_hq);
        $incident = RouteIncident::where('type', 'retrait_hq_non_livre')->sole();
        $this->assertNull($incident->id_route);
        $this->assertSame($this->campagne->id, $incident->id_campagne);
        $this->assertSame($livraison->id, $incident->id_livraison);
        $this->assertSame('ouvert', $incident->statut);
    }

    public function test_lincident_du_retrait_compte_dans_les_incidents_de_la_campagne(): void
    {
        $livraison = $this->retrait('prete');
        $gestionnaire = $this->gestionnaire();
        $this->actingAs($gestionnaire)->postJson(route('livraison.retrait-hq.non-livre', $livraison))->assertOk();

        $this->getJson(route('livraison.campagnes.avancement', $this->campagne))
            ->assertJsonPath('compteurs.incidents_ouverts', 1);
        $this->getJson(route('livraison.campagnes.incidents-liste', $this->campagne))
            ->assertOk()
            ->assertJsonPath('0.type', 'retrait_hq_non_livre')
            ->assertJsonPath('0.type_label', 'Retrait QG non livré')
            ->assertJsonPath('0.id_route', null);
        $this->getJson(route('livraison.campagnes.incidents', $this->campagne))
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_annuler_non_livre_remet_la_famille_prete_et_resout_son_incident(): void
    {
        $livraison = $this->retrait('prete');
        $gestionnaire = $this->gestionnaire();
        $this->actingAs($gestionnaire)->postJson(route('livraison.retrait-hq.non-livre', $livraison))->assertOk();

        $this->postJson(route('livraison.retrait-hq.annuler-non-livre', $livraison))
            ->assertOk()
            ->assertJsonPath('statut', 'prete');

        $this->assertNull($livraison->fresh()->statut_retrait_hq);
        $this->assertSame('resolu', RouteIncident::where('type', 'retrait_hq_non_livre')->sole()->statut);
    }

    public function test_annuler_non_livre_ne_touche_pas_un_incident_deja_traite_par_le_gestionnaire(): void
    {
        $livraison = $this->retrait('prete');
        $gestionnaire = $this->gestionnaire();
        $this->actingAs($gestionnaire)->postJson(route('livraison.retrait-hq.non-livre', $livraison))->assertOk();
        RouteIncident::where('type', 'retrait_hq_non_livre')->update(['statut' => 'ignore']);

        $this->postJson(route('livraison.retrait-hq.annuler-non-livre', $livraison))->assertOk();

        $this->assertSame('ignore', RouteIncident::where('type', 'retrait_hq_non_livre')->sole()->statut);
    }

    public function test_annuler_non_livre_est_refuse_pour_une_famille_qui_nest_pas_non_livree(): void
    {
        $livraison = $this->retrait('prete');

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.retrait-hq.annuler-non-livre', $livraison))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_la_ligne_affiche_le_bouton_annuler_pour_une_famille_non_livree(): void
    {
        $this->retrait('prete', ['statut_retrait_hq' => 'non_delivre']);

        $this->actingAs($this->gestionnaire())
            ->get(route('livraison.retrait-hq.index', $this->campagne))
            ->assertOk()
            ->assertSee('Annuler « Non livré »');
    }

    // ── Chargement : véhicule, contact, colis à charger ──────────────────

    public function test_la_carte_chargement_affiche_vehicule_contact_et_nombre_de_colis(): void
    {
        $chauffeur = $this->creerBenevole($this->vehicules['utilitaire']);
        $chauffeur->update(['telephone' => '0611223344']);
        $route = RouteLivraison::create([
            'id_campagne' => $this->campagne->id,
            'id_benevole' => $chauffeur->id,
            'id_vehicule_type' => $this->vehicules['utilitaire'],
            'creneau' => Creneau::MATIN_1,
            'statut' => 'chargement',
        ]);
        foreach ([2, 3] as $ordre => $nbColis) {
            $livraison = $this->retrait('prete', ['se_deplace' => false, 'statut' => 'assignee']);
            for ($n = 1; $n <= $nbColis; $n++) {
                LivraisonColis::create(['id_livraison' => $livraison->id, 'numero' => $n, 'statut' => 'pret']);
            }
            EtapeRoute::create(['id_route' => $route->id, 'id_livraison' => $livraison->id, 'ordre' => $ordre + 1, 'statut' => 'en_attente']);
        }

        $this->actingAs($this->gestionnaire())
            ->get(route('livraison.chargement.index', $this->campagne))
            ->assertOk()
            ->assertSee('Utilitaire')
            ->assertSee('0611223344')
            ->assertSee('tel:0611223344', false)
            ->assertSee('5 colis à charger');
    }

    public function test_la_carte_chargement_indique_un_telephone_manquant(): void
    {
        $chauffeur = $this->creerBenevole($this->vehicules['voiture']);
        $route = RouteLivraison::create([
            'id_campagne' => $this->campagne->id,
            'id_benevole' => $chauffeur->id,
            'id_vehicule_type' => $this->vehicules['voiture'],
            'creneau' => Creneau::MATIN_1,
            'statut' => 'chargement',
        ]);
        $livraison = $this->retrait('prete', ['se_deplace' => false, 'statut' => 'assignee']);
        EtapeRoute::create(['id_route' => $route->id, 'id_livraison' => $livraison->id, 'ordre' => 1, 'statut' => 'en_attente']);

        $this->actingAs($this->gestionnaire())
            ->get(route('livraison.chargement.index', $this->campagne))
            ->assertOk()
            ->assertSee('téléphone non renseigné')
            ->assertSee('0 colis à charger');
    }
}
