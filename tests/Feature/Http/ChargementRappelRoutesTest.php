<?php
// tests/Feature/Http/ChargementRappelRoutesTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Campagne;
use App\Models\Famille;
use App\Models\Livraison;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * 03/10/2026 — page Chargement : le rappel « générez d'abord les routes »
 * (emoji, texte aéré, lien Suivi livraison devenu un bouton qui mène au bloc
 * « Génération des routes »). Voir ChargementController::etatSansTournee().
 */
class ChargementRappelRoutesTest extends TestCase
{
    use SeedsCommunFixtures;

    private const TITRE = 'Conditionnement terminé, tournées pas encore générées';

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerRolesFamilles();
    }

    private function campagne(): Campagne
    {
        return Campagne::create([
            'type' => 'zakat_el_fitr',
            'statut' => 'preparation',
            'date_livraison' => '2026-11-10',
            'poids_moyen_kg' => 5,
        ]);
    }

    private function livraisonConfirmee(Campagne $campagne, string $conditionnement): Livraison
    {
        return Livraison::create([
            'id_famille' => Famille::factory()->create()->id,
            'id_campagne' => $campagne->id,
            'statut' => 'non_assignee',
            'statut_conditionnement' => $conditionnement,
            'nombre_personnes' => 3,
            'poids_kg' => 10.0,
            'statut_contact' => 'confirme',
            'se_deplace' => false,
        ]);
    }

    public function test_le_rappel_a_un_emoji_et_un_bouton_vers_le_bloc_de_generation(): void
    {
        $campagne = $this->campagne();
        $this->livraisonConfirmee($campagne, 'prete');

        $cible = route('livraison.suivi-livraison.index', $campagne) . '#generer-routes';

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->get(route('livraison.chargement.index', $campagne))
            ->assertOk()
            ->assertSee(self::TITRE)
            ->assertSee('🚚')
            // Un vrai bouton (classes de bouton + min-h tactile), pas un lien souligné dans une phrase.
            ->assertSee('<a href="' . $cible . '"', false)
            ->assertSee('Générer les routes dans Suivi livraison')
            ->assertDontSee('class="underline font-medium"', false);
    }

    public function test_une_equipe_sans_droit_de_generation_voit_le_message_mais_pas_le_bouton(): void
    {
        $campagne = $this->campagne();
        $this->livraisonConfirmee($campagne, 'prete');

        $chargeur = $this->creerPersonne(['membre']);
        $campagne->equipeMembres()->create(['id_personne' => $chargeur->id, 'role' => 'equipe_chargement']);

        $this->actingAs($chargeur)
            ->get(route('livraison.chargement.index', $campagne))
            ->assertOk()
            ->assertSee(self::TITRE)
            ->assertSee('🚚')
            ->assertSee('Prévenez un admin/gestionnaire')
            ->assertDontSee('#generer-routes', false)
            ->assertDontSee('Générer les routes dans Suivi livraison');
    }

    public function test_pas_de_rappel_tant_que_le_conditionnement_nest_pas_termine(): void
    {
        $campagne = $this->campagne();
        $this->livraisonConfirmee($campagne, 'prete');
        $this->livraisonConfirmee($campagne, 'en_attente');

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->get(route('livraison.chargement.index', $campagne))
            ->assertOk()
            ->assertDontSee(self::TITRE)
            ->assertSee('Conditionnement en cours');
    }

    public function test_pas_de_rappel_sans_aucune_famille_confirmee(): void
    {
        $campagne = $this->campagne();

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->get(route('livraison.chargement.index', $campagne))
            ->assertOk()
            ->assertDontSee(self::TITRE);
    }
}
