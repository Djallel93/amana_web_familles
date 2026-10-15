<?php
// tests/Feature/Http/ChargementRappelRoutesTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Amana\Shared\Models\Personne;
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

    /** Rappel tel que l'expose la page Inertia (null = pas de rappel). */
    private function rappel(Campagne $campagne, Personne $personne): ?array
    {
        $reponse = $this->actingAs($personne)->get(route('livraison.chargement.index', $campagne))->assertOk();

        return $reponse->viewData('page')['props']['rappel'];
    }

    public function test_le_rappel_mene_a_l_assistant_de_generation_quand_la_campagne_est_demarree(): void
    {
        $campagne = $this->campagne();
        $this->livraisonConfirmee($campagne, 'prete');

        $rappel = $this->rappel($campagne, $this->creerPersonne(['gestionnaire']));

        $this->assertTrue($rappel['demarree']);
        $this->assertTrue($rappel['peut_generer']);
        $this->assertSame(route('livraison.campagnes.show', $campagne) . '?generer=1', $rappel['url_generer']);
    }

    public function test_le_rappel_saffiche_meme_si_le_conditionnement_nest_pas_termine(): void
    {
        $campagne = $this->campagne();
        $this->livraisonConfirmee($campagne, 'prete');
        $this->livraisonConfirmee($campagne, 'en_attente');

        $this->assertNotNull($this->rappel($campagne, $this->creerPersonne(['gestionnaire'])));
    }

    public function test_campagne_non_demarree_le_bouton_mene_au_hub_pour_demarrer(): void
    {
        $campagne = $this->campagne('preparation');
        $this->livraisonConfirmee($campagne, 'en_attente');

        $rappel = $this->rappel($campagne, $this->creerPersonne(['gestionnaire']));

        $this->assertFalse($rappel['demarree']);
        $this->assertSame(route('livraison.campagnes.show', $campagne), $rappel['url_campagne']);
    }

    public function test_une_equipe_sans_droit_de_generation_voit_le_message_mais_pas_le_bouton(): void
    {
        $campagne = $this->campagne();
        $this->livraisonConfirmee($campagne, 'prete');

        $chargeur = $this->creerPersonne(['membre']);
        $campagne->equipeMembres()->create(['id_personne' => $chargeur->id, 'role' => 'equipe_chargement']);

        $rappel = $this->rappel($campagne, $chargeur);

        $this->assertNotNull($rappel);
        $this->assertFalse($rappel['peut_generer']);
    }

    public function test_pas_de_rappel_sans_aucune_famille_confirmee(): void
    {
        $campagne = $this->campagne();
        $this->livraisonConfirmee($campagne, 'prete', ['statut_contact' => 'a_contacter']);

        $this->assertNull($this->rappel($campagne, $this->creerPersonne(['gestionnaire'])));
    }

    public function test_les_familles_qui_se_deplacent_ne_declenchent_pas_le_rappel(): void
    {
        $campagne = $this->campagne();
        $this->livraisonConfirmee($campagne, 'prete', ['se_deplace' => true]);

        $this->assertNull($this->rappel($campagne, $this->creerPersonne(['gestionnaire'])));
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

        $this->assertNull($this->rappel($campagne, $this->creerPersonne(['gestionnaire'])));
    }

    public function test_le_polling_renvoie_le_rappel(): void
    {
        $campagne = $this->campagne();
        $gestionnaire = $this->creerPersonne(['gestionnaire']);

        $this->actingAs($gestionnaire)->getJson(route('livraison.chargement.liste', $campagne))
            ->assertOk()
            ->assertJsonPath('rappel', null);

        $this->livraisonConfirmee($campagne, 'en_attente');

        $this->getJson(route('livraison.chargement.liste', $campagne))
            ->assertOk()
            ->assertJsonPath('rappel.demarree', true)
            ->assertJsonPath('rappel.peut_generer', true);
    }
}
