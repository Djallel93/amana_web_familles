<?php
// tests/Feature/Http/ChargementEditionTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Amana\Shared\Models\Personne;
use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteLivraison;
use App\Support\Creneau;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsDisponibiliteFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Composition des tournées depuis l'écran chargement (11/10/2026) : ajouter / retirer une
 * famille, changer de chauffeur, scinder, annuler — ex-Suivi livraison. Autorisation
 * (can:gerer,route), verrou « chargée = admin seulement », tournées vides.
 */
class ChargementEditionTest extends TestCase
{
    use BuildsDisponibiliteFixtures;
    use SeedsCommunFixtures;

    private Campagne $campagne;

    private CampagneJournee $journee;

    private int $voiture;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
        $this->voiture = $this->creerVehicules()['voiture'];
        [$this->campagne, $this->journee] = $this->creerCampagneAvecJournee();
    }

    private function livraison(array $surcharge = []): Livraison
    {
        return Livraison::create(array_merge([
            'id_famille' => Famille::factory()->create()->id,
            'id_campagne' => $this->campagne->id,
            'id_campagne_journee' => $this->journee->id,
            'statut' => 'assignee',
            'statut_conditionnement' => 'prete',
            'nombre_personnes' => 2,
            'poids_kg' => 10.0,
            'statut_contact' => 'confirme',
        ], $surcharge));
    }

    /** @param list<Livraison> $livraisons */
    private function route(string $statut, array $livraisons = []): RouteLivraison
    {
        $route = RouteLivraison::create([
            'id_campagne' => $this->campagne->id,
            'id_campagne_journee' => $this->journee->id,
            'id_benevole' => $this->creerPersonne(['benevole'])->id,
            'id_vehicule_type' => $this->voiture,
            'creneau' => Creneau::MATIN_1,
            'statut' => $statut,
        ]);

        foreach ($livraisons as $ordre => $livraison) {
            EtapeRoute::create(['id_route' => $route->id, 'id_livraison' => $livraison->id, 'ordre' => $ordre + 1, 'statut' => 'en_attente']);
        }

        return $route;
    }

    private function chargeur(): Personne
    {
        $chargeur = $this->creerPersonne(['membre']);
        $this->campagne->equipeMembres()->create(['id_personne' => $chargeur->id, 'role' => 'equipe_chargement']);

        return $chargeur;
    }

    // ── Autorisation ─────────────────────────────────────────────────────

    public function test_un_benevole_sans_role_d_equipe_ne_peut_rien_modifier(): void
    {
        $route = $this->route('chargement', [$this->livraison()]);
        $etape = $route->etapes()->first();

        $this->actingAs($this->creerPersonne(['benevole']));

        $this->postJson(route('livraison.chargement.routes.ajouter-famille', $route), ['id_livraison' => $this->livraison()->id])->assertForbidden();
        $this->deleteJson(route('livraison.chargement.routes.retirer-famille', [$route, $etape]))->assertForbidden();
        $this->postJson(route('livraison.chargement.routes.diviser', $route))->assertForbidden();
        $this->deleteJson(route('livraison.chargement.routes.supprimer', $route))->assertForbidden();
    }

    public function test_l_equipe_chargement_atteint_les_endpoints_d_edition(): void
    {
        $route = $this->route('chargement', [$this->livraison()]);

        $this->actingAs($this->chargeur())
            ->postJson(route('livraison.chargement.routes.ajouter-famille', $route), [])
            ->assertStatus(422); // autorisé, mais id_livraison manquant
    }

    public function test_changer_de_chauffeur_est_reserve_aux_admins_et_gestionnaires(): void
    {
        $route = $this->route('chargement', [$this->livraison()]);
        $nouveau = $this->creerBenevole($this->voiture);
        $corps = ['id_benevole' => $nouveau->id, 'id_vehicule_type' => $this->voiture];

        $this->actingAs($this->chargeur())
            ->postJson(route('livraison.chargement.routes.reassigner', $route), $corps)
            ->assertForbidden();

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->postJson(route('livraison.chargement.routes.reassigner', $route), $corps)
            ->assertOk();

        $this->assertSame($nouveau->id, $route->fresh()->id_benevole);
    }

    public function test_la_page_n_offre_le_changement_de_chauffeur_qu_aux_admins_et_gestionnaires(): void
    {
        $this->route('chargement', [$this->livraison()]);

        $this->actingAs($this->chargeur())
            ->get(route('livraison.chargement.index', $this->campagne))
            ->assertInertia(fn($page) => $page->where('peutReassigner', false));

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->get(route('livraison.chargement.index', $this->campagne))
            ->assertInertia(fn($page) => $page->where('peutReassigner', true));
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_ajouter_une_famille_d_une_autre_campagne_est_refuse(): void
    {
        $route = $this->route('planifiee', [$this->livraison()]);
        [$autre, $journeeAutre] = $this->creerCampagneAvecJournee();
        $etrangere = Livraison::create([
            'id_famille' => Famille::factory()->create()->id,
            'id_campagne' => $autre->id,
            'id_campagne_journee' => $journeeAutre->id,
            'statut' => 'non_assignee',
            'statut_conditionnement' => 'en_attente',
            'nombre_personnes' => 2,
            'poids_kg' => 10.0,
            'statut_contact' => 'confirme',
        ]);

        $this->actingAs($this->chargeur())
            ->postJson(route('livraison.chargement.routes.ajouter-famille', $route), ['id_livraison' => $etrangere->id])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_retirer_un_arret_d_une_autre_tournee_est_refuse(): void
    {
        $route = $this->route('planifiee', [$this->livraison()]);
        $autre = $this->route('planifiee', [$this->livraison()]);

        $this->actingAs($this->chargeur())
            ->deleteJson(route('livraison.chargement.routes.retirer-famille', [$route, $autre->etapes()->first()]))
            ->assertStatus(422);

        $this->assertSame(1, $autre->etapes()->count());
    }

    // ── Verrou « chargée » ───────────────────────────────────────────────

    public function test_une_tournee_chargee_n_est_modifiable_que_par_un_admin(): void
    {
        $route = $this->route('charge', [$this->livraison(), $this->livraison()]);
        $etape = $route->etapes()->first();
        $libre = $this->livraison(['statut' => 'non_assignee']);

        foreach ([$this->chargeur(), $this->creerPersonne(['gestionnaire'])] as $acteur) {
            $this->actingAs($acteur);
            $this->postJson(route('livraison.chargement.routes.ajouter-famille', $route), ['id_livraison' => $libre->id])->assertStatus(422);
            $this->deleteJson(route('livraison.chargement.routes.retirer-famille', [$route, $etape]))->assertStatus(422);
            $this->postJson(route('livraison.chargement.routes.diviser', $route))->assertStatus(422);
            $this->deleteJson(route('livraison.chargement.routes.supprimer', $route))->assertStatus(422);
        }

        $this->assertSame(2, $route->etapes()->count());
    }

    public function test_la_page_signale_les_tournees_verrouillees_sauf_pour_un_admin(): void
    {
        $this->route('charge', [$this->livraison()]);

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->get(route('livraison.chargement.index', $this->campagne))
            ->assertInertia(fn($page) => $page->where('routes.0.verrouillee', true));

        $this->actingAs($this->creerPersonne(['admin']))
            ->get(route('livraison.chargement.index', $this->campagne))
            ->assertInertia(fn($page) => $page->where('routes.0.verrouillee', false));
    }

    // ── Tournées vides ───────────────────────────────────────────────────

    public function test_une_tournee_vide_pas_encore_chargee_peut_etre_annulee(): void
    {
        $route = $this->route('chargement');

        $this->actingAs($this->chargeur())
            ->deleteJson(route('livraison.chargement.routes.supprimer', $route))
            ->assertOk();

        $this->assertSame('annulee', $route->fresh()->statut);
    }

    public function test_une_tournee_non_vide_en_chargement_ne_peut_pas_etre_supprimee(): void
    {
        $route = $this->route('chargement', [$this->livraison()]);

        $this->actingAs($this->chargeur())
            ->deleteJson(route('livraison.chargement.routes.supprimer', $route))
            ->assertStatus(422);

        $this->assertSame('chargement', $route->fresh()->statut);
    }

    public function test_une_tournee_planifiee_supprimee_libere_ses_familles(): void
    {
        $livraison = $this->livraison();
        $route = $this->route('planifiee', [$livraison]);

        $this->actingAs($this->chargeur())
            ->deleteJson(route('livraison.chargement.routes.supprimer', $route))
            ->assertOk();

        $this->assertSame('non_assignee', $livraison->fresh()->statut);
        $this->assertSame('annulee', $route->fresh()->statut);
    }

    // ── Retrait d'une famille ────────────────────────────────────────────

    public function test_retirer_la_derniere_famille_laisse_une_tournee_vide_visible_au_chargement(): void
    {
        $this->campagne->update(['hq_latitude' => 48.85, 'hq_longitude' => 2.35]);
        $livraison = $this->livraison();
        $route = $this->route('planifiee', [$livraison]);

        $this->actingAs($this->chargeur())
            ->deleteJson(route('livraison.chargement.routes.retirer-famille', [$route, $route->etapes()->first()]))
            ->assertOk();

        $this->assertSame('non_assignee', $livraison->fresh()->statut);

        $this->getJson(route('livraison.chargement.liste', $this->campagne))
            ->assertOk()
            ->assertJsonPath('stats.vides', 1)
            ->assertJsonPath('routes.0.id', $route->id)
            ->assertJsonPath('routes.0.vide', true)
            ->assertJsonPath('familles.0.id', $livraison->id);
    }
}
