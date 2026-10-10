<?php
// tests/Feature/Http/GenerationRoutesAssistantTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\BenevoleDisponibilite;
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
 * Assistant « Génération des routes » du hub (06/10/2026) : chauffeurs
 * disponibles par créneau (avec drapeau « déjà en tournée »), récapitulatif,
 * génération automatique restreinte aux chauffeurs choisis, mode personnalisé
 * (un chauffeur, des familles choisies) et familles proposées.
 */
class GenerationRoutesAssistantTest extends TestCase
{
    use BuildsDisponibiliteFixtures;
    use SeedsCommunFixtures;

    private const CRENEAU = '08-10';

    private Campagne $campagne;

    private CampagneJournee $journee;

    /** @var array{voiture: int, utilitaire: int, sans_permis: int, non_vehicule: int} */
    private array $vehicules;

    protected function setUp(): void
    {
        parent::setUp();
        // Heure figée (09/10/2026) : le mode automatique refuse les créneaux déjà terminés,
        // la journée de ces tests (10/11/2026) doit rester dans le futur quelle que soit la date d'exécution.
        $this->travelTo('2026-10-01 09:00:00');
        Notification::fake();
        $this->chargerRolesFamilles();
        $this->vehicules = $this->creerVehicules();
        [$this->campagne, $this->journee] = $this->creerCampagneAvecJournee();
    }

    private function gestionnaire()
    {
        return $this->creerPersonne(['gestionnaire']);
    }

    private function chauffeur(string $creneau = self::CRENEAU, ?int $idVehicule = null)
    {
        $benevole = $this->creerBenevole($idVehicule ?? $this->vehicules['voiture']);
        $dispo = BenevoleDisponibilite::create([
            'id_personne' => $benevole->id,
            'id_campagne_journee' => $this->journee->id,
            'statut' => 'confirme',
        ]);
        $dispo->creneaux()->create(['creneau' => $creneau]);

        return $benevole;
    }

