<?php
// tests/Feature/Http/HotelAddressesControllerTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\HotelAddress;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Phase 5 (opportunistic): small, self-contained CRUD, but with a real
 * business rule worth a regression net — duplicate blocking on the
 * NORMALIZED address (HotelAddress::normaliser()), not the raw string,
 * both as a friendly validation error and (implicitly, via the DB unique
 * constraint) as a safety net for concurrent writes.
 */
class HotelAddressesControllerTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerRolesFamilles();
    }

    public function test_store_cree_une_adresse_hotel(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);

        $this->actingAs($gestionnaire)
            ->post(route('hotel-addresses.store'), ['adresse' => "Hôtel Kyriad, 12 Rue de l'Océan, 44300 Nantes"])
            ->assertRedirect(route('settings.index'));

        $this->assertDatabaseHas('hotel_addresses', ['adresse' => "Hôtel Kyriad, 12 Rue de l'Océan, 44300 Nantes"]);
    }

    public function test_store_refuse_un_doublon_une_fois_normalise_meme_avec_une_casse_ou_ponctuation_differente(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        HotelAddress::create(['adresse' => "Hôtel Kyriad, 12 Rue de l'Océan, 44300 Nantes"]);

        $reponse = $this->actingAs($gestionnaire)->post(route('hotel-addresses.store'), [
            // Different casing/punctuation, same normalized form.
            'adresse' => "hotel kyriad   12 rue de l'ocean 44300 nantes",
        ]);

        $reponse->assertSessionHasErrors('adresse');
        $this->assertSame(1, HotelAddress::count());
    }

    public function test_store_accepte_deux_adresses_reellement_differentes_pour_le_meme_hotel(): void
    {
        // Docblock: two genuinely different address forms for the same
        // hotel are BOTH accepted — not deduped against each other.
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        HotelAddress::create(['adresse' => "Hôtel Kyriad, 12 Rue de l'Océan, 44300 Nantes"]);

        $this->actingAs($gestionnaire)
            ->post(route('hotel-addresses.store'), ['adresse' => "12 Rue de l'Océan, 44300 Nantes"])
            ->assertRedirect(route('settings.index'));

        $this->assertSame(2, HotelAddress::count());
    }

    public function test_update_refuse_de_renommer_vers_une_adresse_normalisee_deja_utilisee_par_une_autre_ligne(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        HotelAddress::create(['adresse' => 'Adresse A']);
        $b = HotelAddress::create(['adresse' => 'Adresse B']);

        $reponse = $this->actingAs($gestionnaire)->put(route('hotel-addresses.update', $b), ['adresse' => 'adresse a']);

        $reponse->assertSessionHasErrors('adresse');
        $this->assertSame('Adresse B', $b->fresh()->adresse);
    }

    public function test_update_autorise_a_resaisir_sa_propre_adresse_inchangee(): void
    {
        // dejaExistante()'s $excepte must exclude the row being updated
        // itself, or saving a row unchanged would wrongly be refused as a
        // "duplicate" of itself.
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $adresse = HotelAddress::create(['adresse' => 'Adresse A']);

        $this->actingAs($gestionnaire)
            ->put(route('hotel-addresses.update', $adresse), ['adresse' => 'Adresse A'])
            ->assertRedirect(route('settings.index'));

        $this->assertSame(1, HotelAddress::count());
    }

    public function test_destroy_supprime_reellement_la_ligne(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $adresse = HotelAddress::create(['adresse' => 'Adresse A']);

        $this->actingAs($gestionnaire)->delete(route('hotel-addresses.destroy', $adresse))->assertRedirect();

        $this->assertDatabaseMissing('hotel_addresses', ['id' => $adresse->id]);
    }

    public function test_un_simple_membre_ne_peut_pas_gerer_les_adresses_hotel(): void
    {
        $membre = $this->creerPersonne(['membre']);

        $this->actingAs($membre)
            ->post(route('hotel-addresses.store'), ['adresse' => 'Adresse A'])
            ->assertRedirect(); // role:gestionnaire — redirects, doesn't 403

        $this->assertSame(0, HotelAddress::count());
    }
}
