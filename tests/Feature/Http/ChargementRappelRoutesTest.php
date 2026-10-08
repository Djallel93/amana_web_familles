<?php
// tests/Feature/Http/ChargementRappelRoutesTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Campagne;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteLivraison;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Page Chargement : le rappel « générez les routes ». Refait le 06/10/2026
 * (voir ChargementController::rappelRoutes()) : affiché dès qu'il y a des
 * familles confirmées à livrer et aucune tournée, SANS attendre la fin du
 * packaging ; son bouton ouvre l'assistant de génération du hub (?generer=1),
 * ou propose de démarrer la campagne si elle ne l'est pas encore.
 */
class ChargementRappelRoutesTest extends TestCase
{
    use SeedsCommunFixtures;

    private const TITRE_DEMARREE = 'Tournées pas encore générées';

    private const TITRE_NON_DEMARREE = 'Campagne pas encore démarrée';

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerRolesFamilles();
    }

    private function campagne(string $statut = 'en_cours'): Campagne
    {
        return Campagne::create([
            'type' => 'zakat_el_fitr',
            'statut' => $statut,
            'date_livraison' => '2026-11-10',
            'poids_moyen_kg' => 5,
        ]);
    }

    private function livraisonConfirmee(Campagne $campagne, string $conditionnement, array $surcharge = []): Livraison
    {
        return Livraison::create(array_merge([
            'id_famille' => Famille::factory()->create()->id,
            'id_campagne' => $campagne->id,
            'statut' => 'non_assignee',
            'statut_conditionnement' => $conditionnement,
            'nombre_personnes' => 3,
            'poids_kg' => 10.0,
            'statut_contact' => 'confirme',
            'se_deplace' => false,
        ], $surcharge));
    }

    public function test_le_rappel_a_un_emoji_et_un_bouton_vers_lassistant_de_generation(): void
    {
        $campagne = $this->campagne();
        $this->livraisonConfirmee($campagne, 'prete');

        $cible = route('livraison.campagnes.show', $campagne) . '?generer=1';

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->get(route('livraison.chargement.index', $campagne))
            ->assertOk()
            ->assertSee(self::TITRE_DEMARREE)
            ->assertSee('🚚')
            // Un vrai bouton (classes de bouton + min-h tactile), pas un lien souligné dans une phrase.
            ->assertSee('<a href="' . $cible . '"', false)
            ->assertSee('Générer les routes')
            ->assertDontSee('class="underline font-medium"', false);
    }

    public function test_le_rappel_saffiche_meme_si_le_conditionnement_nest_pas_termine(): void
    {
        $campagne = $this->campagne();
        $this->livraisonConfirmee($campagne, 'prete');
        $this->livraisonConfirmee($campagne, 'en_attente');

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->get(route('livraison.chargement.index', $campagne))
            ->assertOk()
            ->assertSee(self::TITRE_DEMARREE);
    }

    public function test_campagne_non_demarree_le_bouton_mene_au_hub_pour_demarrer(): void
    {
        $campagne = $this->campagne('preparation');
        $this->livraisonConfirmee($campagne, 'en_attente');

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->get(route('livraison.chargement.index', $campagne))
            ->assertOk()
            ->assertSee(self::TITRE_NON_DEMARREE)
            ->assertSee('Démarrer la campagne')
            ->assertSee('<a href="' . route('livraison.campagnes.show', $campagne) . '"', false)
            ->assertDontSee(self::TITRE_DEMARREE);
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
            ->assertSee(self::TITRE_DEMARREE)
            ->assertSee('🚚')
            ->assertSee('Prévenez un admin/gestionnaire')
            ->assertDontSee('?generer=1', false)
            ->assertDontSee('Générer les routes');
    }

    public function test_pas_de_rappel_sans_aucune_famille_confirmee(): void
    {
        $campagne = $this->campagne();
        $this->livraisonConfirmee($campagne, 'prete', ['statut_contact' => 'a_contacter']);

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->get(route('livraison.chargement.index', $campagne))
            ->assertOk()
            ->assertDontSee(self::TITRE_DEMARREE)
            ->assertDontSee(self::TITRE_NON_DEMARREE);
    }

    public function test_les_familles_qui_se_deplacent_ne_declenchent_pas_le_rappel(): void
    {
        $campagne = $this->campagne();
        $this->livraisonConfirmee($campagne, 'prete', ['se_deplace' => true]);

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->get(route('livraison.chargement.index', $campagne))
            ->assertOk()
            ->assertDontSee(self::TITRE_DEMARREE);
    }

    public function test_pas_de_rappel_des_quune_tournee_existe(): void
    {
        $campagne = $this->campagne();
        $this->livraisonConfirmee($campagne, 'prete');
        RouteLivraison::create([
            'id_campagne' => $campagne->id,
            'id_benevole' => $this->creerPersonne(['benevole'])->id,
            'id_vehicule_type' => 1,
            'creneau' => '08-10',
            'statut' => 'planifiee',
        ]);

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->get(route('livraison.chargement.index', $campagne))
            ->assertOk()
            ->assertDontSee(self::TITRE_DEMARREE);
    }

    public function test_le_polling_renvoie_le_rappel_et_sa_signature(): void
    {
        $campagne = $this->campagne();
        $gestionnaire = $this->creerPersonne(['gestionnaire']);

        $vide = $this->actingAs($gestionnaire)->getJson(route('livraison.chargement.liste', $campagne))
            ->assertOk()
            ->assertJsonPath('rappel.html', '')
            ->json();

        $this->livraisonConfirmee($campagne, 'en_attente');

        $rempli = $this->getJson(route('livraison.chargement.liste', $campagne))->assertOk()->json();

        $this->assertStringContainsString(self::TITRE_DEMARREE, $rempli['rappel']['html']);
        $this->assertNotSame($vide['rappel']['sig'], $rempli['rappel']['sig']);
    }
}
