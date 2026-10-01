<?php
// tests/Feature/Http/PersonnesIndexEtDisponibiliteTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\BenevoleDisponibilite;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsDisponibiliteFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Lot du 01/10/2026 sur admin/personnes : recherche + filtre par rôle +
 * compteurs par rôle (index), puis fiche personne — section « Par campagne /
 * journée » (véhicule/permis/secteurs par journée, enregistrement valant
 * confirmation) et bouton retour.
 */
class PersonnesIndexEtDisponibiliteTest extends TestCase
{
    use SeedsCommunFixtures;
    use BuildsDisponibiliteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
    }

    // ── index() — compteurs, filtre, recherche ──────────────────────────

    public function test_les_compteurs_sont_les_totaux_complets_par_role(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $this->creerPersonne(['gestionnaire']);
        $this->creerPersonne(['membre']);
        $this->creerPersonne(['membre']);
        $this->creerPersonne(['benevole']);
        $this->creerPersonne(['gestionnaire_externe']);

        $reponse = $this->actingAs($admin)->get(route('admin.personnes.index'))->assertOk();

        $this->assertSame(6, $reponse->viewData('total'));
        $this->assertSame(
            ['admin' => 1, 'gestionnaire' => 1, 'membre' => 2, 'benevole' => 1, 'gestionnaire_externe' => 1, 'aucun' => 0],
            $reponse->viewData('compteurs'),
        );
        $reponse->assertSee('Gestionnaire externe');
    }

    public function test_le_filtre_par_role_reduit_la_liste_mais_pas_les_compteurs(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $this->creerPersonne(['membre']);
        $this->creerPersonne(['membre']);
        $this->creerPersonne(['benevole']);

        $reponse = $this->actingAs($admin)->get(route('admin.personnes.index', ['role' => 'membre']))->assertOk();

        $this->assertCount(2, $reponse->viewData('personnes'));
        $this->assertSame(2, $reponse->viewData('compteurs')['membre']);
        $this->assertSame(1, $reponse->viewData('compteurs')['benevole']);
        $this->assertSame(4, $reponse->viewData('total'));
    }

    public function test_un_role_de_filtre_inconnu_est_ignore(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $this->creerPersonne(['membre']);

        $reponse = $this->actingAs($admin)->get(route('admin.personnes.index', ['role' => 'root']))->assertOk();

        $this->assertCount(2, $reponse->viewData('personnes'));
        $this->assertSame('', $reponse->viewData('roleFiltre'));
    }

    public function test_la_recherche_ignore_la_casse_et_les_accents(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $this->creerPersonne(['membre'], ['nom' => 'Dupont', 'prenom' => 'Élodie', 'email' => 'elodie@example.fr']);
        $this->creerPersonne(['membre'], ['nom' => 'Martin', 'prenom' => 'Zoé', 'email' => 'zoe@example.fr', 'telephone' => '0611223344']);

        $parNom = $this->actingAs($admin)->get(route('admin.personnes.index', ['q' => 'ELODIE']))->viewData('personnes');
        $this->assertCount(1, $parNom);
        $this->assertSame('dupont', mb_strtolower($parNom->first()->nom));

        $parTelephone = $this->actingAs($admin)->get(route('admin.personnes.index', ['q' => '061122']))->viewData('personnes');
        $this->assertCount(1, $parTelephone);
        $this->assertSame('martin', mb_strtolower($parTelephone->first()->nom));

        // Plusieurs mots : tous doivent correspondre, dans n'importe quel ordre.
        $parNomComplet = $this->actingAs($admin)->get(route('admin.personnes.index', ['q' => 'dupont elodie']))->viewData('personnes');
        $this->assertCount(1, $parNomComplet);
    }

    public function test_recherche_et_filtre_se_combinent_et_signalent_l_absence_de_resultat(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $this->creerPersonne(['membre'], ['nom' => 'Dupont']);
        $this->creerPersonne(['benevole'], ['nom' => 'Dupont']);

        $reponse = $this->actingAs($admin)
            ->get(route('admin.personnes.index', ['q' => 'dupont', 'role' => 'benevole']))
            ->assertOk();
        $this->assertCount(1, $reponse->viewData('personnes'));

        $this->actingAs($admin)
            ->get(route('admin.personnes.index', ['q' => 'introuvable']))
            ->assertOk()
            ->assertSee('Aucun résultat');
    }

    // ── edit() ──────────────────────────────────────────────────────────

    public function test_la_fiche_d_un_benevole_propose_la_section_par_journee_sans_sans_permis(): void
    {
        $vehicules = $this->creerVehicules();
        $this->creerGeographie();
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $benevole = $this->creerBenevole($vehicules['voiture']);
        $this->creerCampagneAvecJournee();

        $this->actingAs($gestionnaire)
            ->get(route('admin.personnes.edit', $benevole->id))
            ->assertOk()
            ->assertSee('Par campagne / journée')
            ->assertSee('Titulaire du permis de conduire')
            ->assertSee('Nantes')
            ->assertDontSee('>Sans permis</option>', false)
            // Le profil n'édite plus permis/véhicule/secteurs.
            ->assertDontSee('name="permis"', false)
            ->assertDontSee('name="secteurs[]"', false);
    }

    public function test_la_fiche_sans_profil_benevole_n_a_pas_la_section(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $membre = $this->creerPersonne(['membre']);

        $this->actingAs($gestionnaire)
            ->get(route('admin.personnes.edit', $membre->id))
            ->assertOk()
            ->assertDontSee('Par campagne / journée');
    }

    public function test_la_journee_du_lien_retour_est_preselectionnee_et_les_plus_recentes_passent_en_premier(): void
    {
        $vehicules = $this->creerVehicules();
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $benevole = $this->creerBenevole($vehicules['voiture']);
        [, $ancienne] = $this->creerCampagneAvecJournee('2026-03-01');
        [, $recente] = $this->creerCampagneAvecJournee('2026-11-10');

        $defaut = $this->actingAs($gestionnaire)->get(route('admin.personnes.edit', $benevole->id));
        $this->assertSame($recente->id, $defaut->viewData('journeeSelectionnee'));
        $this->assertSame(
            [$recente->id, $ancienne->id],
            collect($defaut->viewData('groupesJournees'))->flatMap(fn ($g) => collect($g['journees'])->pluck('id'))->all(),
        );

        $demandee = $this->actingAs($gestionnaire)
            ->get(route('admin.personnes.edit', ['id' => $benevole->id, 'id_campagne_journee' => $ancienne->id]));
        $this->assertSame($ancienne->id, $demandee->viewData('journeeSelectionnee'));
    }

    public function test_le_bouton_retour_porte_le_nouveau_libelle(): void
    {
        $vehicules = $this->creerVehicules();
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $benevole = $this->creerBenevole($vehicules['voiture']);
        [$campagne] = $this->creerCampagneAvecJournee();

        $this->actingAs($gestionnaire)
            ->get(route('admin.personnes.edit', ['id' => $benevole->id, 'retour' => 'campagne_benevoles', 'id_campagne' => $campagne->id]))
            ->assertOk()
            ->assertSee('Retour à la campagne')
            ->assertDontSee('Retour au suivi des bénévoles');
    }

    // ── majDisponibilite() ──────────────────────────────────────────────

    private function chargeJournee(array $vehicules, array $geo, array $surcharges = []): array
    {
        return array_merge([
            'vehicule_confirme' => false,
            'permis' => true,
            'id_vehicule_type' => $vehicules['utilitaire'],
            'coverage_confirmee' => false,
            'secteurs' => [$geo['a1'], $geo['a2']],
        ], $surcharges);
    }

    public function test_un_gestionnaire_enregistre_la_journee_et_cela_vaut_confirmation_sans_toucher_aux_creneaux(): void
    {
        $vehicules = $this->creerVehicules();
        $geo = $this->creerGeographie();
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $benevole = $this->creerBenevole($vehicules['voiture']);
        [, $journee] = $this->creerCampagneAvecJournee();

        // Disponibilité préexistante non confirmée avec un créneau.
        $existante = BenevoleDisponibilite::create([
            'id_personne' => $benevole->id, 'id_campagne_journee' => $journee->id, 'statut' => 'non_confirme',
        ]);
        $existante->creneaux()->create(['creneau' => '10-12']);

        $this->actingAs($gestionnaire)
            ->putJson(route('admin.personnes.disponibilite.update', ['id' => $benevole->id, 'journee' => $journee->id]), $this->chargeJournee($vehicules, $geo))
            ->assertOk()
            ->assertJson(['success' => true, 'etat' => ['permis' => true, 'id_vehicule_type' => $vehicules['utilitaire']]]);

        $dispo = BenevoleDisponibilite::with(['creneaux', 'secteurs'])->findOrFail($existante->id);
        $this->assertSame('confirme', $dispo->statut);
        $this->assertSame(['10-12'], $dispo->creneaux->pluck('creneau')->all());
        $this->assertCount(2, $dispo->secteurs);
    }

    public function test_enregistrer_une_journee_jamais_repondue_cree_la_disponibilite_confirmee(): void
    {
        $vehicules = $this->creerVehicules();
        $geo = $this->creerGeographie();
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $benevole = $this->creerBenevole($vehicules['voiture']);
        [, $journee] = $this->creerCampagneAvecJournee();

        $this->actingAs($gestionnaire)
            ->putJson(route('admin.personnes.disponibilite.update', ['id' => $benevole->id, 'journee' => $journee->id]), $this->chargeJournee($vehicules, $geo, [
                'permis' => false, 'coverage_confirmee' => true, 'secteurs' => [],
            ]))
            ->assertOk();

        $dispo = BenevoleDisponibilite::where('id_personne', $benevole->id)->firstOrFail();
        $this->assertSame('confirme', $dispo->statut);
        $this->assertSame($vehicules['sans_permis'], (int) $dispo->id_vehicule_type);
        $this->assertTrue($dispo->coverage_confirmee);
    }

    public function test_la_validation_de_la_fiche_refuse_les_contradictions(): void
    {
        $vehicules = $this->creerVehicules();
        $geo = $this->creerGeographie();
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $benevole = $this->creerBenevole($vehicules['voiture']);
        [, $journee] = $this->creerCampagneAvecJournee();
        $url = route('admin.personnes.disponibilite.update', ['id' => $benevole->id, 'journee' => $journee->id]);

        $this->actingAs($gestionnaire)
            ->putJson($url, $this->chargeJournee($vehicules, $geo, ['id_vehicule_type' => $vehicules['sans_permis']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('id_vehicule_type', 'errors');

        $this->actingAs($gestionnaire)
            ->putJson($url, $this->chargeJournee($vehicules, $geo, ['id_vehicule_type' => null]))
            ->assertStatus(422);

        $this->actingAs($gestionnaire)
            ->putJson($url, $this->chargeJournee($vehicules, $geo, ['secteurs' => []]))
            ->assertStatus(422);

        $this->assertSame(0, BenevoleDisponibilite::count());
    }

    public function test_une_personne_sans_profil_benevole_ou_une_journee_inconnue_donne_404(): void
    {
        $vehicules = $this->creerVehicules();
        $geo = $this->creerGeographie();
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $membre = $this->creerPersonne(['membre']);
        $benevole = $this->creerBenevole($vehicules['voiture']);
        [, $journee] = $this->creerCampagneAvecJournee();

        $this->actingAs($gestionnaire)
            ->putJson(route('admin.personnes.disponibilite.update', ['id' => $membre->id, 'journee' => $journee->id]), $this->chargeJournee($vehicules, $geo))
            ->assertNotFound();

        $this->actingAs($gestionnaire)
            ->putJson(route('admin.personnes.disponibilite.update', ['id' => $benevole->id, 'journee' => 999999]), $this->chargeJournee($vehicules, $geo))
            ->assertNotFound();
    }

    public function test_un_simple_benevole_ne_peut_pas_enregistrer_la_journee_d_un_autre(): void
    {
        $vehicules = $this->creerVehicules();
        $geo = $this->creerGeographie();
        $benevole = $this->creerBenevole($vehicules['voiture']);
        $autre = $this->creerBenevole($vehicules['voiture']);
        [, $journee] = $this->creerCampagneAvecJournee();

        $reponse = $this->actingAs($benevole)
            ->putJson(route('admin.personnes.disponibilite.update', ['id' => $autre->id, 'journee' => $journee->id]), $this->chargeJournee($vehicules, $geo));

        $this->assertFalse($reponse->isSuccessful());
        $this->assertSame(0, BenevoleDisponibilite::count());
    }
}
