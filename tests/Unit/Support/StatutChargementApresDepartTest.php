<?php
// tests/Unit/Support/StatutChargementApresDepartTest.php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\EtapeRoute;
use App\Models\RouteLivraison;
use App\Support\StatutChargement;
use PHPUnit\Framework\TestCase;

/**
 * 17/10/2026 — statut affiché d'une tournée DÉJÀ PARTIE : en livraison tant qu'un arrêt reste à faire,
 * livrée quand la route est terminée OU que tous ses arrêts sont réglés (livrés ou ignorés).
 * Pur calcul : aucune base de données.
 */
class StatutChargementApresDepartTest extends TestCase
{
    /** @param list<string> $statutsArrets */
    private function route(string $statut, array $statutsArrets): RouteLivraison
    {
        $route = (new RouteLivraison())->forceFill(['statut' => $statut]);
        $route->setRelation('etapes', collect(array_map(
            fn(string $s) => (new EtapeRoute())->forceFill(['statut' => $s]),
            $statutsArrets,
        )));

        return $route;
    }

    public function test_une_route_en_cours_avec_un_arret_a_faire_est_en_livraison(): void
    {
        $this->assertSame(StatutChargement::EN_LIVRAISON, StatutChargement::pourRoute($this->route('en_cours', ['livree', 'en_attente'])));
        $this->assertSame(StatutChargement::EN_LIVRAISON, StatutChargement::pourRoute($this->route('en_cours', ['ignoree', 'en_cours'])));
    }

    public function test_une_route_en_cours_dont_tous_les_arrets_sont_regles_est_livree_ignorees_comprises(): void
    {
        $this->assertSame(StatutChargement::LIVREE, StatutChargement::pourRoute($this->route('en_cours', ['livree', 'livree'])));
        $this->assertSame(StatutChargement::LIVREE, StatutChargement::pourRoute($this->route('en_cours', ['livree', 'ignoree'])));
        $this->assertSame(StatutChargement::LIVREE, StatutChargement::pourRoute($this->route('en_cours', ['ignoree'])));
    }

    public function test_une_route_terminee_est_livree_quel_que_soit_ses_arrets(): void
    {
        $this->assertSame(StatutChargement::LIVREE, StatutChargement::pourRoute($this->route('livraisons_terminees', ['en_attente'])));
        $this->assertSame(StatutChargement::LIVREE, StatutChargement::pourRoute($this->route('terminee', [])));
    }

    public function test_une_route_en_cours_sans_arret_reste_en_livraison(): void
    {
        $this->assertSame(StatutChargement::EN_LIVRAISON, StatutChargement::pourRoute($this->route('en_cours', [])));
    }

    public function test_les_filtres_et_libelles_des_nouveaux_etats(): void
    {
        $this->assertSame(StatutChargement::EN_LIVRAISON, StatutChargement::FILTRES['en_livraison']);
        $this->assertSame(StatutChargement::LIVREE, StatutChargement::FILTRES['terminees']);
        $this->assertSame('En livraison', StatutChargement::LIBELLES[StatutChargement::EN_LIVRAISON]);
        $this->assertSame('Livrée', StatutChargement::LIBELLES[StatutChargement::LIVREE]);
        $this->assertArrayHasKey(StatutChargement::LIVREE, StatutChargement::STYLES);
    }

    public function test_le_tri_met_les_routes_parties_apres_la_chargee(): void
    {
        $this->assertLessThan(StatutChargement::rangTri(StatutChargement::CHARGEE), StatutChargement::rangTri(StatutChargement::PRETE));
        $this->assertLessThan(StatutChargement::rangTri(StatutChargement::EN_LIVRAISON), StatutChargement::rangTri(StatutChargement::CHARGEE));
        $this->assertLessThan(StatutChargement::rangTri(StatutChargement::LIVREE), StatutChargement::rangTri(StatutChargement::EN_LIVRAISON));
    }
}
