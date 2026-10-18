<?php
// tests/Feature/Http/ChargementEtPackagingApresLivraisonTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Campagne;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteLivraison;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * 17/10/2026 — les lignes ne disparaissent plus une fois les livraisons commencées / terminées :
 *   - Chargement : les tournées parties restent listées (« En livraison » / « Livrée », lecture seule),
 *     nouveaux filtres « En livraison » et « Terminées » ; une livraison ignorée compte comme terminée ;
 *   - Packaging : les familles livrées / ignorées restent dans la file (grisées, en dernier), filtre
 *     « Livrées », et la carte « Terminées » ne baisse plus au fil des livraisons.
 */
class ChargementEtPackagingApresLivraisonTest extends TestCase
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

    private function gestionnaire()
    {
        return $this->creerPersonne(['gestionnaire']);
    }

    private function livraison(string $conditionnement = 'prete', string $statut = 'assignee'): Livraison
    {
        return Livraison::create([
            'id_famille' => Famille::factory()->create()->id,
            'id_campagne' => $this->campagne->id,
            'statut' => $statut,
            'statut_conditionnement' => $conditionnement,
            'nombre_personnes' => 3,
            'poids_kg' => 10.0,
            'statut_contact' => 'confirme',
            'se_deplace' => false,
        ]);
    }

    /** @param array<int, array{0: Livraison, 1?: string}> $arrets [livraison, statut d'arrêt] */
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

    private function chargement(string $filtre = 'toutes'): array
    {
        return $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.chargement.liste', $this->campagne) . '?filtre_chargement=' . $filtre)
            ->assertOk()
            ->json();
    }

    /** @return array<int, string> id route => statut affiché */
    private function etats(array $liste): array
    {
        return collect($liste['routes'])->pluck('etat', 'id')->all();
    }

    private function packaging(string $filtre = 'toutes'): array
    {
        return $this->actingAs($this->gestionnaire())
            ->getJson(route('livraison.packaging.liste', $this->campagne) . '?filtre_conditionnement=' . $filtre)
            ->assertOk()
            ->json();
    }

    /** @return list<int> id des livraisons listées par Packaging, dans l'ordre affiché */
    private function idsPackaging(array $liste): array
    {
        return array_map('intval', array_column($liste['lignes'], 'id'));
    }

    // ── Chargement ───────────────────────────────────────────────────────

    public function test_chargement_garde_les_tournees_parties_et_terminees(): void
    {
        $enCours = $this->route('en_cours', [[$this->livraison(), 'en_attente'], [$this->livraison(), 'livree']]);
        $livraisonsTerminees = $this->route('livraisons_terminees', [[$this->livraison(), 'livree']]);
        $terminee = $this->route('terminee', [[$this->livraison(), 'livree']]);
        $planifiee = $this->route('planifiee', [[$this->livraison('en_attente')]]);

        $etats = $this->etats($this->chargement());

        $this->assertSame('en_livraison', $etats[$enCours->id]);
        $this->assertSame('livree', $etats[$livraisonsTerminees->id]);
        $this->assertSame('livree', $etats[$terminee->id]);
        $this->assertSame('restante', $etats[$planifiee->id]);
    }

    public function test_une_tournee_annulee_reste_masquee(): void
    {
        $annulee = $this->route('annulee', [[$this->livraison()]]);

        $this->assertArrayNotHasKey($annulee->id, $this->etats($this->chargement()));
    }

    public function test_une_livraison_ignoree_compte_comme_terminee(): void
    {
        // Tous les arrêts réglés — livrés OU ignorés — alors que le chauffeur n'a pas clôturé : Terminée.
        $route = $this->route('en_cours', [[$this->livraison(), 'livree'], [$this->livraison(), 'ignoree']]);
        // Un arrêt encore à faire : toujours « En livraison ».
        $enCours = $this->route('en_cours', [[$this->livraison(), 'ignoree'], [$this->livraison(), 'en_attente']]);

        $etats = $this->etats($this->chargement());

        $this->assertSame('livree', $etats[$route->id]);
        $this->assertSame('en_livraison', $etats[$enCours->id]);
    }

    public function test_les_filtres_en_livraison_et_terminees_et_les_stats(): void
    {
        $enCours = $this->route('en_cours', [[$this->livraison(), 'en_attente']]);
        $terminee = $this->route('terminee', [[$this->livraison(), 'livree']]);
        $planifiee = $this->route('planifiee', [[$this->livraison('en_attente')]]);

        $this->assertSame([$enCours->id => 'en_livraison'], $this->etats($this->chargement('en_livraison')));
        $this->assertSame([$terminee->id => 'livree'], $this->etats($this->chargement('terminees')));

        $toutes = $this->chargement('toutes');
        $this->assertEqualsCanonicalizing([$enCours->id, $terminee->id, $planifiee->id], array_keys($this->etats($toutes)));
        $this->assertSame(1, $toutes['stats']['en_livraison']);
        $this->assertSame(1, $toutes['stats']['terminees']);
        $this->assertSame(1, $toutes['stats']['restantes']);
    }

    public function test_une_tournee_partie_est_en_lecture_seule_et_triee_en_dernier(): void
    {
        $terminee = $this->route('terminee', [[$this->livraison(), 'livree']]);
        $planifiee = $this->route('planifiee', [[$this->livraison('en_attente')]]);

        $liste = $this->chargement();
        $parId = collect($liste['routes'])->keyBy('id');

        $this->assertTrue($parId[$terminee->id]['partie']);
        $this->assertFalse($parId[$terminee->id]['vide']);
        $this->assertFalse($parId[$terminee->id]['peut_confirmer']);
        $this->assertFalse($parId[$terminee->id]['peut_prioriser']);
        $this->assertFalse($parId[$terminee->id]['peut_supprimer']);
        $this->assertNull($parId[$terminee->id]['urgence']);
        $this->assertSame('livree', $parId[$terminee->id]['etapes'][0]['statut_arret']);
        $this->assertFalse($parId[$planifiee->id]['partie']);

        $this->assertSame([$planifiee->id, $terminee->id], array_column($liste['routes'], 'id'));
    }

    // ── Packaging ────────────────────────────────────────────────────────

    public function test_packaging_garde_les_familles_livrees_et_ignorees_en_dernier(): void
    {
        $livree = $this->livraison('prete', 'livree');
        $ignoree = $this->livraison('en_attente', 'ignoree');
        $restante = $this->livraison('en_attente', 'assignee');

        $ids = $this->idsPackaging($this->packaging());

        $this->assertEqualsCanonicalizing([$livree->id, $ignoree->id, $restante->id], $ids);
        $this->assertSame($restante->id, $ids[0], 'La famille à préparer passe avant les livrées / ignorées.');
    }

    public function test_packaging_filtre_livrees_et_stats(): void
    {
        $livree = $this->livraison('prete', 'livree');
        $ignoree = $this->livraison('en_attente', 'ignoree');
        $this->livraison('en_attente', 'assignee');
        $this->livraison('en_cours', 'assignee');
        $this->livraison('prete', 'assignee');

        $livrees = $this->packaging('livrees');
        $this->assertEqualsCanonicalizing([$livree->id, $ignoree->id], $this->idsPackaging($livrees));

        $stats = $this->packaging('toutes')['stats'];
        $this->assertSame(2, $stats['terminees'], 'Terminées = prêtes, livrées comprises : la carte ne baisse pas à la livraison.');
        $this->assertSame(1, $stats['restantes'], 'Une famille ignorée n\'a plus rien à préparer.');
        $this->assertSame(1, $stats['en_cours']);
        $this->assertSame(2, $stats['livrees']);

        // « Restantes » ne ressort pas la famille ignorée ; « Terminées » inclut la livrée.
        $this->assertCount(1, $this->idsPackaging($this->packaging('restantes')));
        $this->assertContains($livree->id, $this->idsPackaging($this->packaging('terminees')));
    }

    public function test_une_ligne_livree_est_grisee_et_non_modifiable(): void
    {
        $livree = $this->livraison('prete', 'livree');

        $html = $this->packaging()['lignes'][0]['html'];

        $this->assertSame($livree->id, $this->packaging()['lignes'][0]['id']);
        $this->assertStringContainsString('opacity-60', $html);
        $this->assertStringContainsString('Livrée', $html);
        $this->assertStringContainsString('disabled', $html);
        // Pas la pastille de conditionnement (« class="statut-conditionnement… », réécrite par le polling) :
        // l'attribut data-statut-conditionnement des cases, lui, reste présent.
        $this->assertStringNotContainsString('class="statut-conditionnement', $html);
    }
}
