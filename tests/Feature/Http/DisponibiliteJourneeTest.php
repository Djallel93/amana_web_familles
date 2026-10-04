<?php
// tests/Feature/Http/DisponibiliteJourneeTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\BenevoleDisponibilite;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsDisponibiliteFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Page « Ma disponibilité » du bénévole (01/10/2026) : sections Véhicule /
 * Couverture PAR JOURNÉE, suppression des remarques de couverture, et
 * relecture de la réponse par la file admin « Suivi des bénévoles »
 * (régression : une confirmation doit apparaître « confirmé » dans la file).
 */
class DisponibiliteJourneeTest extends TestCase
{
    use BuildsDisponibiliteFixtures;
    use SeedsCommunFixtures;

    private array $vehicules;

    private array $geo;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
        $this->vehicules = $this->creerVehicules();
        $this->geo = $this->creerGeographie();
    }

    private function charge(array $surcharges = [], int $idJournee = 0): array
    {
        return array_merge([
            'id_campagne_journee' => $idJournee,
            'vehicule_confirme' => false,
            'permis' => true,
            'id_vehicule_type' => $this->vehicules['utilitaire'],
            'coverage_confirmee' => false,
            'secteurs' => [$this->geo['a1'], $this->geo['b1']],
            'creneaux' => ['08-10', '14-16'],
        ], $surcharges);
    }

    public function test_la_page_propose_les_sections_et_n_a_plus_les_remarques(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        [$campagne] = $this->creerCampagneAvecJournee();

        $reponse = $this->actingAs($benevole)->get(route('livraison.benevole.disponibilite.show', $campagne));

        $reponse->assertOk()
            ->assertSee('Véhicule')
            ->assertSee('Couverture')
            ->assertSee('Matin')
            ->assertSee('Après-midi')
            ->assertSee('Nantes')
            ->assertSee('Centre')
            ->assertSee('Utilitaire')
            ->assertDontSee('Remarques sur ma couverture')
            // « Sans permis » n'est plus un choix de la liste.
            ->assertDontSee('>Sans permis</option>', false);
    }

    public function test_confirmer_enregistre_vehicule_et_secteurs_pour_la_journee(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        [$campagne, $journee] = $this->creerCampagneAvecJournee();

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.disponibilite.update', $campagne), $this->charge([], $journee->id))
            ->assertOk()
            ->assertJson(['success' => true]);

        $dispo = BenevoleDisponibilite::with(['secteurs', 'creneaux'])->where('id_personne', $benevole->id)->firstOrFail();
        $this->assertSame('confirme', $dispo->statut);
        $this->assertFalse($dispo->vehicule_confirme);
        $this->assertTrue($dispo->permis);
        $this->assertSame($this->vehicules['utilitaire'], (int) $dispo->id_vehicule_type);
        $this->assertEqualsCanonicalizing([$this->geo['a1'], $this->geo['b1']], $dispo->secteurs->pluck('id_secteur')->map(fn($i) => (int) $i)->all());
        $this->assertEqualsCanonicalizing(['08-10', '14-16'], $dispo->creneaux->pluck('creneau')->all());
    }

    public function test_une_confirmation_apparait_confirmee_dans_la_file_admin(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        $admin = $this->creerPersonne(['admin']);
        [$campagne, $journee] = $this->creerCampagneAvecJournee();

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.disponibilite.update', $campagne), $this->charge([], $journee->id))
            ->assertOk();

        $file = $this->actingAs($admin)
            ->getJson(route('livraison.campagnes.benevoles.queue', ['campagne' => $campagne, 'id_campagne_journee' => $journee->id]))
            ->assertOk()
            ->json('data');

        $ligne = collect($file)->firstWhere('id_personne', $benevole->id);
        $this->assertNotNull($ligne);
        $this->assertSame('confirme', $ligne['statut']);
        $this->assertArrayNotHasKey('coverage_notes', $ligne);
    }

    public function test_meme_que_mon_profil_ne_stocke_ni_vehicule_ni_secteurs(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        [$campagne, $journee] = $this->creerCampagneAvecJournee();

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.disponibilite.update', $campagne), $this->charge([
                'vehicule_confirme' => true, 'permis' => null, 'id_vehicule_type' => null,
                'coverage_confirmee' => true, 'secteurs' => [],
            ], $journee->id))
            ->assertOk();

        $dispo = BenevoleDisponibilite::with('secteurs')->where('id_personne', $benevole->id)->firstOrFail();
        $this->assertTrue($dispo->vehicule_confirme);
        $this->assertNull($dispo->id_vehicule_type);
        $this->assertTrue($dispo->coverage_confirmee);
        $this->assertCount(0, $dispo->secteurs);
    }

    public function test_sans_permis_stocke_la_ligne_sans_permis(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        [$campagne, $journee] = $this->creerCampagneAvecJournee();

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.disponibilite.update', $campagne), $this->charge([
                'permis' => false, 'id_vehicule_type' => $this->vehicules['utilitaire'],
            ], $journee->id))
            ->assertOk();

        $dispo = BenevoleDisponibilite::where('id_personne', $benevole->id)->firstOrFail();
        $this->assertFalse($dispo->permis);
        // Le véhicule envoyé est ignoré : sans permis → « Sans permis ».
        $this->assertSame($this->vehicules['sans_permis'], (int) $dispo->id_vehicule_type);
    }

    public function test_permis_coche_avec_le_vehicule_sans_permis_est_refuse(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        [$campagne, $journee] = $this->creerCampagneAvecJournee();

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.disponibilite.update', $campagne), $this->charge([
                'permis' => true, 'id_vehicule_type' => $this->vehicules['sans_permis'],
            ], $journee->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors('id_vehicule_type', 'errors');
    }

    public function test_permis_coche_sans_vehicule_est_refuse(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        [$campagne, $journee] = $this->creerCampagneAvecJournee();

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.disponibilite.update', $campagne), $this->charge([
                'permis' => true, 'id_vehicule_type' => null,
            ], $journee->id))
            ->assertStatus(422);

        $this->assertSame(0, BenevoleDisponibilite::count());
    }

    public function test_couverture_decochee_sans_secteur_est_refusee(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        [$campagne, $journee] = $this->creerCampagneAvecJournee();

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.disponibilite.update', $campagne), $this->charge(['secteurs' => []], $journee->id))
            ->assertStatus(422);
    }

    public function test_au_moins_un_creneau_est_requis(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        [$campagne, $journee] = $this->creerCampagneAvecJournee();

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.disponibilite.update', $campagne), $this->charge(['creneaux' => []], $journee->id))
            ->assertStatus(422);
    }

    public function test_les_journees_d_une_campagne_sont_independantes(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        [$campagne, $journee1] = $this->creerCampagneAvecJournee();
        $journee2 = $campagne->ajouterJournee('2026-11-12');

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.disponibilite.update', $campagne), $this->charge([], $journee1->id))
            ->assertOk();
        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.disponibilite.update', $campagne), $this->charge([
                'permis' => false, 'secteurs' => [$this->geo['a2']],
            ], $journee2->id))
            ->assertOk();

        $this->assertSame(2, BenevoleDisponibilite::count());
        $d1 = BenevoleDisponibilite::with('secteurs')->where('id_campagne_journee', $journee1->id)->firstOrFail();
        $d2 = BenevoleDisponibilite::with('secteurs')->where('id_campagne_journee', $journee2->id)->firstOrFail();
        $this->assertSame($this->vehicules['utilitaire'], (int) $d1->id_vehicule_type);
        $this->assertSame($this->vehicules['sans_permis'], (int) $d2->id_vehicule_type);
        $this->assertSame([$this->geo['a2']], $d2->secteurs->pluck('id_secteur')->map(fn($i) => (int) $i)->all());
    }

    public function test_une_journee_d_une_autre_campagne_est_refusee(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        [$campagne] = $this->creerCampagneAvecJournee();
        [, $journeeAutre] = $this->creerCampagneAvecJournee('2026-12-01');

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.disponibilite.update', $campagne), $this->charge([], $journeeAutre->id))
            ->assertNotFound();
    }

    public function test_reconfirmer_remplace_les_secteurs_et_les_creneaux(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        [$campagne, $journee] = $this->creerCampagneAvecJournee();
        $url = route('livraison.benevole.disponibilite.update', $campagne);

        $this->actingAs($benevole)->postJson($url, $this->charge([], $journee->id))->assertOk();
        $this->actingAs($benevole)->postJson($url, $this->charge([
            'secteurs' => [$this->geo['a2']], 'creneaux' => ['10-12'],
        ], $journee->id))->assertOk();

        $dispo = BenevoleDisponibilite::with(['secteurs', 'creneaux'])->where('id_personne', $benevole->id)->firstOrFail();
        $this->assertSame([$this->geo['a2']], $dispo->secteurs->pluck('id_secteur')->map(fn($i) => (int) $i)->all());
        $this->assertSame(['10-12'], $dispo->creneaux->pluck('creneau')->all());
    }

    // ── Régression : « même que mon profil » sans BenevoleProfil ────────

    public function test_meme_que_mon_profil_est_accepte_sans_profil_benevole(): void
    {
        // Compte avec le rôle bénévole mais SANS BenevoleProfil (ex. staff
        // qui ouvre le lien) : cocher véhicule + zone habituels puis valider
        // ne doit pas renvoyer « Aucun profil bénévole : choisissez… ».
        $compte = $this->creerPersonne(['benevole']);
        [$campagne, $journee] = $this->creerCampagneAvecJournee();

        $this->actingAs($compte)
            ->postJson(route('livraison.benevole.disponibilite.update', $campagne), $this->charge([
                'vehicule_confirme' => true, 'permis' => null, 'id_vehicule_type' => null,
                'coverage_confirmee' => true, 'secteurs' => [],
                'creneaux' => ['08-10', '10-12', '12-14', '14-16', '16-18', '18-19'],
            ], $journee->id))
            ->assertOk()
            ->assertJson(['success' => true]);

        $dispo = BenevoleDisponibilite::with('creneaux')->where('id_personne', $compte->id)->firstOrFail();
        $this->assertSame('confirme', $dispo->statut);
        $this->assertTrue($dispo->vehicule_confirme);
        $this->assertTrue($dispo->coverage_confirmee);
        $this->assertCount(6, $dispo->creneaux);
    }

    public function test_la_page_sans_profil_benevole_s_affiche_avec_une_indication(): void
    {
        $compte = $this->creerPersonne(['benevole']);
        [$campagne] = $this->creerCampagneAvecJournee();

        $this->actingAs($compte)
            ->get(route('livraison.benevole.disponibilite.show', $campagne))
            ->assertOk()
            ->assertSee('aucun véhicule enregistré sur votre profil');
    }

    public function test_le_choix_explicite_reste_valide_sans_profil_benevole(): void
    {
        $compte = $this->creerPersonne(['benevole']);
        [$campagne, $journee] = $this->creerCampagneAvecJournee();

        $this->actingAs($compte)
            ->postJson(route('livraison.benevole.disponibilite.update', $campagne), $this->charge([], $journee->id))
            ->assertOk();
    }
}