    private function livraison(array $surcharge = [], string $creneau = self::CRENEAU): Livraison
    {
        $famille = Famille::factory()->create();
        $famille->latitude = 0.01;
        $famille->longitude = 0.01;
        $famille->save();

        $livraison = Livraison::create(array_merge([
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
        $livraison->creneaux()->create(['creneau' => $creneau]);

        return $livraison;
    }

    private function parametres(array $extra = []): array
    {
        return array_merge(['id_campagne_journee' => $this->journee->id, 'creneau' => self::CRENEAU], $extra);
    }

    // ── Chauffeurs disponibles ───────────────────────────────────────────

    public function test_les_chauffeurs_disponibles_listent_vehicule_capacite_et_exclus_les_autres_creneaux(): void
    {
        $present = $this->chauffeur();
        $this->chauffeur('16-18');
        $this->chauffeur(self::CRENEAU, $this->vehicules['sans_permis']); // capacité nulle : jamais proposé

        $reponse = $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.campagnes.chauffeurs-disponibles', $this->campagne) . '?' . http_build_query($this->parametres()))
            ->assertOk()
            ->assertJsonCount(1);

        $reponse->assertJsonPath('0.id_personne', $present->id)
            ->assertJsonPath('0.vehicule', 'Voiture')
            ->assertJsonPath('0.occupe', false);
        $this->assertEquals(200, $reponse->json('0.capacite_kg'));
    }

    public function test_un_chauffeur_deja_en_tournee_sur_le_creneau_est_marque_occupe(): void
    {
        $occupe = $this->chauffeur();
        $libre = $this->chauffeur();
        $route = RouteLivraison::create([
            'id_campagne' => $this->campagne->id,
            'id_campagne_journee' => $this->journee->id,
            'id_benevole' => $occupe->id,
            'id_vehicule_type' => $this->vehicules['voiture'],
            'creneau' => self::CRENEAU,
            'statut' => 'planifiee',
        ]);

        $liste = $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.campagnes.chauffeurs-disponibles', $this->campagne) . '?' . http_build_query($this->parametres()))
            ->assertOk()->json();

        $parId = collect($liste)->keyBy('id_personne');
        $this->assertTrue($parId[$occupe->id]['occupe']);
        $this->assertSame($route->id, $parId[$occupe->id]['id_route']);
        $this->assertFalse($parId[$libre->id]['occupe']);
        // Les libres passent avant les occupés.
        $this->assertSame($libre->id, $liste[0]['id_personne']);
    }

    public function test_une_route_annulee_ne_rend_pas_le_chauffeur_occupe(): void
    {
        $chauffeur = $this->chauffeur();
        RouteLivraison::create([
            'id_campagne' => $this->campagne->id,
            'id_campagne_journee' => $this->journee->id,
            'id_benevole' => $chauffeur->id,
            'id_vehicule_type' => $this->vehicules['voiture'],
            'creneau' => self::CRENEAU,
            'statut' => 'annulee',
        ]);

        $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.campagnes.chauffeurs-disponibles', $this->campagne) . '?' . http_build_query($this->parametres()))
            ->assertJsonPath('0.occupe', false);
    }

    public function test_les_chauffeurs_disponibles_exigent_journee_et_creneau(): void
    {
        $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.campagnes.chauffeurs-disponibles', $this->campagne))
            ->assertStatus(422);
    }

    // ── Récapitulatif ────────────────────────────────────────────────────

    public function test_lapercu_compte_familles_poids_et_capacite_des_chauffeurs_choisis(): void
    {
        $choisi = $this->chauffeur();
        $this->chauffeur(); // disponible mais pas choisi
        $this->livraison();
        $this->livraison();
        $this->livraison([], '16-18');                         // autre créneau : hors récapitulatif
        $this->livraison(['se_deplace' => true]);              // retrait QG : jamais livrée
        $this->livraison(['statut_contact' => 'a_contacter']); // pas confirmée

        $apercu = $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.campagnes.apercu-generation', $this->campagne) . '?' . http_build_query($this->parametres(['ids_benevoles' => [$choisi->id]])))
            ->assertOk()
            ->json();

        $this->assertEquals(
            ['familles' => 2, 'poids_kg' => 20, 'capacite_kg' => 200, 'sans_coordonnees' => 0, 'chauffeurs' => 1],
            $apercu,
        );
    }

    public function test_lapercu_ne_compte_pas_les_familles_imposees(): void
    {
        $choisi = $this->chauffeur();
        $imposeur = $this->creerBenevole($this->vehicules['voiture']);
        $this->livraison(['id_benevole_impose' => $imposeur->id]);
        $this->livraison();

        $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.campagnes.apercu-generation', $this->campagne) . '?' . http_build_query($this->parametres(['ids_benevoles' => [$choisi->id]])))
            ->assertJsonPath('familles', 1);
    }

    // ── Génération automatique : garde-fous ──────────────────────────────

    public function test_la_generation_exige_une_campagne_demarree(): void
    {
        $this->campagne->update(['statut' => 'preparation']);
        $chauffeur = $this->chauffeur();
        $this->livraison();

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.campagnes.generer-routes', $this->campagne), $this->parametres(['ids_benevoles' => [$chauffeur->id]]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Démarrez la campagne avant de générer les routes.');

        $this->assertSame(0, RouteLivraison::where('id_campagne', $this->campagne->id)->count());
    }

    public function test_la_generation_exige_au_moins_une_famille_confirmee_sur_le_creneau(): void
    {
        $chauffeur = $this->chauffeur();
        $this->livraison(['statut_contact' => 'a_contacter']);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.campagnes.generer-routes', $this->campagne), $this->parametres(['ids_benevoles' => [$chauffeur->id]]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Aucune famille confirmée en attente de tournée pour ce créneau.');
    }

    public function test_la_generation_exige_un_chauffeur_reellement_disponible(): void
    {
        $this->chauffeur();
        $absent = $this->creerBenevole($this->vehicules['voiture']); // pas de disponibilité sur ce créneau
        $this->livraison();

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.campagnes.generer-routes', $this->campagne), $this->parametres(['ids_benevoles' => [$absent->id]]))
            ->assertStatus(422);
    }

    public function test_la_generation_valide_ses_parametres(): void
    {
        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.campagnes.generer-routes', $this->campagne), ['creneau' => 'minuit'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id_campagne_journee', 'creneau', 'ids_benevoles']);
    }

    public function test_la_generation_cree_une_tournee_pour_le_chauffeur_choisi_seulement(): void
    {
        $choisi = $this->chauffeur();
        $autre = $this->chauffeur();
        $this->livraison();
        $this->livraison();

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.campagnes.generer-routes', $this->campagne), $this->parametres(['ids_benevoles' => [$choisi->id]]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $benevoles = RouteLivraison::where('id_campagne', $this->campagne->id)->pluck('id_benevole')->all();
        $this->assertContains($choisi->id, $benevoles);
        $this->assertNotContains($autre->id, $benevoles);
        $this->assertSame(self::CRENEAU, RouteLivraison::where('id_benevole', $choisi->id)->value('creneau'));
    }

    public function test_la_generation_ne_renvoie_aucun_email_de_retrait_qg(): void
    {
        $chauffeur = $this->chauffeur();
        $this->livraison();
        $this->livraison(['se_deplace' => true]);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.campagnes.generer-routes', $this->campagne), $this->parametres(['ids_benevoles' => [$chauffeur->id]]))
            ->assertOk();

        Notification::assertSentOnDemandTimes(RetraitHqNotification::class, 0);
    }

    // ── Mode personnalisé ────────────────────────────────────────────────

    public function test_le_mode_personnalise_cree_une_tournee_sur_la_journee_et_le_creneau_choisis(): void
    {
        $chauffeur = $this->chauffeur();
        $a = $this->livraison();
        $b = $this->livraison();

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.routes.personnalisee', $this->campagne), $this->parametres([
                'id_benevole' => $chauffeur->id,
                'ids_livraisons' => [$a->id, $b->id],
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $route = RouteLivraison::where('id_benevole', $chauffeur->id)->sole();
        $this->assertSame($this->journee->id, $route->id_campagne_journee);
        $this->assertSame(self::CRENEAU, $route->creneau);
        $this->assertSame($this->vehicules['voiture'], $route->id_vehicule_type);
        $this->assertCount(2, $route->etapes);
    }

    public function test_le_mode_personnalise_refuse_un_chauffeur_deja_en_tournee_sur_ce_creneau(): void
    {
        $chauffeur = $this->chauffeur();
        $a = $this->livraison();
        $b = $this->livraison();
        $payload = $this->parametres(['id_benevole' => $chauffeur->id, 'ids_livraisons' => [$a->id]]);

        $this->actingAs($this->gestionnaire())->postJson(route('livraison.routes.personnalisee', $this->campagne), $payload)->assertOk();

        $payload['ids_livraisons'] = [$b->id];
        $this->postJson(route('livraison.routes.personnalisee', $this->campagne), $payload)
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_le_mode_personnalise_refuse_un_chauffeur_non_disponible(): void
    {
        $absent = $this->creerBenevole($this->vehicules['voiture']);
        $livraison = $this->livraison();

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.routes.personnalisee', $this->campagne), $this->parametres([
                'id_benevole' => $absent->id,
                'ids_livraisons' => [$livraison->id],
            ]))
            ->assertStatus(422);
    }

    public function test_le_mode_personnalise_exige_une_campagne_demarree(): void
    {
        $this->campagne->update(['statut' => 'preparation']);
        $chauffeur = $this->chauffeur();
        $livraison = $this->livraison();

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.routes.personnalisee', $this->campagne), $this->parametres([
                'id_benevole' => $chauffeur->id,
                'ids_livraisons' => [$livraison->id],
            ]))
            ->assertStatus(422);
    }

    public function test_le_mode_personnalise_n_embarque_jamais_une_famille_qui_se_deplace_ni_une_imposee(): void
    {
        $chauffeur = $this->chauffeur();
        $normale = $this->livraison();
        $retrait = $this->livraison(['se_deplace' => true]);
        $imposee = $this->livraison(['id_benevole_impose' => $this->creerBenevole($this->vehicules['voiture'])->id]);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.routes.personnalisee', $this->campagne), $this->parametres([
                'id_benevole' => $chauffeur->id,
                'ids_livraisons' => [$normale->id, $retrait->id, $imposee->id],
            ]))
            ->assertOk();

        $route = RouteLivraison::where('id_benevole', $chauffeur->id)->sole();
        $this->assertSame([$normale->id], $route->etapes->pluck('id_livraison')->all());
        $this->assertSame('non_assignee', $retrait->fresh()->statut);
        $this->assertSame('non_assignee', $imposee->fresh()->statut);
    }

    // ── Familles proposées au mode personnalisé ──────────────────────────

    public function test_le_tableau_des_familles_filtre_par_creneau_et_exclut_retrait_qg_et_imposees(): void
    {
        $dansLeCreneau = $this->livraison();
        $this->livraison([], '16-18');
        $this->livraison(['se_deplace' => true]);
        $this->livraison(['id_benevole_impose' => $this->creerBenevole($this->vehicules['voiture'])->id]);

        $url = route('livraison.campagnes.non-couvertes-tableau', $this->campagne);
        $gestionnaire = $this->gestionnaire();

        $ids = fn(array $params) => collect($this->actingAs($gestionnaire)->getJson($url . '?' . http_build_query($params))->assertOk()->json('data'))
            ->pluck('id_livraison')->all();

        $this->assertSame([$dansLeCreneau->id], $ids($this->parametres(['se_deplace' => 0, 'sans_imposees' => 1])));
        // Case « autres créneaux » cochée : le créneau n'est plus transmis.
        $this->assertCount(2, $ids(['id_campagne_journee' => $this->journee->id, 'se_deplace' => 0, 'sans_imposees' => 1]));
    }

    public function test_le_tableau_des_familles_expose_le_poids_du_colis(): void
    {
        $this->livraison(['poids_kg' => 12.5]);

        $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.campagnes.non-couvertes-tableau', $this->campagne) . '?' . http_build_query($this->parametres(['se_deplace' => 0, 'sans_imposees' => 1])))
            ->assertJsonPath('data.0.poids_kg', 12.5);
    }
    // ── Créneaux courants et restants uniquement (09/10/2026) ────────────

    public function test_le_jour_meme_un_creneau_deja_termine_est_refuse_a_lapercu_et_a_la_generation(): void
    {
        $this->travelTo('2026-11-10 11:00:00'); // journée de la campagne = aujourd'hui, 08-10 terminé
        $chauffeur = $this->chauffeur();
        $this->livraison();

        $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.campagnes.apercu-generation', $this->campagne) . '?' . http_build_query($this->parametres(['ids_benevoles' => [$chauffeur->id]])))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->postJson(route('livraison.campagnes.generer-routes', $this->campagne), $this->parametres(['ids_benevoles' => [$chauffeur->id]]))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, RouteLivraison::count());
    }

    public function test_le_jour_meme_le_creneau_en_cours_reste_generable(): void
    {
        $this->travelTo('2026-11-10 09:15:00'); // 08-10 est le créneau en cours
        $chauffeur = $this->chauffeur();
        $this->livraison();

        $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.campagnes.apercu-generation', $this->campagne) . '?' . http_build_query($this->parametres(['ids_benevoles' => [$chauffeur->id]])))
            ->assertOk()
            ->assertJsonPath('familles', 1);
    }

    public function test_une_journee_passee_naccepte_plus_aucune_generation_automatique(): void
    {
        $this->travelTo('2026-11-11 08:00:00');
        $chauffeur = $this->chauffeur();

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.campagnes.generer-routes', $this->campagne), $this->parametres(['ids_benevoles' => [$chauffeur->id]]))
            ->assertStatus(422);
    }
}
