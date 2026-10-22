<?php
// tests/Feature/Http/EtiquettesEtImpressionsTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Amana\Shared\Models\Personne;
use App\Http\Controllers\Livraison\ChargementController;
use App\Models\Campagne;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\LivraisonColis;
use App\Models\RouteLivraison;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Octobre 2026 — documents imprimés et écrans d'appoint :
 *   - planche d'étiquettes A4 3×4 (recto : numéro de famille ; verso : QR), familles se_deplace comprises,
 *     aucun nom imprimé ;
 *   - QR → /livraison/scan/{livraison} : retrait QG ou arrêt courant ;
 *   - feuille de préparation : familles confirmées, une case par colis, livraison imposée nommée ;
 *   - feuille de tournée imprimable ; Retrait QG : numéro, colis, téléphones, email.
 */
class EtiquettesEtImpressionsTest extends TestCase
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

    private function livraison(array $famille = [], array $surcharge = [], int $colis = 1): Livraison
    {
        $livraison = Livraison::create(array_merge([
            'id_famille' => Famille::factory()->create($famille)->id,
            'id_campagne' => $this->campagne->id,
            'statut' => 'non_assignee',
            'statut_conditionnement' => 'en_attente',
            'nombre_personnes' => 3,
            'poids_kg' => 10.0,
            'statut_contact' => 'confirme',
            'se_deplace' => false,
        ], $surcharge));

        for ($numero = 1; $numero <= $colis; $numero++) {
            LivraisonColis::create(['id_livraison' => $livraison->id, 'numero' => $numero]);
        }

        return $livraison;
    }

    // ── Étiquettes ───────────────────────────────────────────────────────

    public function test_les_etiquettes_incluent_les_familles_qui_se_deplacent_sans_aucun_nom(): void
    {
        $livree = $this->livraison(['nom' => 'Nomsecret', 'prenom' => 'Prenomsecret'], [], 2);
        $retrait = $this->livraison([], ['se_deplace' => true], 1);
        $this->livraison([], ['statut_contact' => 'rejete'], 1);

        $html = $this->actingAs($this->gestionnaire())
            ->get(route('livraison.chargement.etiquettes', $this->campagne))
            ->assertOk()
            ->getContent();

        $this->assertSame(3, substr_count($html, '<svg'), 'un QR par colis des familles confirmées (2 + 1)');
        $this->assertStringContainsString('>' . $livree->id_famille . '</span>', $html);
        $this->assertStringContainsString('>' . $retrait->id_famille . '</span>', $html);
        $this->assertStringContainsString('1/2', $html);
        $this->assertStringContainsString('2/2', $html);
        $this->assertStringContainsString('1/1', $html);
        $this->assertStringNotContainsString('Nomsecret', $html);
        $this->assertStringNotContainsString('Prenomsecret', $html);
        $this->assertStringNotContainsString('QR indisponible', $html);
    }

    public function test_douze_etiquettes_par_planche_chacune_sur_deux_pages(): void
    {
        for ($i = 0; $i < 13; $i++) {
            $this->livraison();
        }

        $html = $this->actingAs($this->gestionnaire())
            ->get(route('livraison.chargement.etiquettes', $this->campagne))
            ->assertOk()
            ->getContent();

        $this->assertSame(2, substr_count($html, 'class="page recto"'));
        $this->assertSame(2, substr_count($html, 'class="page verso"'));
    }

    public function test_le_verso_est_inverse_ligne_par_ligne_pour_le_recto_verso(): void
    {
        $etiquettes = array_map(fn(int $i) => ['famille_id' => $i, 'numero' => 1, 'total' => 1, 'qr' => ''], range(1, 5));

        $methode = new \ReflectionMethod(ChargementController::class, 'paginerEtiquettes');
        $pages = $methode->invoke(app(ChargementController::class), $etiquettes);

        $this->assertCount(1, $pages);
        $this->assertCount(12, $pages['0']['recto']);
        $this->assertCount(12, $pages['0']['verso']);
        $this->assertSame([1, 2, 3, 4, 5], array_column(array_filter($pages[0]['recto']), 'famille_id'));
        // Ligne 1 : 1,2,3 → 3,2,1 ; ligne 2 : 4,5,vide → vide,5,4.
        $this->assertSame([3, 2, 1], array_column(array_slice($pages[0]['verso'], 0, 3), 'famille_id'));
        $this->assertNull($pages[0]['verso'][3]);
        $this->assertSame(5, $pages[0]['verso'][4]['famille_id']);
        $this->assertSame(4, $pages[0]['verso'][5]['famille_id']);
    }

    // ── Cible des QR ─────────────────────────────────────────────────────

    public function test_le_scan_d_une_famille_qui_se_deplace_mene_au_retrait_qg(): void
    {
        $livraison = $this->livraison([], ['se_deplace' => true]);

        $this->actingAs($this->gestionnaire())
            ->get(route('livraison.scan', $livraison))
            ->assertRedirect(route('livraison.retrait-hq.scan', $livraison));
    }

    public function test_le_scan_mene_a_l_arret_de_la_tournee_courante(): void
    {
        $livraison = $this->livraison([], ['statut' => 'assignee']);
        $route = $this->route('planifiee', [[$livraison]]);
        $etape = $route->etapes()->first();

        $this->actingAs($this->gestionnaire())
            ->get(route('livraison.scan', $livraison))
            ->assertRedirect(route('livraison.benevole.etapes.scan', $etape));
    }

    public function test_le_scan_ignore_une_tournee_abandonnee(): void
    {
        $livraison = $this->livraison([], ['statut' => 'assignee']);
        $this->route('annulee', [[$livraison, 'annulee']]);

        $this->actingAs($this->gestionnaire())
            ->get(route('livraison.scan', $livraison))
            ->assertNotFound();
    }

    public function test_le_scan_survit_a_la_recomposition_des_tournees(): void
    {
        $livraison = $this->livraison([], ['statut' => 'assignee']);
        $ancienne = $this->route('annulee', [[$livraison, 'annulee']]);
        $nouvelle = $this->route('planifiee', [[$livraison]]);

        $this->actingAs($this->gestionnaire())
            ->get(route('livraison.scan', $livraison))
            ->assertRedirect(route('livraison.benevole.etapes.scan', $nouvelle->etapes()->first()));

        $this->assertNotSame($ancienne->id, $nouvelle->id);
    }

    public function test_le_scan_exige_d_etre_connecte(): void
    {
        $this->get(route('livraison.scan', $this->livraison()))->assertRedirect();
    }

    // ── Feuille de préparation ───────────────────────────────────────────

    public function test_la_feuille_de_preparation_ne_liste_que_les_familles_confirmees(): void
    {
        $confirmee = $this->livraison([], [], 1);
        $rejetee = $this->livraison([], ['statut_contact' => 'rejete'], 1);

        $html = $this->actingAs($this->gestionnaire())
            ->get(route('livraison.packaging.feuille-preparation', $this->campagne))
            ->assertOk()
            ->getContent();

        // La feuille n'imprime pas de nom (décision du 05/09/2026) : on repère les familles par leur numéro.
        $this->assertStringContainsString('<td>#' . $confirmee->id_famille . '</td>', $html);
        $this->assertStringNotContainsString('<td>#' . $rejetee->id_famille . '</td>', $html);
    }

    public function test_la_feuille_de_preparation_a_une_case_par_colis(): void
    {
        $this->livraison([], [], 4);

        $html = $this->actingAs($this->gestionnaire())
            ->get(route('livraison.packaging.feuille-preparation', $this->campagne))
            ->assertOk()
            ->getContent();

        $this->assertSame(4, substr_count($html, '<span class="case">'));
    }

    public function test_la_feuille_de_preparation_nomme_le_chauffeur_impose(): void
    {
        $chauffeur = $this->creerPersonne(['benevole'], ['nom' => 'Imposeur', 'prenom' => 'Karim']);
        $this->livraison([], ['id_benevole_impose' => $chauffeur->id]);

        $html = $this->actingAs($this->gestionnaire())
            ->get(route('livraison.packaging.feuille-preparation', $this->campagne))
            ->assertOk()
            ->getContent();

        // Personne normalise le nom en majuscules (setNomAttribute).
        $this->assertStringContainsString('Imposée — Karim IMPOSEUR', $html);
    }

    // ── Feuille de tournée imprimable ────────────────────────────────────

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

    public function test_la_feuille_de_tournee_liste_adresses_telephones_et_colis(): void
    {
        $livraison = $this->livraison([
            'adresse' => '12 rue des Lilas',
            'code_postal' => '44000',
            'ville_texte' => 'Nantes',
            'telephone' => '0612345678',
        ], ['statut' => 'assignee'], 3);
        $annulee = $this->livraison(['adresse' => '99 impasse Fantome'], ['statut' => 'non_assignee']);
        $route = $this->route('planifiee', [[$livraison]]);
        EtapeRoute::create(['id_route' => $route->id, 'id_livraison' => $annulee->id, 'ordre' => 2, 'statut' => 'annulee']);

        $reponse = $this->actingAs($this->gestionnaire())
            ->get(route('livraison.routes.imprimer', $route))
            ->assertOk();

        $reponse->assertSee('12 rue des Lilas', false);
        $reponse->assertSee('12 34 56 78', false);
        $reponse->assertSee('3 colis', false);
        $reponse->assertDontSee('99 impasse Fantome', false);
    }

    public function test_la_feuille_de_tournee_est_reservee_au_staff(): void
    {
        $route = $this->route('planifiee', [[$this->livraison([], ['statut' => 'assignee'])]]);

        $this->actingAs($this->creerPersonne(['benevole']))
            ->get(route('livraison.routes.imprimer', $route))
            ->assertRedirect();
    }

    // ── Retrait QG ───────────────────────────────────────────────────────

    public function test_le_retrait_qg_affiche_numero_colis_telephones_et_email(): void
    {
        $retrait = $this->livraison([
            'nom' => 'Durand',
            'prenom' => 'Awa',
            'email' => 'awa@amana-test.fr',
            'telephone' => '0611223344',
            'telephone_bis' => '0655667788',
            'nombre_enfant' => 2,
        ], ['se_deplace' => true, 'statut_conditionnement' => 'prete'], 3);

        $ligne = $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.retrait-hq.liste', $this->campagne))
            ->assertOk()
            ->json('lignes.0.html');

        $this->assertStringContainsString('#' . $retrait->id_famille . ' Awa Durand', preg_replace('/\s+/', ' ', $ligne));
        $this->assertStringNotContainsString('dossier #', $ligne);
        $this->assertStringContainsString('3 colis', $ligne);
        $this->assertStringContainsString('2 enfants', $ligne);
        $this->assertStringContainsString('tel:0611223344', $ligne);
        $this->assertStringContainsString('tel:0655667788', $ligne);
        $this->assertStringContainsString('mailto:awa@amana-test.fr', $ligne);
    }

    public function test_le_retrait_qg_omet_les_coordonnees_absentes(): void
    {
        // familles.telephone est NOT NULL : « absent » = chaîne vide.
        $this->livraison(['email' => null, 'telephone' => '', 'telephone_bis' => null], ['se_deplace' => true, 'statut_conditionnement' => 'prete']);

        $ligne = $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.retrait-hq.liste', $this->campagne))
            ->assertOk()
            ->json('lignes.0.html');

        $this->assertStringNotContainsString('tel:', $ligne);
        $this->assertStringNotContainsString('mailto:', $ligne);
    }
}
