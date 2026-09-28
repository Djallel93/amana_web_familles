<?php
// tests/Feature/Http/OrganisationsControllerTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Organisation;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

class OrganisationsControllerTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerRolesFamilles();
    }

    public function test_store_cree_une_organisation_active(): void
    {
        $admin = $this->creerPersonne(['admin']);

        $this->actingAs($admin)
            ->post(route('admin.organisations.store'), ['code' => 'PARTENAIRE_A', 'nom' => 'Partenaire A'])
            ->assertRedirect(route('settings.index'));

        $this->assertDatabaseHas('organisations', ['code' => 'PARTENAIRE_A', 'actif' => true]);
    }

    public function test_store_refuse_un_code_deja_utilise(): void
    {
        $admin = $this->creerPersonne(['admin']);
        Organisation::create(['code' => 'EXISTANT', 'nom' => 'Existant', 'actif' => true]);

        $this->actingAs($admin)
            ->post(route('admin.organisations.store'), ['code' => 'EXISTANT', 'nom' => 'Autre Nom'])
            ->assertSessionHasErrors('code');
    }

    public function test_store_refuse_un_nom_deja_utilise(): void
    {
        $admin = $this->creerPersonne(['admin']);
        Organisation::create(['code' => 'EXISTANT', 'nom' => 'Nom Existant', 'actif' => true]);

        $this->actingAs($admin)
            ->post(route('admin.organisations.store'), ['code' => 'AUTRE_CODE', 'nom' => 'Nom Existant'])
            ->assertSessionHasErrors('nom');
    }

    /**
     * The AMANA "principale" org — auto-seeded by migration, see
     * database/migrations/2026_08_28_000001_create_organisations_domain_tables.php
     * — must never be deactivated: FamilleUpsertService::rattacherOrganisationInitiale()'s
     * default-attachment fallback depends on Organisation::principale() existing.
     */
    public function test_update_ne_peut_pas_desactiver_lorganisation_principale(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $principale = Organisation::principale();

        $this->actingAs($admin)
            ->put(route('admin.organisations.update', $principale), ['nom' => $principale->nom, 'actif' => false])
            ->assertRedirect();

        $this->assertTrue($principale->fresh()->actif);
    }

    public function test_destroy_refuse_de_desactiver_lorganisation_principale(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $principale = Organisation::principale();

        $reponse = $this->actingAs($admin)->delete(route('admin.organisations.destroy', $principale));

        $reponse->assertSessionHasErrors('organisation');
        $this->assertTrue($principale->fresh()->actif);
    }

    public function test_destroy_desactive_sans_supprimer_la_ligne(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $organisation = Organisation::create(['code' => 'PARTENAIRE_A', 'nom' => 'Partenaire A', 'actif' => true]);

        $this->actingAs($admin)->delete(route('admin.organisations.destroy', $organisation))->assertRedirect();

        $this->assertNotNull(Organisation::find($organisation->id));
        $this->assertFalse($organisation->fresh()->actif);
    }

    public function test_update_peut_reactiver_une_organisation_non_principale(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $organisation = Organisation::create(['code' => 'PARTENAIRE_A', 'nom' => 'Partenaire A', 'actif' => false]);

        $this->actingAs($admin)
            ->put(route('admin.organisations.update', $organisation), ['nom' => 'Partenaire A', 'actif' => true])
            ->assertRedirect();

        $this->assertTrue($organisation->fresh()->actif);
    }

    public function test_un_gestionnaire_ne_peut_pas_gerer_les_organisations(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);

        $this->actingAs($gestionnaire)
            ->post(route('admin.organisations.store'), ['code' => 'X', 'nom' => 'X'])
            ->assertRedirect(); // role:admin — redirects, doesn't 403

        $this->assertNull(Organisation::where('code', 'X')->first());
    }
}
