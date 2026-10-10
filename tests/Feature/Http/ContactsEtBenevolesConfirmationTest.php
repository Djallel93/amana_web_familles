<?php
// tests/Feature/Http/ContactsEtBenevolesConfirmationTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\BenevoleDisponibilite;
use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteLivraison;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsDisponibiliteFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * 06/10/2026 — enregistrements interdits tant qu'ils sont incomplets :
 *   - Suivi des contacts : « Enregistrer la confirmation » exige au moins un
 *     créneau ET un se_deplace explicite (plus de « Non » implicite) ;
 *   - Suivi des bénévoles : « Enregistrer » exige au moins un créneau ;
 * et « Prendre en charge » (chauffeur imposé) depuis Suivi des contacts.
 * Les boutons grisés côté Vue ne sont pas la seule protection : le serveur
 * applique les mêmes règles.
 */
class ContactsEtBenevolesConfirmationTest extends TestCase
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

    private function livraison(array $surcharge = []): Livraison
    {
        $famille = Famille::factory()->create();
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
            'statut_contact' => 'a_contacter',
            'se_deplace' => false,
        ], $surcharge));
    }

    /** Disponibilité confirmée du chauffeur pour la journée de la campagne (obligatoire pour « Prendre en charge », 09/10/2026). */
    private function confirmerChauffeur(int $idPersonne, array $attributs = []): BenevoleDisponibilite
    {
        $dispo = BenevoleDisponibilite::create(array_merge([
            'id_personne' => $idPersonne,
            'id_campagne_journee' => $this->journee->id,
            'statut' => 'confirme',
        ], $attributs));
        $dispo->creneaux()->create(['creneau' => '08-10']);

        return $dispo;
    }

    // ── Suivi des contacts : confirmation ────────────────────────────────

    public function test_la_confirmation_est_refusee_sans_se_deplace(): void
    {
        $livraison = $this->livraison();

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.contacts.contacter-manuel', $livraison), [
                'statut_contact' => 'confirme',
                'creneaux' => ['08-10'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['se_deplace']);

        $this->assertSame('a_contacter', $livraison->fresh()->statut_contact);
    }

    public function test_la_confirmation_est_refusee_sans_creneau(): void
    {
        $livraison = $this->livraison();

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.contacts.contacter-manuel', $livraison), [
                'statut_contact' => 'confirme',
                'se_deplace' => false,
                'creneaux' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['creneaux']);

        $this->assertSame('a_contacter', $livraison->fresh()->statut_contact);
    }

    public function test_la_confirmation_complete_est_enregistree_avec_un_se_deplace_explicite(): void
    {
        $livraison = $this->livraison();

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.contacts.contacter-manuel', $livraison), [
                'statut_contact' => 'confirme',
                'se_deplace' => true,
                'creneaux' => ['08-10', '10-12'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $livraison->refresh();
        $this->assertSame('confirme', $livraison->statut_contact);
        $this->assertTrue($livraison->se_deplace);
        $this->assertCount(2, $livraison->creneaux);
    }

    public function test_les_autres_statuts_ne_demandent_ni_creneau_ni_se_deplace(): void
    {
        $livraison = $this->livraison();

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.contacts.contacter-manuel', $livraison), ['statut_contact' => 'injoignable'])
            ->assertOk();

        $this->assertSame('injoignable', $livraison->fresh()->statut_contact);
        $this->assertNull($livraison->fresh()->motif_statut_contact);
    }

    // ── Motif obligatoire pour archiver / rejeter (09/10/2026) ───────────

    public function test_archiver_ou_rejeter_exige_un_motif(): void
    {
        $gestionnaire = $this->gestionnaire();

        foreach (['archive', 'rejetee'] as $statut) {
            $livraison = $this->livraison();

            $this->actingAs($gestionnaire)
                ->postJson(route('livraison.contacts.contacter-manuel', $livraison), ['statut_contact' => $statut])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['motif']);

            $this->postJson(route('livraison.contacts.contacter-manuel', $livraison), ['statut_contact' => $statut, 'motif' => '   '])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['motif']);

            $this->assertSame('a_contacter', $livraison->fresh()->statut_contact);
        }
    }

    public function test_le_motif_est_enregistre_avec_larchivage_ou_le_rejet(): void
    {
        $archivee = $this->livraison();
        $rejetee = $this->livraison();
        $gestionnaire = $this->gestionnaire();

        $this->actingAs($gestionnaire)
            ->postJson(route('livraison.contacts.contacter-manuel', $archivee), ['statut_contact' => 'archive', 'motif' => '  Déménagé hors zone  '])
            ->assertOk();
        $this->postJson(route('livraison.contacts.contacter-manuel', $rejetee), ['statut_contact' => 'rejetee', 'motif' => 'Ne répond plus aux critères'])
            ->assertOk();

        $this->assertSame('archive', $archivee->fresh()->statut_contact);
        $this->assertSame('Déménagé hors zone', $archivee->fresh()->motif_statut_contact);
        $this->assertSame('Ne répond plus aux critères', $rejetee->fresh()->motif_statut_contact);
    }

    public function test_la_file_de_contacts_expose_le_motif(): void
    {
        $this->livraison(['statut_contact' => 'archive', 'motif_statut_contact' => 'Doublon']);

        $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.contacts.queue', ['id_campagne' => $this->campagne->id]))
            ->assertOk()
            ->assertJsonPath('data.0.motif_statut_contact', 'Doublon');
    }

    public function test_confirmer_une_famille_imposee_apres_le_demarrage_lajoute_a_la_tournee_du_chauffeur(): void
    {
        $chauffeur = $this->creerBenevole($this->vehicules['voiture']);
        $livraison = $this->livraison(['id_benevole_impose' => $chauffeur->id]);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.contacts.contacter-manuel', $livraison), [
                'statut_contact' => 'confirme',
                'se_deplace' => false,
                'creneaux' => ['08-10'],
            ])
            ->assertOk();

        $route = RouteLivraison::where('id_benevole', $chauffeur->id)->sole();
        $this->assertNull($route->creneau);
        $this->assertSame('assignee', $livraison->fresh()->statut);
    }

    // ── Suivi des bénévoles ──────────────────────────────────────────────

    public function test_enregistrer_une_disponibilite_confirmee_sans_creneau_est_refuse(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.campagnes.benevoles.mettre-a-jour', [$this->campagne, $benevole->id]), [
                'id_campagne_journee' => $this->journee->id,
                'statut' => 'confirme',
                'creneaux' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['creneaux']);

        $this->assertSame(0, BenevoleDisponibilite::where('id_personne', $benevole->id)->count());
    }

    public function test_enregistrer_une_disponibilite_avec_creneaux_fonctionne(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.campagnes.benevoles.mettre-a-jour', [$this->campagne, $benevole->id]), [
                'id_campagne_journee' => $this->journee->id,
                'statut' => 'confirme',
                'creneaux' => ['08-10'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $dispo = BenevoleDisponibilite::where('id_personne', $benevole->id)->sole();
        $this->assertSame(['08-10'], $dispo->creneaux->pluck('creneau')->all());
    }

    public function test_repasser_non_confirme_nexige_aucun_creneau(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.campagnes.benevoles.mettre-a-jour', [$this->campagne, $benevole->id]), [
                'id_campagne_journee' => $this->journee->id,
                'statut' => 'non_confirme',
            ])
            ->assertOk();
    }

    // ── Prendre en charge ────────────────────────────────────────────────

    public function test_prendre_en_charge_impose_la_famille_et_cree_la_tournee(): void
    {
        $chauffeur = $this->creerBenevole($this->vehicules['voiture']);
        $this->confirmerChauffeur($chauffeur->id);
        $livraison = $this->livraison(['statut_contact' => 'confirme']);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.contacts.prise-en-charge', $livraison), ['id_benevole' => $chauffeur->id])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('id_benevole_impose', $chauffeur->id)
            ->assertJsonPath('statut', 'assignee');

        $this->assertSame(1, RouteLivraison::where('id_benevole', $chauffeur->id)->whereNull('creneau')->count());
    }

    public function test_prendre_en_charge_renvoie_le_message_de_refus_quand_la_famille_se_deplace(): void
    {
        $chauffeur = $this->creerBenevole($this->vehicules['voiture']);
        $this->confirmerChauffeur($chauffeur->id);
        $livraison = $this->livraison(['statut_contact' => 'confirme', 'se_deplace' => true]);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.contacts.prise-en-charge', $livraison), ['id_benevole' => $chauffeur->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['message']);

        $this->assertNull($livraison->fresh()->id_benevole_impose);
    }

    public function test_un_id_benevole_vide_retire_limposition(): void
    {
        $chauffeur = $this->creerBenevole($this->vehicules['voiture']);
        $this->confirmerChauffeur($chauffeur->id);
        $livraison = $this->livraison(['statut_contact' => 'confirme']);
        $gestionnaire = $this->gestionnaire();

        $this->actingAs($gestionnaire)->postJson(route('livraison.contacts.prise-en-charge', $livraison), ['id_benevole' => $chauffeur->id])->assertOk();
        $this->postJson(route('livraison.contacts.prise-en-charge', $livraison), ['id_benevole' => null])
            ->assertOk()
            ->assertJsonPath('id_benevole_impose', null)
            ->assertJsonPath('statut', 'non_assignee');
    }

    public function test_se_deplace_est_refuse_pour_une_famille_imposee_via_lendpoint_de_correction(): void
    {
        $chauffeur = $this->creerBenevole($this->vehicules['voiture']);
        $livraison = $this->livraison(['statut_contact' => 'confirme', 'id_benevole_impose' => $chauffeur->id]);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.contacts.se-deplace', $livraison), ['se_deplace' => true])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertFalse($livraison->fresh()->se_deplace);
    }

    public function test_la_file_de_contacts_expose_le_chauffeur_impose(): void
    {
        $chauffeur = $this->creerBenevole($this->vehicules['voiture']);
        $this->livraison(['statut_contact' => 'confirme', 'id_benevole_impose' => $chauffeur->id]);

        $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.contacts.queue', ['id_campagne' => $this->campagne->id]))
            ->assertOk()
            ->assertJsonPath('data.0.id_benevole_impose', $chauffeur->id)
            ->assertJsonPath('data.0.benevole_impose.id', $chauffeur->id);
    }
    // ── Chauffeurs confirmés uniquement (09/10/2026) ─────────────────────

    public function test_prendre_en_charge_est_refuse_pour_un_benevole_non_confirme_sur_la_journee(): void
    {
        $chauffeur = $this->creerBenevole($this->vehicules['voiture']);
        $this->confirmerChauffeur($chauffeur->id, ['statut' => 'non_confirme']);
        $livraison = $this->livraison(['statut_contact' => 'confirme']);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.contacts.prise-en-charge', $livraison), ['id_benevole' => $chauffeur->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertNull($livraison->fresh()->id_benevole_impose);
    }

    public function test_prendre_en_charge_est_refuse_pour_un_benevole_confirme_une_autre_journee(): void
    {
        $chauffeur = $this->creerBenevole($this->vehicules['voiture']);
        $autreJournee = $this->campagne->ajouterJournee('2026-11-11');
        BenevoleDisponibilite::create(['id_personne' => $chauffeur->id, 'id_campagne_journee' => $autreJournee->id, 'statut' => 'confirme']);
        $livraison = $this->livraison(['statut_contact' => 'confirme']);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.contacts.prise-en-charge', $livraison), ['id_benevole' => $chauffeur->id])
            ->assertStatus(422);
    }

    public function test_le_selecteur_ne_propose_que_les_chauffeurs_confirmes_pour_la_journee(): void
    {
        $confirme = $this->creerBenevole($this->vehicules['voiture']);
        $this->confirmerChauffeur($confirme->id);
        $nonConfirme = $this->creerBenevole($this->vehicules['voiture']);
        $this->confirmerChauffeur($nonConfirme->id, ['statut' => 'non_confirme']);
        $sansDispo = $this->creerBenevole($this->vehicules['voiture']);
        $sansVehicule = $this->creerBenevole($this->vehicules['non_vehicule']);
        $this->confirmerChauffeur($sansVehicule->id);
        // Véhicule déclaré pour la journée : prime sur celui du profil.
        $utilitaireJour = $this->creerBenevole($this->vehicules['voiture']);
        $this->confirmerChauffeur($utilitaireJour->id, ['vehicule_confirme' => false, 'id_vehicule_type' => $this->vehicules['utilitaire']]);

        $reponse = $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.personnes.recherche', [
                'tous' => 1,
                'role' => 'benevole',
                'avec_vehicule' => 1,
                'id_campagne_journee' => $this->journee->id,
            ]))
            ->assertOk();

        $ids = collect($reponse->json())->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$confirme->id, $utilitaireJour->id], $ids);
        $this->assertNotContains($nonConfirme->id, $ids);
        $this->assertNotContains($sansDispo->id, $ids);
        $this->assertNotContains($sansVehicule->id, $ids);

        $parId = collect($reponse->json())->keyBy('id');
        $this->assertSame($this->vehicules['utilitaire'], $parId[$utilitaireJour->id]['id_vehicule_type']);
    }
    // ── Retirer / réinitialiser (09/10/2026) ─────────────────────────────

    public function test_retirer_supprime_la_livraison(): void
    {
        $livraison = $this->livraison(['statut_contact' => 'confirme']);

        $this->actingAs($this->gestionnaire())
            ->deleteJson(route('livraison.contacts.retirer', $livraison))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('livraisons', ['id' => $livraison->id]);
    }

    public function test_retirer_renvoie_le_message_de_refus_pour_une_famille_livree(): void
    {
        $livraison = $this->livraison(['statut_contact' => 'confirme', 'statut' => 'livree']);

        $this->actingAs($this->gestionnaire())
            ->deleteJson(route('livraison.contacts.retirer', $livraison))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['message']);

        $this->assertDatabaseHas('livraisons', ['id' => $livraison->id]);
    }

    public function test_reinitialiser_remet_une_famille_confirmee_a_contacter(): void
    {
        $livraison = $this->livraison(['statut_contact' => 'confirme']);
        $livraison->creneaux()->create(['creneau' => '08-10']);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.contacts.reinitialiser', $livraison))
            ->assertOk()
            ->assertJsonPath('statut_contact', 'a_contacter');

        $this->assertSame('a_contacter', $livraison->fresh()->statut_contact);
        $this->assertCount(0, $livraison->fresh()->creneaux);
    }

    public function test_reinitialiser_est_refuse_pour_une_famille_pas_encore_confirmee(): void
    {
        $livraison = $this->livraison(['statut_contact' => 'injoignable']);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.contacts.reinitialiser', $livraison))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_retirer_et_reinitialiser_sont_reserves_aux_gestionnaires(): void
    {
        $livraison = $this->livraison(['statut_contact' => 'confirme']);

        $this->actingAs($this->creerPersonne(['benevole']))
            ->deleteJson(route('livraison.contacts.retirer', $livraison))
            ->assertRedirect(); // middleware d'équipe : un non-gestionnaire est renvoyé à l'accueil
        $this->postJson(route('livraison.contacts.reinitialiser', $livraison))->assertRedirect();
        $this->assertDatabaseHas('livraisons', ['id' => $livraison->id, 'statut_contact' => 'confirme']);
    }
}
