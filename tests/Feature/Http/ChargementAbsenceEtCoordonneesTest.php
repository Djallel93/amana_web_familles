<?php
// tests/Feature/Http/ChargementAbsenceEtCoordonneesTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Amana\Shared\Models\Personne;
use App\Models\Campagne;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\LivraisonColis;
use App\Models\RouteLivraison;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Octobre 2026 — écran Chargement :
 *   - « Bénévole absent » abandonne la tournée (statut « annulee », pas « terminee ») et annule
 *     ses arrêts non livrés ; les arrêts déjà livrés gardent leur statut ;
 *   - arrêts et familles sans tournée exposent colis, adresse complète et téléphones — jamais le nom ;
 *   - les référentiels de l'assistant de génération ne sont envoyés que tant que le rappel est affiché.
 */
class ChargementAbsenceEtCoordonneesTest extends TestCase
{
    use SeedsCommunFixtures;

    private Campagne $campagne;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerRolesFamilles();
        $this->campagne = Campagne::create([
            'type' => 'zakat_el_fitr',
            'statut' => 'en_cours',
            'date_livraison' => '2026-11-10',
            'poids_moyen_kg' => 5,
        ]);
    }

    private function gestionnaire(): Personne
    {
        return $this->creerPersonne(['gestionnaire']);
    }

    private function livraison(array $famille = [], array $surcharge = []): Livraison
    {
        return Livraison::create(array_merge([
            'id_famille' => Famille::factory()->create($famille)->id,
            'id_campagne' => $this->campagne->id,
            'statut' => 'assignee',
            'statut_conditionnement' => 'prete',
            'nombre_personnes' => 3,
            'poids_kg' => 10.0,
            'statut_contact' => 'confirme',
            'se_deplace' => false,
        ], $surcharge));
    }

    private function route(string $statut, array $arrets): RouteLivraison
    {
        $route = RouteLivraison::create([
            'id_campagne' => $this->campagne->id,
            'id_benevole' => $this->creerPersonne(['benevole'])->id,
            'id_vehicule_type' => 1,
            'creneau' => '08-10',
            'statut' => $statut,
        ]);

        foreach (array_values($arrets) as $i => $arret) {
            EtapeRoute::create([
                'id_route' => $route->id,
                'id_livraison' => $arret[0]->id,
                'ordre' => $i + 1,
                'statut' => $arret[1] ?? 'en_attente',
            ]);
        }

        return $route;
    }

    // ── Bénévole absent ──────────────────────────────────────────────────

    public function test_benevole_absent_annule_la_tournee_et_ses_arrets_non_livres(): void
    {
        $aLivrer = $this->livraison();
        $dejaLivree = $this->livraison(surcharge: ['statut' => 'livree']);
        $route = $this->route('planifiee', [[$aLivrer, 'en_attente'], [$dejaLivree, 'livree']]);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.chargement.benevole-absent', $route), ['notes' => 'Injoignable'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('annulee', $route->fresh()->statut);
        $this->assertDatabaseHas('etapes_route', ['id_route' => $route->id, 'id_livraison' => $aLivrer->id, 'statut' => 'annulee']);
        $this->assertDatabaseHas('etapes_route', ['id_route' => $route->id, 'id_livraison' => $dejaLivree->id, 'statut' => 'livree']);
        $this->assertSame('non_assignee', $aLivrer->fresh()->statut);
        $this->assertDatabaseHas('route_incidents', ['id_route' => $route->id, 'type' => 'benevole_absent']);
    }

    public function test_une_tournee_abandonnee_disparait_de_la_liste_chargement(): void
    {
        $route = $this->route('planifiee', [[$this->livraison(), 'en_attente']]);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.chargement.benevole-absent', $route))
            ->assertOk();

        $liste = $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.chargement.liste', $this->campagne))
            ->assertOk()
            ->json();

        $this->assertNotContains($route->id, array_column($liste['routes'], 'id'));
    }

    public function test_le_statut_annulee_n_est_pas_posable_a_la_main(): void
    {
        $this->assertNotContains('annulee', EtapeRoute::STATUTS_MANUELS);
        $this->assertContains('annulee', EtapeRoute::STATUTS);
    }

    // ── Coordonnées et colis ─────────────────────────────────────────────

    public function test_les_arrets_exposent_colis_adresse_et_telephones_sans_le_nom(): void
    {
        $livraison = $this->livraison([
            'nom' => 'Nomsecret',
            'prenom' => 'Prenomsecret',
            'adresse' => '12 rue des Lilas',
            'code_postal' => '44000',
            'ville_texte' => 'Nantes',
            'telephone' => '0612345678',
            'telephone_bis' => '0698765432',
        ]);
        foreach ([1, 2, 3] as $numero) {
            LivraisonColis::create(['id_livraison' => $livraison->id, 'numero' => $numero]);
        }
        $this->route('planifiee', [[$livraison]]);

        $reponse = $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.chargement.liste', $this->campagne))
            ->assertOk();

        $etape = $reponse->json('routes.0.etapes.0');
        $this->assertSame(3, $etape['colis']);
        $this->assertSame('12 rue des Lilas, 44000 Nantes', $etape['adresse']);
        $this->assertSame('0612345678', $etape['telephone']);
        $this->assertSame('0698765432', $etape['telephone_bis']);
        $this->assertStringNotContainsString('Nomsecret', $reponse->getContent());
        $this->assertStringNotContainsString('Prenomsecret', $reponse->getContent());
    }

    public function test_les_familles_sans_tournee_exposent_colis_et_adresse(): void
    {
        $livraison = $this->livraison(['adresse' => '3 quai Ceineray', 'code_postal' => '44000', 'ville_texte' => 'Nantes'], ['statut' => 'non_assignee']);
        LivraisonColis::create(['id_livraison' => $livraison->id, 'numero' => 1]);

        $liste = $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.chargement.liste', $this->campagne))
            ->assertOk()
            ->json();

        $famille = collect($liste['familles'])->firstWhere('id', $livraison->id);
        $this->assertNotNull($famille);
        $this->assertSame(1, $famille['colis']);
        $this->assertSame('3 quai Ceineray, 44000 Nantes', $famille['adresse']);
    }

    // ── Assistant de génération sur la page ──────────────────────────────

    private function props(Personne $personne): array
    {
        return $this->actingAs($personne)
            ->get(route('livraison.chargement.index', $this->campagne))
            ->assertOk()
            ->viewData('page')['props'];
    }

    public function test_les_referentiels_de_l_assistant_sont_envoyes_tant_que_le_rappel_est_affiche(): void
    {
        $this->livraison(surcharge: ['statut' => 'non_assignee']);

        $props = $this->props($this->gestionnaire());

        $this->assertNotNull($props['rappel']);
        $this->assertNotNull($props['generation']);
        foreach (['campagne', 'urls', 'villes', 'secteurs', 'quartiers', 'organisations'] as $cle) {
            $this->assertArrayHasKey($cle, $props['generation']);
        }
    }

    public function test_pas_de_referentiels_une_fois_les_routes_generees(): void
    {
        $this->route('planifiee', [[$this->livraison()]]);

        $props = $this->props($this->gestionnaire());

        $this->assertNull($props['rappel']);
        $this->assertNull($props['generation']);
    }

    public function test_pas_de_referentiels_pour_une_equipe_sans_droit_de_generation(): void
    {
        $this->livraison(surcharge: ['statut' => 'non_assignee']);
        $chargeur = $this->creerPersonne(['membre']);
        $this->campagne->equipeMembres()->create(['id_personne' => $chargeur->id, 'role' => 'equipe_chargement']);

        $props = $this->props($chargeur);

        $this->assertNotNull($props['rappel']);
        $this->assertNull($props['generation']);
    }
}
