<?php
// tests/Feature/Http/CampagneHubCompteursTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Campagne;
use App\Models\CampagneArrivee;
use App\Models\Donation;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\LivraisonColis;
use App\Models\RouteLivraison;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * 17/10/2026 — compteurs du hub campagne (GET livraison/campagnes/{id}/avancement) affichés à côté du
 * statut des cartes : familles encore sans tournée (Génération des routes), personnes réceptionnées,
 * poids collecté (kg) et colis prêts / total (Packaging).
 */
class CampagneHubCompteursTest extends TestCase
{
    use SeedsCommunFixtures;

    private Campagne $campagne;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
        $this->campagne = Campagne::create([
            'type' => 'zakat_el_fitr',
            'statut' => 'en_cours',
            'date_livraison' => '2026-11-10',
            'poids_moyen_kg' => 5,
        ]);
    }

    private function livraison(array $surcharge = [], ?Campagne $campagne = null): Livraison
    {
        return Livraison::create(array_merge([
            'id_famille' => Famille::factory()->create()->id,
            'id_campagne' => ($campagne ?? $this->campagne)->id,
            'statut' => 'non_assignee',
            'statut_conditionnement' => 'en_attente',
            'nombre_personnes' => 2,
            'poids_kg' => 10.0,
            'statut_contact' => 'confirme',
            'se_deplace' => false,
        ], $surcharge));
    }

    private function compteurs(): array
    {
        return $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->getJson(route('livraison.campagnes.avancement', $this->campagne))
            ->assertOk()
            ->json('compteurs');
    }

    public function test_familles_sans_route_compte_les_confirmees_non_placees_hors_retrait_qg(): void
    {
        $this->livraison();                                                    // sans route : compte
        $this->livraison();                                                    // sans route : compte
        $this->livraison(['se_deplace' => true]);                              // retrait QG : pas de route
        $this->livraison(['statut_contact' => 'a_contacter']);                 // pas confirmée
        $this->livraison(['statut_contact' => 'archive']);                     // archivée
        $this->livraison(['statut' => 'assignee']);                            // déjà dans une tournée

        $this->assertSame(2, $this->compteurs()['familles_sans_route']);
    }

    public function test_familles_sans_route_tombe_a_zero_quand_tout_est_place(): void
    {
        $livraison = $this->livraison();
        $route = RouteLivraison::create([
            'id_campagne' => $this->campagne->id,
            'id_benevole' => $this->creerPersonne(['benevole'])->id,
            'id_vehicule_type' => 1,
            'creneau' => '08-10',
            'statut' => 'planifiee',
        ]);
        EtapeRoute::create(['id_route' => $route->id, 'id_livraison' => $livraison->id, 'ordre' => 1, 'statut' => 'en_attente']);
        $livraison->update(['statut' => 'assignee']);

        $this->assertSame(0, $this->compteurs()['familles_sans_route']);
    }

    public function test_personnes_receptionnees_et_poids_collecte_sont_ceux_de_la_campagne(): void
    {
        $autre = Campagne::create([
            'type' => 'zakat_el_fitr',
            'statut' => 'en_cours',
            'date_livraison' => '2026-12-01',
            'poids_moyen_kg' => 5,
        ]);
        $logueur = $this->creerPersonne(['benevole'])->id;

        foreach ([3, 4] as $n) {
            CampagneArrivee::create(['id_campagne' => $this->campagne->id, 'nombre_donateur' => $n, 'horodatage' => now(), 'logge_par' => $logueur]);
        }
        CampagneArrivee::create(['id_campagne' => $autre->id, 'nombre_donateur' => 50, 'horodatage' => now(), 'logge_par' => $logueur]);

        foreach ([12.5, 7.25] as $kg) {
            Donation::create(['id_campagne' => $this->campagne->id, 'poids_kg' => $kg, 'horodatage' => now(), 'logge_par' => $logueur]);
        }
        Donation::create(['id_campagne' => $autre->id, 'poids_kg' => 99, 'horodatage' => now(), 'logge_par' => $logueur]);

        $compteurs = $this->compteurs();

        $this->assertSame(7, $compteurs['personnes_receptionnees']);
        $this->assertEquals(19.8, $compteurs['poids_collecte_kg']); // 19,75 arrondi à 0,1
    }

    public function test_colis_prets_sur_total_compte_aussi_les_familles_livrees(): void
    {
        $livree = $this->livraison(['statut' => 'livree', 'statut_conditionnement' => 'prete']);
        $enCours = $this->livraison(['statut' => 'assignee', 'statut_conditionnement' => 'en_cours']);
        $nonConfirmee = $this->livraison(['statut_contact' => 'a_contacter']);

        foreach ([1, 2] as $numero) {
            LivraisonColis::create(['id_livraison' => $livree->id, 'numero' => $numero, 'statut' => 'pret']);
        }
        LivraisonColis::create(['id_livraison' => $enCours->id, 'numero' => 1, 'statut' => 'pret']);
        LivraisonColis::create(['id_livraison' => $enCours->id, 'numero' => 2, 'statut' => 'a_preparer']);
        LivraisonColis::create(['id_livraison' => $nonConfirmee->id, 'numero' => 1, 'statut' => 'pret']);

        $compteurs = $this->compteurs();

        $this->assertSame(3, $compteurs['colis_prets']);
        $this->assertSame(4, $compteurs['colis_total']);
    }

    public function test_les_compteurs_sont_a_zero_sur_une_campagne_vide(): void
    {
        $compteurs = $this->compteurs();

        $this->assertSame(0, $compteurs['familles_sans_route']);
        $this->assertSame(0, $compteurs['personnes_receptionnees']);
        $this->assertEquals(0, $compteurs['poids_collecte_kg']);
        $this->assertSame(0, $compteurs['colis_prets']);
        $this->assertSame(0, $compteurs['colis_total']);
    }
}
