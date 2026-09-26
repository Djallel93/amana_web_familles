<?php
// tests/Feature/Http/PersonnesControllerTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Organisation;
use App\Models\Personne;
use App\Notifications\InvitationFamillesDejaInscritNotification;
use App\Notifications\InvitationFamillesNotification;
use App\Services\RoleService;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Phase 5 (opportunistic): PersonnesController is admin-only staff
 * management — covers the shared-`ref_personnes`-account dedup on store()
 * (the same email may already exist from another AMANA app), the
 * invitation-vs-direct-login email branch, role syncing via RoleService,
 * the gestionnaire_externe organisation requirement, and access
 * revocation on destroy() (never deletes the shared Personne row).
 */
class PersonnesControllerTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
    }

    private function organisation(string $code): Organisation
    {
        return Organisation::create(['code' => $code, 'nom' => $code, 'actif' => true]);
    }

    private function champsPersonne(array $overrides = []): array
    {
        return array_merge([
            'nom' => 'Benali',
            'prenom' => 'Karim',
            'email' => 'karim.benali@example.fr',
            'telephone' => '0600000000',
            'role' => 'membre',
        ], $overrides);
    }

    // ── store() — création vs compte partagé existant ───────────────────

    public function test_store_cree_une_nouvelle_personne_et_envoie_une_invitation(): void
    {
        $admin = $this->creerPersonne(['admin']);

        $this->actingAs($admin)->post(route('admin.personnes.store'), $this->champsPersonne())->assertRedirect(route('admin.personnes.index'));

        $personne = Personne::where('email', 'karim.benali@example.fr')->first();
        $this->assertNotNull($personne);
        $this->assertNull($personne->password);
        $this->assertSame('membre', app(RoleService::class)->currentRoleCode($personne));
        Notification::assertSentTo($personne, InvitationFamillesNotification::class);
        Notification::assertNotSentTo($personne, InvitationFamillesDejaInscritNotification::class);
    }

    public function test_store_reutilise_un_compte_ref_personnes_existant_au_lieu_de_dupliquer(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $existante = Personne::create([
            'nom' => 'AncienNom', 'prenom' => 'Karim', 'email' => 'karim.benali@example.fr',
            'password' => bcrypt('deja-un-mdp'), 'statut' => 'Validé',
        ]);

        $this->actingAs($admin)->post(route('admin.personnes.store'), $this->champsPersonne(['nom' => 'NouveauNom']))->assertRedirect();

        $this->assertSame(1, Personne::where('email', 'karim.benali@example.fr')->count());
        $this->assertSame('NouveauNom', $existante->fresh()->nom);
    }

    /**
     * Docblock: a personne who already has a password (shared account
     * from another AMANA app) gets the "direct login" email, not an
     * invitation with a password-creation link.
     */
    public function test_store_envoie_lemail_de_connexion_directe_a_un_compte_qui_a_deja_un_mot_de_passe(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $existante = Personne::create([
            'nom' => 'X', 'prenom' => 'Y', 'email' => 'karim.benali@example.fr',
            'password' => bcrypt('deja-un-mdp'), 'statut' => 'Validé',
        ]);

        $this->actingAs($admin)->post(route('admin.personnes.store'), $this->champsPersonne())->assertRedirect();

        Notification::assertSentTo($existante, InvitationFamillesDejaInscritNotification::class);
        Notification::assertNotSentTo($existante, InvitationFamillesNotification::class);
    }

    public function test_store_refuse_gestionnaire_externe_sans_organisation_selectionnee(): void
    {
        $admin = $this->creerPersonne(['admin']);

        $reponse = $this->actingAs($admin)->post(route('admin.personnes.store'), $this->champsPersonne([
            'role' => 'gestionnaire_externe',
            'organisations' => [],
        ]));

        $reponse->assertSessionHasErrors('organisations');
        $this->assertNull(Personne::where('email', 'karim.benali@example.fr')->first());
    }

    public function test_store_rattache_les_organisations_choisies_pour_un_gestionnaire_externe(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $organisation = $this->organisation('PARTENAIRE');

        $this->actingAs($admin)->post(route('admin.personnes.store'), $this->champsPersonne([
            'role' => 'gestionnaire_externe',
            'organisations' => [$organisation->id],
        ]))->assertRedirect();

        $personne = Personne::where('email', 'karim.benali@example.fr')->first();
        $this->assertSame([$organisation->id], Organisation::idsPourPersonne($personne->id));
    }

    /**
     * Defense-in-depth per the controller's own docblock: organisations
     * submitted for a non-gestionnaire_externe role must be ignored, not
     * silently attached.
     */
    public function test_store_ne_rattache_aucune_organisation_pour_un_role_non_externe_meme_si_soumises(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $organisation = $this->organisation('PARTENAIRE');

        $this->actingAs($admin)->post(route('admin.personnes.store'), $this->champsPersonne([
            'role' => 'membre',
            'organisations' => [$organisation->id],
        ]))->assertRedirect();

        $personne = Personne::where('email', 'karim.benali@example.fr')->first();
        $this->assertSame([], Organisation::idsPourPersonne($personne->id));
    }

    // ── update() ─────────────────────────────────────────────────────────

    public function test_update_change_le_role_et_synchronise_les_organisations(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $personne = $this->creerPersonne(['membre']);
        $organisation = $this->organisation('PARTENAIRE');

        $this->actingAs($admin)->put(route('admin.personnes.update', $personne->id), [
            'nom' => $personne->nom, 'prenom' => $personne->prenom, 'telephone' => null,
            'role' => 'gestionnaire_externe', 'organisations' => [$organisation->id],
        ])->assertRedirect();

        $roleService = app(RoleService::class);
        $this->assertSame('gestionnaire_externe', $roleService->currentRoleCode($personne));
        $this->assertSame([$organisation->id], Organisation::idsPourPersonne($personne->id));
    }

    public function test_update_retire_les_organisations_en_repassant_a_un_role_interne(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $organisation = $this->organisation('PARTENAIRE');
        $personne = $this->creerPersonne(['gestionnaire_externe']);
        Organisation::syncPersonne($personne->id, [$organisation->id]);

        $this->actingAs($admin)->put(route('admin.personnes.update', $personne->id), [
            'nom' => $personne->nom, 'prenom' => $personne->prenom, 'telephone' => null,
            'role' => 'membre', 'organisations' => [],
        ])->assertRedirect();

        $this->assertSame([], Organisation::idsPourPersonne($personne->id));
    }

    // ── destroy() ────────────────────────────────────────────────────────

    public function test_destroy_revoque_le_role_familles_sans_supprimer_le_compte_partage(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $personne = $this->creerPersonne(['membre']);

        $this->actingAs($admin)->delete(route('admin.personnes.destroy', $personne->id))->assertRedirect();

        $this->assertNotNull(Personne::find($personne->id), 'The shared ref_personnes account must survive — only the role is revoked');
        $roleService = app(RoleService::class);
        $this->assertNull($roleService->currentRoleCode($personne));
    }

    // ── accès ────────────────────────────────────────────────────────────

    public function test_un_gestionnaire_ne_peut_pas_acceder_a_la_gestion_des_personnes(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);

        $this->actingAs($gestionnaire)->get(route('admin.personnes.index'))->assertRedirect();
        $this->actingAs($gestionnaire)->post(route('admin.personnes.store'), $this->champsPersonne())->assertRedirect();
        $this->assertNull(Personne::where('email', 'karim.benali@example.fr')->first());
    }
}
