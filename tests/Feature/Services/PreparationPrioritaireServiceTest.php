<?php
// tests/Feature/Services/PreparationPrioritaireServiceTest.php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteLivraison;
use App\Services\PreparationPrioritaireService;
use App\Support\Creneau;
use Tests\Concerns\BuildsDisponibiliteFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * « Préparation prioritaire » (11/10/2026) : service et endpoints chargement (tournée entière)
 * et retrait QG (une famille).
 */
class PreparationPrioritaireServiceTest extends TestCase
{
    use BuildsDisponibiliteFixtures;
    use SeedsCommunFixtures;

    private Campagne $campagne;

    private CampagneJournee $journee;

    private PreparationPrioritaireService $service;

    private int $vehiculeVoiture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerRolesFamilles();
        $vehicules = $this->creerVehicules();
        [$this->campagne, $this->journee] = $this->creerCampagneAvecJournee();
        $this->vehiculeVoiture = $vehicules['voiture'];
        $this->service = app(PreparationPrioritaireService::class);
    }

    private function livraison(array $surcharge = []): Livraison
    {
        return Livraison::create(array_merge([
            'id_famille' => Famille::factory()->create()->id,
            'id_campagne' => $this->campagne->id,
            'id_campagne_journee' => $this->journee->id,
            'statut' => 'assignee',
            'statut_conditionnement' => 'en_attente',
            'nombre_personnes' => 2,
            'poids_kg' => 10.0,
            'statut_contact' => 'confirme',
        ], $surcharge));
    }

    /** @param list<Livraison> $livraisons */
    private function route(string $statut, array $livraisons): RouteLivraison
    {
        $route = RouteLivraison::create([
            'id_campagne' => $this->campagne->id,
            'id_campagne_journee' => $this->journee->id,
            'id_benevole' => $this->creerPersonne(['benevole'])->id,
            'id_vehicule_type' => $this->vehiculeVoiture,
            'creneau' => Creneau::MATIN_1,
            'statut' => $statut,
        ]);

        foreach ($livraisons as $ordre => $livraison) {
            EtapeRoute::create(['id_route' => $route->id, 'id_livraison' => $livraison->id, 'ordre' => $ordre + 1, 'statut' => 'en_attente']);
        }

        return $route;
    }

    public function test_prioriser_une_tournee_marque_les_familles_pas_encore_pretes(): void
    {
        $enAttente = $this->livraison();
        $enCours = $this->livraison(['statut_conditionnement' => 'en_cours']);
        $prete = $this->livraison(['statut_conditionnement' => 'prete']);
        $route = $this->route('planifiee', [$enAttente, $enCours, $prete]);

        $this->assertSame(2, $this->service->prioriserTournee($route));

        $this->assertTrue($enAttente->fresh()->preparation_prioritaire);
        $this->assertNotNull($enAttente->fresh()->prioritaire_at);
        $this->assertTrue($enCours->fresh()->preparation_prioritaire);
        $this->assertFalse($prete->fresh()->preparation_prioritaire);
    }

    public function test_une_tournee_chargee_ne_peut_plus_etre_priorisee(): void
    {
        $route = $this->route('charge', [$this->livraison()]);

        $this->expectException(\RuntimeException::class);
        $this->service->prioriserTournee($route);
    }

    public function test_une_tournee_deja_entierement_prete_ou_prioritaire_est_refusee(): void
    {
        $route = $this->route('chargement', [
            $this->livraison(['statut_conditionnement' => 'prete']),
            $this->livraison(['preparation_prioritaire' => true, 'prioritaire_at' => now()]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->prioriserTournee($route);
    }

    public function test_prioriser_une_famille_retrait_qg(): void
    {
        $livraison = $this->livraison(['se_deplace' => true]);

        $this->service->prioriserLivraison($livraison);

        $this->assertTrue($livraison->fresh()->preparation_prioritaire);
        $this->assertNotNull($livraison->fresh()->prioritaire_at);
    }

    public function test_prioriser_une_famille_prete_deja_prioritaire_ou_servie_est_refuse(): void
    {
        foreach ([
            ['statut_conditionnement' => 'prete'],
            ['preparation_prioritaire' => true],
            ['statut' => 'livree', 'statut_retrait_hq' => 'delivre'],
        ] as $surcharge) {
            try {
                $this->service->prioriserLivraison($this->livraison($surcharge + ['se_deplace' => true]));
                $this->fail('Refus attendu pour ' . json_encode($surcharge));
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_endpoint_chargement_reserve_a_l_equipe_et_aux_gestionnaires(): void
    {
        $route = $this->route('planifiee', [$this->livraison()]);

        $this->actingAs($this->creerPersonne(['benevole']))
            ->postJson(route('livraison.chargement.preparation-prioritaire', $route))
            ->assertForbidden();

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->postJson(route('livraison.chargement.preparation-prioritaire', $route))
            ->assertOk()
            ->assertJson(['success' => true, 'familles' => 1]);
    }

    public function test_endpoint_chargement_renvoie_le_motif_du_refus(): void
    {
        $route = $this->route('charge', [$this->livraison()]);

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->postJson(route('livraison.chargement.preparation-prioritaire', $route))
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_endpoint_retrait_qg(): void
    {
        $livraison = $this->livraison(['se_deplace' => true]);

        $this->actingAs($this->creerPersonne(['benevole']))
            ->postJson(route('livraison.retrait-hq.preparation-prioritaire', $livraison))
            ->assertForbidden();

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->postJson(route('livraison.retrait-hq.preparation-prioritaire', $livraison))
            ->assertOk();

        $this->assertTrue($livraison->fresh()->preparation_prioritaire);

        // Une seconde demande est refusée : la priorité ne se retire ni ne se répète.
        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->postJson(route('livraison.retrait-hq.preparation-prioritaire', $livraison))
            ->assertStatus(422);
    }
}
