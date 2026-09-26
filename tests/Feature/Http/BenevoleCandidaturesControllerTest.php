<?php
// tests/Feature/Http/BenevoleCandidaturesControllerTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Amana\Shared\Models\VehiculeType;
use App\Models\BenevoleProfil;
use App\Models\Personne;
use App\Notifications\BenevoleCandidatureValideeDejaInscritNotification;
use App\Notifications\BenevoleCandidatureValideeNotification;
use App\Services\RoleService;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Phase 5 (opportunistic): covers the exact regression the controller's
 * own docblock documents (27/08/2026 fix) — valider() must set
 * Personne::statut = 'Validé' in addition to the BenevoleProfil's own
 * statut, or AuthController refuses the login the invitation email
 * promises. Also covers role assignment, the two notification branches,
 * and the already-treated guard shared by valider()/rejeter().
 */
class BenevoleCandidaturesControllerTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
    }

    private function creerCandidature(array $overrides = []): BenevoleProfil
    {
        $vehicule = VehiculeType::create(['type' => 'Voiture', 'capacite_kg' => 200, 'nombre_part_max' => 5]);
        // No global 'familles' role on purpose — a fresh public candidacy
        // hasn't been given one yet (that's exactly what valider() does).
        $personne = Personne::create([
            'nom' => 'Nouveau', 'prenom' => 'Benevole', 'email' => 'benevole@example.fr',
            'statut' => 'En attente',
        ]);

        return BenevoleProfil::create(array_merge([
            'id_personne' => $personne->id,
            'id_vehicule_type' => $vehicule->id,
            'statut' => 'Reçu',
        ], $overrides));
    }

    // ── valider() ────────────────────────────────────────────────────────

    public function test_valider_met_a_jour_le_statut_du_profil_et_de_la_personne(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $candidature = $this->creerCandidature();

        $this->actingAs($admin)
            ->post(route('admin.benevoles.valider', $candidature->id), ['role' => 'benevole'])
            ->assertRedirect(route('admin.benevoles.index'));

        $this->assertSame('Validé', $candidature->fresh()->statut);
        // The regression this locks in: Personne::statut must ALSO flip,
        // or the login the email promises would be refused by AuthController.
        $this->assertSame('Validé', Personne::find($candidature->id_personne)->statut);
    }

    public function test_valider_attribue_le_role_choisi_par_le_staff(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $candidature = $this->creerCandidature();

        $this->actingAs($admin)->post(route('admin.benevoles.valider', $candidature->id), ['role' => 'equipe_pesee']);

        $personne = Personne::find($candidature->id_personne);
        $this->assertSame('equipe_pesee', app(RoleService::class)->currentRoleCode($personne));
    }

    public function test_valider_rejette_un_role_qui_nexiste_pas(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $candidature = $this->creerCandidature();

        $this->actingAs($admin)
            ->post(route('admin.benevoles.valider', $candidature->id), ['role' => 'role_invente'])
            ->assertSessionHasErrors('role');

        $this->assertSame('Reçu', $candidature->fresh()->statut);
    }

    public function test_valider_envoie_linvitation_a_une_personne_sans_mot_de_passe(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $candidature = $this->creerCandidature();
        $personne = Personne::find($candidature->id_personne);

        $this->actingAs($admin)->post(route('admin.benevoles.valider', $candidature->id), ['role' => 'benevole']);

        Notification::assertSentTo($personne, BenevoleCandidatureValideeNotification::class);
        Notification::assertNotSentTo($personne, BenevoleCandidatureValideeDejaInscritNotification::class);
    }

    public function test_valider_envoie_la_connexion_directe_a_une_personne_qui_a_deja_un_mot_de_passe(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $candidature = $this->creerCandidature();
        Personne::find($candidature->id_personne)->update(['password' => bcrypt('deja-defini')]);
        $personne = Personne::find($candidature->id_personne);

        $this->actingAs($admin)->post(route('admin.benevoles.valider', $candidature->id), ['role' => 'benevole']);

        Notification::assertSentTo($personne, BenevoleCandidatureValideeDejaInscritNotification::class);
        Notification::assertNotSentTo($personne, BenevoleCandidatureValideeNotification::class);
    }

    public function test_valider_refuse_une_candidature_qui_nest_plus_en_attente(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $candidature = $this->creerCandidature(['statut' => 'Rejeté']);

        $reponse = $this->actingAs($admin)->post(route('admin.benevoles.valider', $candidature->id), ['role' => 'benevole']);

        $reponse->assertRedirect(route('admin.benevoles.index'));
        $this->assertSame('Rejeté', $candidature->fresh()->statut);
        Notification::assertNothingSent();
    }

    // ── rejeter() ────────────────────────────────────────────────────────

    public function test_rejeter_passe_le_statut_a_rejete(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $candidature = $this->creerCandidature();

        $this->actingAs($admin)->post(route('admin.benevoles.rejeter', $candidature->id))->assertRedirect();

        $this->assertSame('Rejeté', $candidature->fresh()->statut);
    }

    public function test_rejeter_ne_touche_pas_au_statut_de_la_personne(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $candidature = $this->creerCandidature();

        $this->actingAs($admin)->post(route('admin.benevoles.rejeter', $candidature->id));

        $this->assertSame('En attente', Personne::find($candidature->id_personne)->statut);
    }

    public function test_rejeter_refuse_une_candidature_deja_traitee(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $candidature = $this->creerCandidature(['statut' => 'Validé']);

        $this->actingAs($admin)->post(route('admin.benevoles.rejeter', $candidature->id))->assertRedirect();

        $this->assertSame('Validé', $candidature->fresh()->statut);
    }

    // ── accès ────────────────────────────────────────────────────────────

    public function test_un_gestionnaire_ne_peut_pas_valider_de_candidature(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $candidature = $this->creerCandidature();

        $this->actingAs($gestionnaire)
            ->post(route('admin.benevoles.valider', $candidature->id), ['role' => 'benevole'])
            ->assertRedirect(); // role:admin — redirects, doesn't 403

        $this->assertSame('Reçu', $candidature->fresh()->statut);
    }
}
