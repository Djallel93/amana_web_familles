<?php
// tests/Feature/Http/PersonneDesactivationTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\BenevoleProfil;
use App\Models\Campagne;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\PersonneDesactivee;
use App\Models\RouteLivraison;
use App\Notifications\CampagneDisponibiliteNotification;
use App\Services\BenevoleDisponibiliteService;
use App\Services\RoleService;
use App\Support\Creneau;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsCampagneEquipeFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * 03/10/2026 — /admin/personnes : « Supprimer » devient « Désactiver ».
 * Désactivée = plus de connexion à Familles, plus proposable pour une
 * campagne, mais rôles et historique conservés ; réactivation à l'identique.
 * Bloquée tant que la personne est engagée sur une campagne non terminée
 * (la liste de TOUTES les campagnes concernées est affichée).
 */
class PersonneDesactivationTest extends TestCase
{
    use BuildsCampagneEquipeFixtures;
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
    }

    private function creerRoute(Campagne $campagne, int $idBenevole, string $statut): RouteLivraison
    {
        return RouteLivraison::create([
            'id_campagne' => $campagne->id,
            'id_benevole' => $idBenevole,
            'id_vehicule_type' => 1,
            'creneau' => Creneau::MATIN_1,
            'statut' => $statut,
        ]);
    }

    private function creerLivraisonImposee(Campagne $campagne, int $idBenevole, string $statut = 'assignee'): Livraison
    {
        return Livraison::create([
            'id_famille' => Famille::factory()->create()->id,
            'id_campagne' => $campagne->id,
            'statut' => $statut,
            'statut_conditionnement' => 'en_attente',
            'nombre_personnes' => 1,
            'poids_kg' => 10.0,
            'statut_contact' => 'a_contacter',
            'id_benevole_impose' => $idBenevole,
        ]);
    }

    // ── Désactivation / réactivation ─────────────────────────────────────

    public function test_desactiver_puis_reactiver_restaure_la_personne_a_lidentique(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $personne = $this->creerPersonne(['gestionnaire', 'equipe_packaging']);

        $this->actingAs($admin)->post(route('admin.personnes.desactiver', $personne->id))
            ->assertRedirect(route('admin.personnes.index'))
            ->assertSessionHas('success');
        $this->assertTrue(PersonneDesactivee::estDesactivee($personne->id));

        $this->actingAs($admin)->post(route('admin.personnes.reactiver', $personne->id))->assertRedirect();

        $this->assertFalse(PersonneDesactivee::estDesactivee($personne->id));
        $this->assertSame('gestionnaire', app(RoleService::class)->currentRoleCode($personne));
        $this->assertSame(2, $personne->roles()->whereHas('application', fn ($q) => $q->where('code', 'familles'))->count());
    }

    public function test_un_membre_ou_gestionnaire_ne_peut_pas_desactiver(): void
    {
        $cible = $this->creerPersonne(['membre']);

        $this->actingAs($this->creerPersonne(['gestionnaire']))->post(route('admin.personnes.desactiver', $cible->id));

        $this->assertFalse(PersonneDesactivee::estDesactivee($cible->id));
    }

    public function test_on_ne_peut_pas_se_desactiver_soi_meme(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $autreAdmin = $this->creerPersonne(['admin']);

        $this->actingAs($admin)->post(route('admin.personnes.desactiver', $admin->id))->assertSessionHas('error');

        $this->assertFalse(PersonneDesactivee::estDesactivee($admin->id));
        $this->assertFalse(PersonneDesactivee::estDesactivee($autreAdmin->id));
    }

    // ── Verrou : campagnes non terminées ─────────────────────────────────

    public function test_desactivation_bloquee_liste_toutes_les_campagnes_concernees(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $personne = $this->creerPersonne(['benevole', 'equipe_pesee']);

        $c1 = $this->creerCampagne(['statut' => 'en_cours']);
        $c2 = $this->creerCampagne(['statut' => 'preparation']);
        $this->assignerEquipe($c1, $personne->id, 'equipe_pesee');
        $this->creerRoute($c2, $personne->id, 'en_cours');
        $this->creerLivraisonImposee($c2, $personne->id);

        $reponse = $this->actingAs($admin)->post(route('admin.personnes.desactiver', $personne->id));

        $reponse->assertRedirect(route('admin.personnes.index'));
        $this->assertFalse(PersonneDesactivee::estDesactivee($personne->id), 'Rien ne doit être désactivé tant que le verrou joue');

        $bloquee = session('desactivation_bloquee');
        $this->assertNotNull($bloquee);
        $this->assertSame([$c1->id, $c2->id], array_column($bloquee['campagnes'], 'id'));
        $this->assertSame(['Équipe Pesée'], $bloquee['campagnes'][0]['raisons']);
        $this->assertContains("Tournée #{$c2->routes()->first()->id} (en_cours)", $bloquee['campagnes'][1]['raisons']);
        $this->assertContains('1 livraison imposée', $bloquee['campagnes'][1]['raisons']);

        // Le bandeau affiche ces deux campagnes avec un lien.
        $this->get(route('admin.personnes.index'))
            ->assertSee(route('livraison.campagnes.show', $c1->id), false)
            ->assertSee(route('livraison.campagnes.show', $c2->id), false);
    }

    public function test_une_campagne_terminee_ou_des_tournees_finies_ne_bloquent_pas(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $personne = $this->creerPersonne(['benevole']);

        $terminee = $this->creerCampagne(['statut' => 'terminee']);
        $this->assignerEquipe($terminee, $personne->id, 'equipe_chargement');

        $enCours = $this->creerCampagne(['statut' => 'en_cours']);
        $this->creerRoute($enCours, $personne->id, 'terminee');
        $this->creerRoute($enCours, $personne->id, 'annulee');
        $this->creerLivraisonImposee($enCours, $personne->id, 'livree');

        $this->actingAs($admin)->post(route('admin.personnes.desactiver', $personne->id))->assertSessionHas('success');

        $this->assertTrue(PersonneDesactivee::estDesactivee($personne->id));
    }

    // ── Accès coupé ──────────────────────────────────────────────────────

    public function test_une_personne_desactivee_est_deconnectee_a_sa_prochaine_requete(): void
    {
        $personne = $this->creerPersonne(['gestionnaire']);
        $this->actingAs($personne)->get(route('familles.index'))->assertOk();

        PersonneDesactivee::create(['id_personne' => $personne->id]);

        $this->get(route('familles.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', \App\Http\Middleware\EnsurePersonneActive::MESSAGE);
        $this->assertGuest();
    }

    public function test_une_requete_json_dune_personne_desactivee_recoit_403(): void
    {
        $personne = $this->creerPersonne(['gestionnaire']);
        PersonneDesactivee::create(['id_personne' => $personne->id]);

        $this->actingAs($personne)->getJson(route('livraison.personnes.recherche'))
            ->assertForbidden()
            ->assertJsonPath('message', \App\Http\Middleware\EnsurePersonneActive::MESSAGE);
    }

    // ── Liste ────────────────────────────────────────────────────────────

    public function test_la_liste_masque_les_desactivees_par_defaut_et_les_compte_a_part(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $active = $this->creerPersonne(['membre'], ['nom' => 'Zidane', 'prenom' => 'Active']);
        $inactive = $this->creerPersonne(['membre'], ['nom' => 'Yahia', 'prenom' => 'Inactive']);
        PersonneDesactivee::create(['id_personne' => $inactive->id]);

        $this->actingAs($admin)->get(route('admin.personnes.index'))
            ->assertOk()
            ->assertSee('Zidane')
            ->assertDontSee('Yahia')
            ->assertSee('Désactivées')
            ->assertViewHas('total', 2)            // admin + active
            ->assertViewHas('nbDesactives', 1);

        $this->get(route('admin.personnes.index', ['desactives' => 1]))
            ->assertSee('Yahia')
            ->assertSee(route('admin.personnes.reactiver', $inactive->id), false);
    }

    // ── Exclusion des futures campagnes ──────────────────────────────────

    public function test_le_picker_ne_propose_pas_les_desactivees(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $active = $this->creerPersonne(['benevole'], ['nom' => 'Alpha']);
        $inactive = $this->creerPersonne(['benevole'], ['nom' => 'Beta']);
        PersonneDesactivee::create(['id_personne' => $inactive->id]);

        $ids = collect($this->actingAs($gestionnaire)
            ->getJson(route('livraison.personnes.recherche', ['tous' => 1]))
            ->assertOk()->json())->pluck('id')->all();

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_on_ne_peut_pas_ajouter_une_desactivee_a_une_equipe(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $inactive = $this->creerPersonne(['benevole']);
        PersonneDesactivee::create(['id_personne' => $inactive->id]);
        $campagne = $this->creerCampagne();

        $this->actingAs($gestionnaire)
            ->postJson(route('livraison.campagnes.equipes.ajouter', $campagne), ['id_personne' => $inactive->id, 'role' => 'equipe_pesee'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, $campagne->equipeMembres()->count());
    }

    public function test_personnes_avec_role_exclut_les_desactivees(): void
    {
        $campagne = $this->creerCampagne();
        $active = $this->creerPersonne(['benevole']);
        $inactive = $this->creerPersonne(['benevole']);
        $this->assignerEquipe($campagne, $active->id, 'equipe_chargement');
        $this->assignerEquipe($campagne, $inactive->id, 'equipe_chargement');
        PersonneDesactivee::create(['id_personne' => $inactive->id]);

        $this->assertSame([$active->id], $campagne->personnesAvecRole('equipe_chargement')->pluck('id')->all());
    }

    public function test_la_notification_de_disponibilite_saute_les_desactivees(): void
    {
        $active = $this->creerPersonne(['benevole']);
        $inactive = $this->creerPersonne(['benevole']);
        foreach ([$active, $inactive] as $p) {
            BenevoleProfil::create(['id_personne' => $p->id, 'id_vehicule_type' => 1, 'statut' => 'Validé']);
        }
        PersonneDesactivee::create(['id_personne' => $inactive->id]);

        $resultat = app(BenevoleDisponibiliteService::class)->notifierCampagne($this->creerCampagne());

        $this->assertSame(1, $resultat['envoyes']);
        Notification::assertSentTo($active, CampagneDisponibiliteNotification::class);
        Notification::assertNotSentTo($inactive, CampagneDisponibiliteNotification::class);
    }

    public function test_on_ne_peut_pas_reassigner_une_tournee_a_une_desactivee(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $chauffeur = $this->creerPersonne(['benevole']);
        $inactive = $this->creerPersonne(['benevole']);
        PersonneDesactivee::create(['id_personne' => $inactive->id]);
        $route = $this->creerRoute($this->creerCampagne(), $chauffeur->id, 'planifiee');

        $this->actingAs($gestionnaire)
            ->postJson(route('livraison.routes.reassigner', $route), ['id_benevole' => $inactive->id, 'id_vehicule_type' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id_benevole']);

        $this->assertSame($chauffeur->id, $route->fresh()->id_benevole);
    }
}
