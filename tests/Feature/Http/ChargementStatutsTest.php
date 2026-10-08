<?php
// tests/Feature/Http/ChargementStatutsTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Campagne;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Écran Chargement refait le 06/10/2026 : « Famille #{id} » au lieu du nom,
 * statut dérivé du conditionnement (Restante → En préparation → Prête →
 * Chargée, voir App\Support\StatutChargement), stats et filtres alignés,
 * familles sans tournée listées avec leur statut, popup de confirmation +
 * annulation d'un chargement avec incident.
 */
class ChargementStatutsTest extends TestCase
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

    private function livraison(string $conditionnement, array $surcharge = [], array $famille = []): Livraison
    {
        return Livraison::create(array_merge([
            'id_famille' => Famille::factory()->create($famille)->id,
            'id_campagne' => $this->campagne->id,
            'statut' => 'assignee',
            'statut_conditionnement' => $conditionnement,
            'nombre_personnes' => 3,
            'poids_kg' => 10.0,
            'statut_contact' => 'confirme',
            'se_deplace' => false,
        ], $surcharge));
    }

    /** @param Livraison[] $livraisons */
    private function route(string $statut, array $livraisons): RouteLivraison
    {
        $route = RouteLivraison::create([
            'id_campagne' => $this->campagne->id,
            'id_benevole' => $this->creerPersonne(['benevole'])->id,
            'id_vehicule_type' => 1,
            'creneau' => '08-10',
            'statut' => $statut,
        ]);

        foreach (array_values($livraisons) as $i => $livraison) {
            EtapeRoute::create([
                'id_route' => $route->id,
                'id_livraison' => $livraison->id,
                'ordre' => $i + 1,
                'statut' => 'en_attente',
            ]);
        }

        return $route;
    }

    private function liste(string $filtre = 'toutes'): array
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

    // ── Statuts dérivés ──────────────────────────────────────────────────

    public function test_une_tournee_sans_colis_pret_est_restante_et_visible_des_la_planification(): void
    {
        $route = $this->route('planifiee', [$this->livraison('en_attente'), $this->livraison('en_attente')]);

        $this->assertSame([$route->id => 'restante'], $this->etats($this->liste()));
    }

    public function test_une_tournee_avec_au_moins_un_colis_pret_est_en_preparation(): void
    {
        $partielle = $this->route('planifiee', [$this->livraison('en_attente'), $this->livraison('en_cours')]);
        $unePrete = $this->route('planifiee', [$this->livraison('en_attente'), $this->livraison('prete')]);

        $etats = $this->etats($this->liste());
        $this->assertSame('en_preparation', $etats[$partielle->id]);
        $this->assertSame('en_preparation', $etats[$unePrete->id]);
    }

    public function test_une_tournee_dont_tous_les_colis_sont_prets_est_prete(): void
    {
        $route = $this->route('chargement', [$this->livraison('prete'), $this->livraison('prete')]);

        $this->assertSame([$route->id => 'prete'], $this->etats($this->liste()));
    }

    public function test_une_tournee_chargee_est_chargee_et_une_tournee_demarree_disparait(): void
    {
        $chargee = $this->route('charge', [$this->livraison('prete')]);
        $this->route('en_cours', [$this->livraison('prete')]);

        $this->assertSame([$chargee->id => 'chargee'], $this->etats($this->liste()));
    }

    public function test_une_tournee_vide_nest_pas_listee(): void
    {
        $this->route('planifiee', []);

        $this->assertSame([], $this->etats($this->liste()));
    }

    // ── Stats et filtres ─────────────────────────────────────────────────

    public function test_les_stats_comptent_les_quatre_statuts_et_le_packaging_annule_avec_les_restantes(): void
    {
        $this->route('planifiee', [$this->livraison('en_attente')]);
        $this->route('packaging_annule', [$this->livraison('en_attente')]);
        $this->route('planifiee', [$this->livraison('en_cours')]);
        $this->route('chargement', [$this->livraison('prete')]);
        $this->route('charge', [$this->livraison('prete')]);

        $this->assertSame(
            ['chargees' => 1, 'restantes' => 2, 'en_preparation' => 1, 'pretes' => 1],
            $this->liste()['stats'],
        );
    }

    public function test_les_filtres_ne_gardent_que_le_statut_demande_mais_les_stats_restent_globales(): void
    {
        $restante = $this->route('planifiee', [$this->livraison('en_attente')]);
        $enPrepa = $this->route('planifiee', [$this->livraison('en_cours')]);
        $prete = $this->route('chargement', [$this->livraison('prete')]);
        $chargee = $this->route('charge', [$this->livraison('prete')]);

        foreach ([
            'restantes' => $restante,
            'en_preparation' => $enPrepa,
            'pretes' => $prete,
            'chargees' => $chargee,
        ] as $filtre => $attendue) {
            $liste = $this->liste($filtre);
            $this->assertSame([$attendue->id], collect($liste['routes'])->pluck('id')->all(), "filtre {$filtre}");
            $this->assertSame(4, array_sum($liste['stats']), "stats globales avec le filtre {$filtre}");
        }

        $this->assertCount(4, $this->liste('toutes')['routes']);
    }

    public function test_la_page_propose_les_cinq_filtres_et_les_quatre_cartes(): void
    {
        $this->actingAs($this->gestionnaire())
            ->get(route('livraison.chargement.index', $this->campagne))
            ->assertOk()
            ->assertSee('stat-en-preparation', false)
            ->assertSee('stat-pretes', false)
            ->assertSee("appliquerFiltreChargement('en_preparation')", false)
            ->assertSee("appliquerFiltreChargement('pretes')", false);
    }

    // ── Anonymisation ────────────────────────────────────────────────────

    public function test_les_familles_sont_affichees_comme_famille_numero_et_jamais_par_leur_nom(): void
    {
        $livraison = $this->livraison('en_attente', [], ['nom' => 'Zebulonsky', 'prenom' => 'Anatolie']);
        $this->route('planifiee', [$livraison]);
        $sansTournee = $this->livraison('en_attente', ['statut' => 'non_assignee'], ['nom' => 'Quasimodov', 'prenom' => 'Esmeralda']);

        $this->actingAs($this->gestionnaire())
            ->get(route('livraison.chargement.index', $this->campagne))
            ->assertOk()
            ->assertSee('Famille #' . $livraison->famille->id)
            ->assertSee('Famille #' . $sansTournee->famille->id)
            ->assertDontSee('Zebulonsky')
            ->assertDontSee('Anatolie')
            ->assertDontSee('Quasimodov');

        $liste = $this->liste();
        $this->assertStringNotContainsString('Zebulonsky', json_encode($liste));
        $this->assertStringContainsString('Famille #' . $livraison->famille->id, $liste['routes'][0]['html']);
    }

    // ── Familles pas encore dans une tournée ─────────────────────────────

    public function test_les_familles_sans_tournee_sont_listees_avec_leur_statut_de_conditionnement(): void
    {
        $restante = $this->livraison('en_attente', ['statut' => 'non_assignee']);
        $enPrepa = $this->livraison('en_cours', ['statut' => 'non_assignee']);
        $prete = $this->livraison('prete', ['statut' => 'non_assignee']);
        // Hors périmètre : famille au QG, famille non confirmée, famille déjà en tournée.
        $this->livraison('prete', ['statut' => 'non_assignee', 'se_deplace' => true]);
        $this->livraison('prete', ['statut' => 'non_assignee', 'statut_contact' => 'a_contacter']);
        $this->route('planifiee', [$this->livraison('en_attente')]);

        $familles = collect($this->liste()['familles'])->pluck('etat', 'id')->all();

        $this->assertSame([
            $restante->id => 'restante',
            $enPrepa->id => 'en_preparation',
            $prete->id => 'prete',
        ], $familles);
    }

    public function test_les_familles_sans_tournee_suivent_le_filtre(): void
    {
        $this->livraison('en_attente', ['statut' => 'non_assignee']);
        $prete = $this->livraison('prete', ['statut' => 'non_assignee']);

        $this->assertSame([$prete->id], collect($this->liste('pretes')['familles'])->pluck('id')->all());
        $this->assertSame([], $this->liste('chargees')['familles']);
    }

    public function test_une_famille_qui_se_deplace_napparait_jamais_dans_une_tournee_ni_en_liste(): void
    {
        $this->livraison('prete', ['statut' => 'non_assignee', 'se_deplace' => true]);

        $liste = $this->liste();
        $this->assertSame([], $liste['familles']);
        $this->assertSame([], $liste['routes']);
    }

    // ── Confirmer / annuler le chargement ────────────────────────────────

    public function test_confirmer_exige_une_tournee_prete(): void
    {
        $gestionnaire = $this->gestionnaire();
        $restante = $this->route('planifiee', [$this->livraison('en_attente')]);

        $this->actingAs($gestionnaire)
            ->postJson(route('livraison.chargement.confirmer', $restante))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('planifiee', $restante->fresh()->statut);

        $prete = $this->route('chargement', [$this->livraison('prete')]);
        $this->postJson(route('livraison.chargement.confirmer', $prete))->assertOk()->assertJsonPath('success', true);
        $this->assertSame('charge', $prete->fresh()->statut);
    }

    public function test_annuler_un_chargement_remet_la_tournee_en_pret_et_ouvre_un_incident(): void
    {
        $route = $this->route('charge', [$this->livraison('prete')]);
        $gestionnaire = $this->gestionnaire();

        $reponse = $this->actingAs($gestionnaire)
            ->postJson(route('livraison.chargement.annuler-chargement', $route))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('chargement', $route->fresh()->statut);

        $incident = RouteIncident::findOrFail($reponse->json('id_incident'));
        $this->assertSame('chargement_annule', $incident->type);
        $this->assertSame('ouvert', $incident->statut);
        $this->assertSame($route->id, $incident->id_route);
        $this->assertSame($gestionnaire->id, $incident->signale_par);
        $this->assertSame('Chargement annulé', RouteIncident::LABELS_TYPE['chargement_annule']);
    }

    public function test_annuler_un_chargement_est_refuse_quand_le_chauffeur_a_demarre(): void
    {
        $route = $this->route('en_cours', [$this->livraison('prete')]);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.chargement.annuler-chargement', $route))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('en_cours', $route->fresh()->statut);
        $this->assertSame(0, RouteIncident::where('type', 'chargement_annule')->count());
    }

    public function test_annuler_un_chargement_est_refuse_quand_la_tournee_nest_pas_chargee(): void
    {
        $route = $this->route('chargement', [$this->livraison('prete')]);

        $this->actingAs($this->gestionnaire())
            ->postJson(route('livraison.chargement.annuler-chargement', $route))
            ->assertStatus(422);

        $this->assertSame(0, RouteIncident::where('type', 'chargement_annule')->count());
    }

    public function test_les_cartes_chargee_et_prete_portent_les_bons_boutons_avec_popup(): void
    {
        $this->route('chargement', [$this->livraison('prete')]);
        $this->route('charge', [$this->livraison('prete')]);

        $this->actingAs($this->gestionnaire())
            ->get(route('livraison.chargement.index', $this->campagne))
            ->assertOk()
            ->assertSee('Chargement confirmé')
            ->assertSee('Annuler le chargement')
            ->assertSee('amanaConfirm', false);
    }
}
