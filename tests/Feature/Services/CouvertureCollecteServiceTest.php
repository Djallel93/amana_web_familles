<?php
// tests/Feature/Services/CouvertureCollecteServiceTest.php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Campagne;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\LivraisonColis;
use App\Services\CouvertureCollecteService;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * 18/10/2026 — « Reste à conditionner » par colis : une famille dont une partie des colis est prête n'est plus
 * comptée « engagée » en entier (le reste tombait à 0 alors que des familles n'avaient pas tous leurs colis).
 */
class CouvertureCollecteServiceTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
    }

    private function campagne(): Campagne
    {
        return Campagne::create([
            'type' => 'zakat_el_fitr',
            'statut' => 'en_cours',
            'date_livraison' => '2026-11-10',
            'poids_moyen_kg' => 5,
        ]);
    }

    /** @param  int  $prets  colis prêts sur $personnes */
    private function livraison(Campagne $campagne, string $conditionnement, int $personnes, float $poids, int $prets = 0): Livraison
    {
        $livraison = Livraison::create([
            'id_famille' => Famille::factory()->create(['est_hotel' => false, 'etudiant' => false])->id,
            'id_campagne' => $campagne->id,
            'statut' => 'assignee',
            'statut_conditionnement' => $conditionnement,
            'statut_contact' => 'confirme',
            'nombre_personnes' => $personnes,
            'poids_kg' => $poids,
        ]);

        // Colis explicites : on repart d'une liste vide quoi que fasse la création de la livraison.
        $livraison->colis()->delete();
        for ($n = 1; $n <= $personnes; $n++) {
            LivraisonColis::create([
                'id_livraison' => $livraison->id,
                'numero' => $n,
                'statut' => $n <= $prets ? 'pret' : 'a_preparer',
            ]);
        }

        return $livraison;
    }

    public function test_une_famille_a_moitie_prete_laisse_la_moitie_de_son_poids_a_conditionner(): void
    {
        $campagne = $this->campagne();
        // 4 personnes à 5 kg = 20 kg, 2 colis prêts sur 4.
        $this->livraison($campagne, 'en_cours', 4, 20.0, prets: 2);

        $couverture = (new CouvertureCollecteService())->calculer($campagne);

        $this->assertSame(10.0, $couverture['engage_kg']);
        // Avant le correctif : 0 (la famille sortait du reste et comptait en entier dans l'engagé).
        $this->assertSame(10.0, $couverture['reste_kg']);
    }

    public function test_le_reste_n_est_plus_nul_quand_toutes_les_familles_ont_au_moins_un_colis_pret(): void
    {
        $campagne = $this->campagne();
        $this->livraison($campagne, 'en_cours', 2, 10.0, prets: 1);
        $this->livraison($campagne, 'en_cours', 3, 15.0, prets: 1);

        $couverture = (new CouvertureCollecteService())->calculer($campagne);

        $this->assertSame(5.0 + 5.0, $couverture['engage_kg']);
        $this->assertSame(5.0 + 10.0, $couverture['reste_kg']);
        $this->assertGreaterThan(0, $couverture['reste_kg']);
    }

    public function test_les_livraisons_pretes_ou_livrees_sont_engagees_en_entier_et_les_en_attente_restent_en_entier(): void
    {
        $campagne = $this->campagne();
        $this->livraison($campagne, 'prete', 3, 15.0, prets: 3);
        $this->livraison($campagne, 'en_attente', 2, 10.0);

        $couverture = (new CouvertureCollecteService())->calculer($campagne);

        $this->assertSame(15.0, $couverture['engage_kg']);
        $this->assertSame(10.0, $couverture['reste_kg']);
    }

    public function test_la_part_a_preparer_suit_les_poids_moyens_actuels_et_la_part_prete_garde_son_poids_fige(): void
    {
        $campagne = $this->campagne();
        // Poids figé à 40 kg (ancien poids moyen 10 kg/personne), 2 colis prêts sur 4 ; le poids moyen
        // actuel est 5 kg/personne.
        $this->livraison($campagne, 'en_cours', 4, 40.0, prets: 2);

        $couverture = (new CouvertureCollecteService())->calculer($campagne);

        $this->assertSame(20.0, $couverture['engage_kg']);
        $this->assertSame(10.0, $couverture['reste_kg']);
    }

    public function test_une_livraison_commencee_ne_declenche_pas_recalcul_necessaire(): void
    {
        $campagne = $this->campagne();
        // recalculerPoids() ne touche jamais aux livraisons déjà commencées : les comparer afficherait
        // « recalcul nécessaire » en permanence.
        $this->livraison($campagne, 'en_cours', 4, 40.0, prets: 2);

        $this->assertFalse((new CouvertureCollecteService())->calculer($campagne)['recalcul_necessaire']);
    }

    public function test_une_livraison_en_attente_au_poids_perime_declenche_recalcul_necessaire(): void
    {
        $campagne = $this->campagne();
        $this->livraison($campagne, 'en_attente', 4, 40.0);

        $this->assertTrue((new CouvertureCollecteService())->calculer($campagne)['recalcul_necessaire']);
    }
}
