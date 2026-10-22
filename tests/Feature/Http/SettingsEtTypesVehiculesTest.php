<?php
// tests/Feature/Http/SettingsEtTypesVehiculesTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Amana\Shared\Models\VehiculeType;
use App\Models\Organisation;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Octobre 2026 — page Paramètres :
 *   - types de véhicule : ajout, retrait (= désactivation), réactivation, types protégés ;
 *   - les types retirés disparaissent des choix publics ;
 *   - enregistrer l'organisation principale (select « actif » désactivé, donc non soumis) réussit ;
 *   - une organisation créée apparaît dans la liste de la page.
 */
class SettingsEtTypesVehiculesTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerRolesFamilles();
    }

    private function admin()
    {
        // admin + gestionnaire : la page Paramètres et les types de véhicule sont servis au groupe
        // gestionnaire, les organisations au groupe admin.
        return $this->creerPersonne(['admin', 'gestionnaire']);
    }

    private function vehicule(string $type = 'Camionnette', ?int $id = null): VehiculeType
    {
        $attributs = ['type' => $type, 'capacite_kg' => 300, 'nombre_part_max' => 6, 'actif' => true];

        return $id === null ? VehiculeType::create($attributs) : VehiculeType::forceCreate(['id' => $id] + $attributs);
    }

    private function propsSettings($personne): array
    {
        return $this->actingAs($personne)->get(route('settings.index'))->assertOk()->viewData('page')['props'];
    }

    // ── Types de véhicule ────────────────────────────────────────────────

    public function test_store_ajoute_un_type_actif(): void
    {
        $this->actingAs($this->admin())
            ->post(route('vehicules.store'), ['type' => 'Fourgon', 'capacite_kg' => 800, 'nombre_part_max' => 12])
            ->assertRedirect(route('settings.index'));

        $this->assertDatabaseHas('ref_vehicules', ['type' => 'Fourgon', 'actif' => true], config('amana-shared.connection', 'commun'));
    }

    public function test_store_refuse_un_libelle_deja_utilise_sans_tenir_compte_de_la_casse(): void
    {
        $this->vehicule('Fourgon');

        $this->actingAs($this->admin())
            ->post(route('vehicules.store'), ['type' => 'fourgon', 'capacite_kg' => 800, 'nombre_part_max' => 12])
            ->assertSessionHasErrors('type');

        $this->assertSame(1, VehiculeType::whereRaw('LOWER(type) = ?', ['fourgon'])->count());
    }

    public function test_store_valide_les_champs(): void
    {
        $this->actingAs($this->admin())
            ->post(route('vehicules.store'), ['type' => '', 'capacite_kg' => -1, 'nombre_part_max' => 'x'])
            ->assertSessionHasErrors(['type', 'capacite_kg', 'nombre_part_max']);
    }

    public function test_destroy_desactive_le_type_sans_le_supprimer(): void
    {
        $vehicule = $this->vehicule();

        $this->actingAs($this->admin())
            ->delete(route('vehicules.destroy', $vehicule))
            ->assertRedirect(route('settings.index'));

        $this->assertFalse($vehicule->fresh()->actif);
    }

    public function test_les_types_sans_vehicule_sont_proteges(): void
    {
        $nonVehicule = $this->vehicule('Non véhiculé', 7);
        $sansPermis = $this->vehicule('Sans permis', 8);

        foreach ([$nonVehicule, $sansPermis] as $protege) {
            $this->actingAs($this->admin())
                ->delete(route('vehicules.destroy', $protege))
                ->assertSessionHasErrors('vehicule');

            $this->assertTrue($protege->fresh()->actif);
        }
    }

    public function test_reactiver_remet_le_type_dans_les_choix(): void
    {
        $vehicule = $this->vehicule();
        $vehicule->update(['actif' => false]);

        $this->actingAs($this->admin())
            ->post(route('vehicules.reactiver', $vehicule))
            ->assertRedirect(route('settings.index'));

        $this->assertTrue($vehicule->fresh()->actif);
    }

    public function test_la_page_expose_tous_les_types_et_marque_les_proteges(): void
    {
        $this->vehicule('Camionnette');
        $retire = $this->vehicule('Ancien');
        $retire->update(['actif' => false]);
        $this->vehicule('Non véhiculé', 7);

        $vehicules = collect($this->propsSettings($this->admin())['vehicules']);

        $this->assertNotNull($vehicules->firstWhere('type', 'Ancien'), 'un type retiré reste listé (réactivable)');
        $this->assertFalse($vehicules->firstWhere('type', 'Camionnette')['protege']);
        $this->assertTrue($vehicules->firstWhere('type', 'Non véhiculé')['protege']);
    }

    public function test_les_choix_publics_n_incluent_que_les_types_actifs(): void
    {
        $actif = $this->vehicule('Camionnette');
        $retire = $this->vehicule('Ancien');
        $retire->update(['actif' => false]);

        $ids = collect($this->getJson('/vehicules')->assertOk()->json())->pluck('id')->all();

        $this->assertContains($actif->id, $ids);
        $this->assertNotContains($retire->id, $ids);
    }

    // ── Organisations ────────────────────────────────────────────────────

    public function test_enregistrer_l_organisation_principale_sans_le_champ_actif_reussit(): void
    {
        $principale = Organisation::principale();

        $this->actingAs($this->admin())
            ->put(route('admin.organisations.update', $principale), ['nom' => 'AMANA Nantes'])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('AMANA Nantes', $principale->fresh()->nom);
        $this->assertTrue($principale->fresh()->actif);
    }

    public function test_une_organisation_creee_apparait_dans_la_liste_de_la_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.organisations.store'), ['code' => 'PARTENAIRE_B', 'nom' => 'Partenaire B'])
            ->assertRedirect(route('settings.index'));

        $noms = collect($this->propsSettings($admin)['organisations'])->pluck('nom')->all();

        $this->assertContains('Partenaire B', $noms);
    }
}
