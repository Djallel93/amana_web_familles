<?php
// tests/Feature/Http/CampagneDemarrageTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteLivraison;
use App\Notifications\RetraitHqNotification;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsDisponibiliteFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * « Démarrer la campagne » (06/10/2026) — voir CampagneDemarrageService :
 * statut 'en_cours', tournées des familles imposées (sans créneau), rendez-vous
 * et emails de retrait QG envoyés UNE fois. Au moins une famille confirmée requise.
 */
class CampagneDemarrageTest extends TestCase
{
    use BuildsDisponibiliteFixtures;
    use SeedsCommunFixtures;

    private Campagne $campagne;

    private CampagneJournee $journee;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();

        $this->campagne = Campagne::create([
            'type' => 'zakat_el_fitr',
            'statut' => 'preparation',
            'date_livraison' => '2026-11-10',
            'poids_moyen_kg' => 5,
            'hq_latitude' => 0.0,
            'hq_longitude' => 0.0,
        ]);
        $this->journee = $this->campagne->ajouterJournee('2026-11-10');
    }

    private function gestionnaire()
    {
        return $this->creerPersonne(['gestionnaire']);
    }

    private function livraison(array $surcharge = []): Livraison
    {
        $famille = Famille::factory()->create(['email' => 'f' . uniqid() . '@amana-test.fr', 'langue' => 'fr']);
        $famille->latitude = 0.01;
        $famille->longitude = 0.01;
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

    public function test_demarrer_passe_la_campagne_en_cours(): void
    {
        $this->livraison();

        $this->actingAs($this->gestionnaire())->postJson(route('livraison.campagnes.demarrer', $this->campagne))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('campagne.statut', 'en_cours');

        $this->assertSame('en_cours', $this->campagne->fresh()->statut);
    }

    public function test_demarrer_exige_au_moins_une_famille_confirmee(): void
    {
        $this->livraison(['statut_contact' => 'a_contacter']);

        $this->actingAs($this->gestionnaire())->postJson(route('livraison.campagnes.demarrer', $this->campagne))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('preparation', $this->campagne->fresh()->statut);
    }

    public function test_demarrer_nest_possible_qune_fois(): void
    {
        $this->livraison();
        $this->campagne->update(['statut' => 'en_cours']);

        $this->actingAs($this->gestionnaire())->postJson(route('livraison.campagnes.demarrer', $this->campagne))
            ->assertStatus(422);
    }

    public function test_demarrer_cree_les_tournees_imposees_sans_creneau(): void
    {
        $vehicules = $this->creerVehicules();
        $chauffeur = $this->creerBenevole($vehicules['voiture']);
        $imposee = $this->livraison(['id_benevole_impose' => $chauffeur->id]);
        $this->livraison(); // une famille classique : pas de tournée à ce stade

        $this->actingAs($this->gestionnaire())->postJson(route('livraison.campagnes.demarrer', $this->campagne))
            ->assertOk()
            ->assertJsonPath('routes_imposees', 1);

        $route = RouteLivraison::where('id_campagne', $this->campagne->id)->sole();
        $this->assertSame($chauffeur->id, $route->id_benevole);
        $this->assertNull($route->creneau);
        $this->assertSame('assignee', $imposee->fresh()->statut);
    }

    public function test_demarrer_ignore_les_imposees_pas_encore_confirmees(): void
    {
        $vehicules = $this->creerVehicules();
        $chauffeur = $this->creerBenevole($vehicules['voiture']);
        $this->livraison(['id_benevole_impose' => $chauffeur->id, 'statut_contact' => 'a_contacter']);
        $this->livraison();

        $this->actingAs($this->gestionnaire())->postJson(route('livraison.campagnes.demarrer', $this->campagne))
            ->assertOk()
            ->assertJsonPath('routes_imposees', 0);
    }

    public function test_demarrer_planifie_et_envoie_une_fois_les_emails_de_retrait_qg(): void
    {
        $retrait = $this->livraison(['se_deplace' => true]);
        $this->livraison();

        $this->actingAs($this->gestionnaire())->postJson(route('livraison.campagnes.demarrer', $this->campagne))
            ->assertOk()
            ->assertJsonPath('retraits_planifies', 1);

        $this->assertNotNull($retrait->fresh()->heure_arrivee_prevue_hq);
        Notification::assertSentOnDemandTimes(RetraitHqNotification::class, 1);
    }

    public function test_une_campagne_avec_seulement_des_familles_qui_se_deplacent_peut_demarrer(): void
    {
        $this->livraison(['se_deplace' => true]);

        $this->actingAs($this->gestionnaire())->postJson(route('livraison.campagnes.demarrer', $this->campagne))
            ->assertOk()
            ->assertJsonPath('routes_imposees', 0)
            ->assertJsonPath('retraits_planifies', 1);
    }

    public function test_avancement_expose_le_statut_et_la_raison_du_blocage(): void
    {
        $gestionnaire = $this->gestionnaire();

        $this->actingAs($gestionnaire)->getJson(route('livraison.campagnes.avancement', $this->campagne))
            ->assertOk()
            ->assertJsonPath('statut', 'preparation')
            ->assertJsonPath('demarrage_bloque', 'Au moins une famille doit être confirmée pour démarrer la campagne.');

        $this->livraison();

        $this->getJson(route('livraison.campagnes.avancement', $this->campagne))
            ->assertJsonPath('demarrage_bloque', null);
    }

    public function test_rouvrir_revient_en_cours_quand_la_campagne_avait_des_tournees(): void
    {
        $this->campagne->update(['statut' => 'terminee']);
        RouteLivraison::create([
            'id_campagne' => $this->campagne->id,
            'id_benevole' => $this->creerPersonne(['benevole'])->id,
            'id_vehicule_type' => 1,
            'creneau' => '08-10',
            'statut' => 'terminee',
        ]);

        $this->actingAs($this->gestionnaire())->postJson(route('livraison.campagnes.rouvrir', $this->campagne))
            ->assertOk()
            ->assertJsonPath('campagne.statut', 'en_cours');
    }
}
