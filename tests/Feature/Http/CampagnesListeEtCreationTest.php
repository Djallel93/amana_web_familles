<?php
// tests/Feature/Http/CampagnesListeEtCreationTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\BenevoleDisponibilite;
use App\Models\Campagne;
use App\Models\Donation;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\PersonneDesactivee;
use App\Models\RouteLivraison;
use App\Support\Creneau;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * 03/10/2026 — livraison/campagnes : liste seule (lignes dépliables avec
 * statistiques à la demande) + page de création dédiée.
 */
class CampagnesListeEtCreationTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerRolesFamilles();
    }

    private function creerCampagne(array $surcharge = []): Campagne
    {
        return Campagne::create(array_merge([
            'type' => 'zakat_el_fitr',
            'statut' => 'preparation',
            'date_livraison' => '2026-11-10',
            'poids_moyen_kg' => 5,
        ], $surcharge));
    }

    private function creerLivraison(Campagne $campagne, array $surcharge = []): Livraison
    {
        return Livraison::create(array_merge([
            'id_famille' => Famille::factory()->create()->id,
            'id_campagne' => $campagne->id,
            'statut' => 'non_assignee',
            'statut_conditionnement' => 'en_attente',
            'nombre_personnes' => 3,
            'poids_kg' => 10.0,
            'statut_contact' => 'confirme',
        ], $surcharge));
    }

    // ── Liste ────────────────────────────────────────────────────────────

    public function test_la_liste_ne_porte_plus_le_formulaire_de_creation(): void
    {
        $this->creerCampagne();

        $this->actingAs($this->creerPersonne(['gestionnaire']))->get(route('livraison.campagnes.index'))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->component('Livraison/Campagnes')
                ->has('campagnes', 1)
                ->where('creerUrl', route('livraison.campagnes.creer'))
                ->has('apercuUrlTemplate')
                ->has('resumeSuppressionUrlTemplate')
                ->has('destroyUrlTemplate')
                // Props du formulaire déménagées sur la page de création :
                ->missing('storeUrl')
                ->missing('googlePlacesKey')
                ->missing('hqGlobalDefaut')
                ->missing('livraisonsMaxParTourneeDefaut'));
    }

    // ── Page de création ─────────────────────────────────────────────────

    public function test_la_page_de_creation_porte_les_props_du_formulaire(): void
    {
        $this->actingAs($this->creerPersonne(['gestionnaire']))->get(route('livraison.campagnes.creer'))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->component('Livraison/CampagneCreer')
                ->where('storeUrl', route('livraison.campagnes.store'))
                ->where('retourUrl', route('livraison.campagnes.index'))
                ->has('livraisonsMaxParTourneeDefaut')
                ->has('googlePlacesKey')
                ->has('hqGlobalDefaut'));
    }

    public function test_creer_nest_pas_interprete_comme_un_id_de_campagne(): void
    {
        // /livraison/campagnes/creer est déclarée AVANT /campagnes/{campagne}.
        $this->assertSame(url('/livraison/campagnes/creer'), route('livraison.campagnes.creer'));

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->get('/livraison/campagnes/creer')
            ->assertOk()
            ->assertInertia(fn($page) => $page->component('Livraison/CampagneCreer'));
    }

    public function test_un_membre_ne_voit_ni_la_liste_ni_la_creation_ni_les_stats(): void
    {
        $membre = $this->creerPersonne(['membre']);
        $campagne = $this->creerCampagne();

        // EnsureRole redirige vers l'accueil avec un message d'erreur (jamais de 403).
        $accueil = route(config('amana-shared.home_route'));
        $this->actingAs($membre)->get(route('livraison.campagnes.creer'))->assertRedirect($accueil);
        $this->actingAs($membre)->getJson(route('livraison.campagnes.apercu', $campagne))->assertRedirect($accueil);
    }

    public function test_store_exige_un_type_et_au_moins_une_date(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);

        $this->actingAs($gestionnaire)
            ->postJson(route('livraison.campagnes.store'), ['poids_moyen_kg' => 5, 'journees' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'journees']);

        $this->assertSame(0, Campagne::count());
    }

    public function test_store_cree_la_campagne_et_ses_journees(): void
    {
        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->postJson(route('livraison.campagnes.store'), [
                'type' => 'collecte_alimentaire',
                'poids_moyen_kg' => 5,
                'journees' => [['date' => '2026-11-10', 'label' => null], ['date' => '2026-11-11', 'label' => 'Jour 2']],
            ])
            ->assertCreated();

        $campagne = Campagne::firstOrFail();
        $this->assertSame('preparation', $campagne->statut);
        $this->assertSame(2, $campagne->journees()->count());
    }

    // ── Statistiques d'une ligne dépliée ─────────────────────────────────

    public function test_apercu_agrege_les_statistiques_choisies(): void
    {
        $campagne = $this->creerCampagne();
        $journee = $campagne->ajouterJournee('2026-11-10');

        // Familles : 2 confirmées (dont 1 se déplace au QG, 1 prête, 1 livrée), 1 rejetée, 1 à contacter.
        $this->creerLivraison($campagne, ['statut' => 'livree', 'statut_conditionnement' => 'prete', 'poids_kg' => 10]);
        $this->creerLivraison($campagne, ['statut' => 'assignee', 'se_deplace' => true, 'poids_kg' => 20]);
        $this->creerLivraison($campagne, ['statut_contact' => 'rejetee', 'poids_kg' => 99]);
        $this->creerLivraison($campagne, ['statut_contact' => 'a_contacter', 'poids_kg' => 5]);

        // Tournées : 2 planifiées, 1 terminée.
        foreach (['planifiee', 'planifiee', 'terminee'] as $statut) {
            RouteLivraison::create([
                'id_campagne' => $campagne->id, 'id_benevole' => $this->creerPersonne(['benevole'])->id,
                'id_vehicule_type' => 1, 'creneau' => Creneau::MATIN_1, 'statut' => $statut,
            ]);
        }

        // Bénévoles : 2 confirmés (dont 1 désactivé → exclu), 1 non confirmé.
        $b1 = $this->creerPersonne(['benevole']);
        $b2 = $this->creerPersonne(['benevole']);
        $b3 = $this->creerPersonne(['benevole']);
        foreach ([[$b1, 'confirme'], [$b2, 'confirme'], [$b3, 'non_confirme']] as [$p, $statut]) {
            BenevoleDisponibilite::create(['id_personne' => $p->id, 'id_campagne_journee' => $journee->id, 'statut' => $statut]);
        }
        PersonneDesactivee::create(['id_personne' => $b2->id]);

        // Poids collecté : 2 donations.
        foreach ([12.5, 7.5] as $poids) {
            Donation::create(['id_campagne' => $campagne->id, 'id_campagne_journee' => $journee->id, 'poids_kg' => $poids, 'logge_par' => $b1->id]);
        }

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->getJson(route('livraison.campagnes.apercu', $campagne))
            ->assertOk()
            ->assertJsonPath('familles', ['total' => 4, 'confirmees' => 2, 'se_deplacent' => 1])
            ->assertJsonPath('contacts.confirme', 2)
            ->assertJsonPath('contacts.rejetee', 1)
            ->assertJsonPath('contacts.a_contacter', 1)
            ->assertJsonPath('contacts.archive', 0)
            ->assertJsonPath('benevoles', ['disponibles' => 1, 'en_attente' => 1])
            // Estimé : tout sauf rejetées/archivées = 10 + 20 + 5 ; collecté = 12,5 + 7,5.
            ->assertJsonPath('poids.estime_kg', 35)
            ->assertJsonPath('poids.collecte_kg', 20)
            ->assertJsonPath('tournees', ['total' => 3, 'par_statut' => ['planifiee' => 2, 'terminee' => 1]])
            ->assertJsonPath('packaging', ['pretes' => 1, 'confirmees' => 2, 'taux' => 50])
            ->assertJsonPath('livraisons', ['livrees' => 1, 'ignorees' => 0, 'en_attente' => 1]);
    }

    public function test_apercu_dune_campagne_vide_ne_divise_pas_par_zero(): void
    {
        $campagne = $this->creerCampagne();

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->getJson(route('livraison.campagnes.apercu', $campagne))
            ->assertOk()
            ->assertJsonPath('packaging.taux', null)
            ->assertJsonPath('familles.total', 0)
            ->assertJsonPath('tournees.total', 0)
            ->assertJsonPath('poids.collecte_kg', 0);
    }
}
