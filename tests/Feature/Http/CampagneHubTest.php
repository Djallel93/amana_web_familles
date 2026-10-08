<?php
// tests/Feature/Http/CampagneHubTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Campagne;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Support\Creneau;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * 03/10/2026 — page campagne devenue un hub : cartes, page Paramètres,
 * sélection des familles dédiée, page Incidents (statut 'ignore'), clôture
 * (terminer / rouvrir / tout résoudre de force) et génération des routes
 * déplacée sur Suivi livraison.
 */
class CampagneHubTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
    }

    private function gestionnaire()
    {
        return $this->creerPersonne(['gestionnaire']);
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

    private function creerRoute(Campagne $campagne, string $statut = 'planifiee'): RouteLivraison
    {
        return RouteLivraison::create([
            'id_campagne' => $campagne->id,
            'id_benevole' => $this->creerPersonne(['benevole'])->id,
            'id_vehicule_type' => 1,
            'creneau' => Creneau::MATIN_1,
            'statut' => $statut,
        ]);
    }

    private function creerIncident(RouteLivraison $route, string $type = 'capacite', ?string $statut = 'ouvert', array $surcharge = []): RouteIncident
    {
        return RouteIncident::withoutEvents(fn() => RouteIncident::create(array_merge([
            'id_route' => $route->id,
            'type' => $type,
            'signale_par' => $route->id_benevole,
            'statut' => $statut,
        ], $surcharge)));
    }

    // ── Hub ──────────────────────────────────────────────────────────────

    public function test_le_hub_porte_les_urls_des_cartes_et_plus_les_props_de_edition(): void
    {
        $campagne = $this->creerCampagne();

        $this->actingAs($this->gestionnaire())->get(route('livraison.campagnes.show', $campagne))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->component('Livraison/CampagneDetail')
                ->where('urls.parametres', route('livraison.campagnes.parametres', $campagne))
                ->where('urls.familles', route('livraison.familles-eligibles.index', $campagne))
                // La carte Incidents a laissé place à une section repliable : plus d'URL de page dédiée.
                ->missing('urls.incidents')
                ->where('incidentsUrls.liste', route('livraison.campagnes.incidents-liste', $campagne))
                ->where('incidentsUrls.resoudre', route('livraison.incidents.resoudre', ['incident' => '__ID__']))
                ->where('incidentsUrls.ignorer', route('livraison.incidents.ignorer', ['incident' => '__ID__']))
                // Démarrage + assistant « Génération des routes » (06/10/2026).
                ->where('demarrerUrl', route('livraison.campagnes.demarrer', $campagne))
                ->where('generationUrls.chauffeurs', route('livraison.campagnes.chauffeurs-disponibles', $campagne))
                ->where('generationUrls.apercu', route('livraison.campagnes.apercu-generation', $campagne))
                ->where('generationUrls.generer', route('livraison.campagnes.generer-routes', $campagne))
                ->where('generationUrls.personnalisee', route('livraison.routes.personnalisee', $campagne))
                ->has('quartiers')
                ->where('urls.statistiques', route('livraison.statistiques.index', $campagne))
                ->where('terminerUrl', route('livraison.campagnes.terminer', $campagne))
                ->where('rouvrirUrl', route('livraison.campagnes.rouvrir', $campagne))
                // Plus portés par le hub : sélection des familles, HQ/journées/équipes, génération des routes.
                ->missing('eligiblesUrl')
                ->missing('genererRoutesUrl')
                ->missing('updateUrl'));
    }

    public function test_avancement_compte_les_incidents_ouverts_de_la_campagne_seulement(): void
    {
        $campagne = $this->creerCampagne();
        $autre = $this->creerCampagne(['date_livraison' => '2026-12-01']);
        $route = $this->creerRoute($campagne);

        $this->creerIncident($route, 'capacite', 'ouvert');
        $this->creerIncident($route, 'livraison_ignoree', 'ouvert');
        $this->creerIncident($route, 'capacite', 'resolu');
        $this->creerIncident($route, 'capacite', 'ignore');
        $this->creerIncident($route, 'chargement_termine', null);           // jalon : jamais compté
        $this->creerIncident($this->creerRoute($autre), 'capacite', 'ouvert'); // autre campagne

        $this->actingAs($this->gestionnaire())->getJson(route('livraison.campagnes.avancement', $campagne))
            ->assertOk()
            ->assertJsonPath('compteurs.incidents_ouverts', 2);
    }

    // ── Sélection des familles (page dédiée) ─────────────────────────────

    public function test_la_page_familles_avec_campagne_porte_les_urls_de_selection(): void
    {
        $campagne = $this->creerCampagne();
        $campagne->ajouterJournee('2026-11-10');

        $this->actingAs($this->gestionnaire())->get(route('livraison.familles-eligibles.index', $campagne))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->component('Livraison/CampagneFamilles')
                ->where('campagne.id', $campagne->id)
                ->has('campagne.journees', 1)
                ->where('urlsCampagne.eligibles', route('livraison.campagnes.eligibles', $campagne))
                ->where('urlsCampagne.genererLivraisons', route('livraison.campagnes.generer-livraisons', $campagne))
                ->where('retourUrl', route('livraison.campagnes.show', $campagne)));
    }

    public function test_la_page_familles_sans_campagne_propose_den_choisir_une(): void
    {
        $this->creerCampagne();

        // Entrée de la barre latérale : pas de campagne dans l'URL.
        $this->actingAs($this->gestionnaire())->get(route('livraison.familles-eligibles.index'))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->component('Livraison/CampagneFamilles')
                ->where('campagne', null)
                ->where('urlsCampagne', null)
                ->has('campagnes', 1));
    }

    // ── Paramètres ───────────────────────────────────────────────────────

    public function test_parametres_expose_hq_journees_et_equipes(): void
    {
        $campagne = $this->creerCampagne();
        $campagne->ajouterJournee('2026-11-10');
        $membre = $this->creerPersonne(['benevole']);
        $campagne->equipeMembres()->create(['id_personne' => $membre->id, 'role' => 'equipe_pesee']);

        $this->actingAs($this->gestionnaire())->get(route('livraison.campagnes.parametres', $campagne))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->component('Livraison/CampagneParametres')
                ->where('onglet', 'hq')
                ->where('updateUrl', route('livraison.campagnes.update', $campagne))
                ->where('ajouterJourneeUrl', route('livraison.campagnes.journees.store', $campagne))
                ->has('campagne.journees', 1)
                ->has('lignesEquipe', 1)
                ->where('lignesEquipe.0.id_personne', $membre->id));
    }

    public function test_parametres_accepte_un_onglet_valide_et_ignore_les_autres(): void
    {
        $campagne = $this->creerCampagne();
        $gestionnaire = $this->gestionnaire();

        $this->actingAs($gestionnaire)->get(route('livraison.campagnes.parametres', [$campagne, 'onglet' => 'equipes']))
            ->assertInertia(fn($page) => $page->where('onglet', 'equipes'));
        $this->get(route('livraison.campagnes.parametres', [$campagne, 'onglet' => 'nimporte-quoi']))
            ->assertInertia(fn($page) => $page->where('onglet', 'hq'));
    }

    public function test_lancienne_page_equipes_redirige_vers_longlet_equipes(): void
    {
        $campagne = $this->creerCampagne();

        $this->actingAs($this->gestionnaire())->get(route('livraison.campagnes.equipes.index', $campagne))
            ->assertRedirect(route('livraison.campagnes.parametres', ['campagne' => $campagne, 'onglet' => 'equipes']));
    }

    // ── Section Incidents (liste JSON, 06/10/2026) ───────────────────────

    public function test_la_liste_des_incidents_contient_tout_sauf_le_jalon_et_les_autres_campagnes(): void
    {
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne);
        $ouvert = $this->creerIncident($route, 'capacite', 'ouvert');
        $ignore = $this->creerIncident($route, 'livraison_ignoree', 'ignore');
        $resolu = $this->creerIncident($route, 'packaging_annule', 'resolu');
        $this->creerIncident($route, 'chargement_termine', null);
        $this->creerIncident($this->creerRoute($this->creerCampagne(['date_livraison' => '2026-12-01'])), 'capacite', 'ouvert');

        $reponse = $this->actingAs($this->gestionnaire())->getJson(route('livraison.campagnes.incidents-liste', $campagne))
            ->assertOk()
            ->assertJsonCount(3);

        $this->assertSame(
            collect([$ouvert->id, $ignore->id, $resolu->id])->sort()->values()->all(),
            collect($reponse->json())->pluck('id')->sort()->values()->all(),
        );
    }

    public function test_la_page_incidents_dediee_nexiste_plus(): void
    {
        $this->assertFalse(Route::has('livraison.campagnes.gestion-incidents'));
    }

    public function test_une_ligne_dincident_porte_libelles_description_et_guide_vide(): void
    {
        $campagne = $this->creerCampagne();
        $incident = $this->creerIncident($this->creerRoute($campagne), 'packaging_annule', 'ouvert');

        $this->actingAs($this->gestionnaire())->getJson(route('livraison.campagnes.incidents-liste', $campagne))
            ->assertJsonPath('0.id', $incident->id)
            ->assertJsonPath('0.type_label', 'Packaging annulé')
            ->assertJsonPath('0.statut', 'ouvert')
            ->assertJsonPath('0.guide', null)
            ->assertJson(fn($json) => $json->has('0.description')->etc());
    }

    public function test_ignorer_ferme_lincident_sans_effet_de_bord(): void
    {
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne);
        $incident = $this->creerIncident($route, 'benevole_absent', 'ouvert');

        $this->actingAs($this->gestionnaire())->postJson(route('livraison.incidents.ignorer', $incident))
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame('ignore', $incident->fresh()->statut);
        // benevole_absent ignoré : aucun re-clustering, donc aucune nouvelle tournée.
        $this->assertSame(1, $campagne->routes()->count());
    }

    public function test_resoudre_marque_lincident_resolu(): void
    {
        $incident = $this->creerIncident($this->creerRoute($this->creerCampagne()), 'capacite', 'ouvert');

        $this->actingAs($this->gestionnaire())->postJson(route('livraison.incidents.resoudre', $incident))
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame('resolu', $incident->fresh()->statut);
    }

    // ── Clôture ──────────────────────────────────────────────────────────

    public function test_cloture_liste_les_tournees_non_terminees_et_les_avertissements(): void
    {
        $campagne = $this->creerCampagne();
        $enCours = $this->creerRoute($campagne, 'en_cours');
        $this->creerRoute($campagne, 'terminee');
        $this->creerRoute($campagne, 'annulee');
        $annule = $this->creerRoute($campagne, 'packaging_annule');
        $this->creerIncident($enCours, 'capacite', 'ouvert');

        $reponse = $this->actingAs($this->gestionnaire())->getJson(route('livraison.campagnes.cloture', $campagne))->assertOk();

        $this->assertSame([$enCours->id, $annule->id], collect($reponse->json('routes_non_terminees'))->pluck('id')->all());
        $reponse->assertJsonPath('incidents_ouverts', 1)->assertJsonPath('statut', 'preparation');
    }

    public function test_terminer_est_refuse_tant_quune_tournee_nest_pas_terminee(): void
    {
        $campagne = $this->creerCampagne();
        $this->creerRoute($campagne, 'en_cours');

        $this->actingAs($this->gestionnaire())->postJson(route('livraison.campagnes.terminer', $campagne))
            ->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame('preparation', $campagne->fresh()->statut);
    }

    public function test_terminer_passe_la_campagne_a_terminee_malgre_des_incidents_ouverts(): void
    {
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, 'terminee');
        $this->creerRoute($campagne, 'annulee');
        $incident = $this->creerIncident($route, 'capacite', 'ouvert');

        $this->actingAs($this->gestionnaire())->postJson(route('livraison.campagnes.terminer', $campagne))
            ->assertOk()->assertJsonPath('campagne.statut', 'terminee');

        $this->assertSame('terminee', $campagne->fresh()->statut);
        // Les incidents ouverts n'empêchent pas la clôture et ne sont pas touchés tout seuls.
        $this->assertSame('ouvert', $incident->fresh()->statut);
    }

    public function test_terminer_une_campagne_sans_tournee_est_possible(): void
    {
        $campagne = $this->creerCampagne();

        $this->actingAs($this->gestionnaire())->postJson(route('livraison.campagnes.terminer', $campagne))->assertOk();

        $this->assertSame('terminee', $campagne->fresh()->statut);
    }

    public function test_terminer_deux_fois_est_refuse(): void
    {
        $campagne = $this->creerCampagne(['statut' => 'terminee']);

        $this->actingAs($this->gestionnaire())->postJson(route('livraison.campagnes.terminer', $campagne))
            ->assertStatus(422);
    }

    public function test_rouvrir_remet_en_preparation_et_nest_possible_que_si_terminee(): void
    {
        $gestionnaire = $this->gestionnaire();
        $terminee = $this->creerCampagne(['statut' => 'terminee']);
        $enCours = $this->creerCampagne(['statut' => 'en_cours', 'date_livraison' => '2026-12-01']);

        $this->actingAs($gestionnaire)->postJson(route('livraison.campagnes.rouvrir', $terminee))
            ->assertOk()->assertJsonPath('campagne.statut', 'preparation');
        $this->assertSame('preparation', $terminee->fresh()->statut);

        $this->postJson(route('livraison.campagnes.rouvrir', $enCours))->assertStatus(422);
        $this->assertSame('en_cours', $enCours->fresh()->statut);
    }

    public function test_forcer_la_resolution_ferme_tous_les_incidents_ouverts_sans_reclustering(): void
    {
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, 'terminee');
        $absent = $this->creerIncident($route, 'benevole_absent', 'ouvert');
        $capacite = $this->creerIncident($route, 'capacite', 'ouvert', ['notes' => 'Trop de colis']);
        $deja = $this->creerIncident($route, 'capacite', 'ignore');
        $jalon = $this->creerIncident($route, 'chargement_termine', null);
        $autre = $this->creerIncident($this->creerRoute($this->creerCampagne(['date_livraison' => '2026-12-01'])), 'capacite', 'ouvert');

        $this->actingAs($this->gestionnaire())->postJson(route('livraison.campagnes.incidents.forcer-resolution', $campagne))
            ->assertOk()->assertJsonPath('resolus', 2);

        $this->assertSame('resolu', $absent->fresh()->statut);
        $this->assertSame('resolu', $capacite->fresh()->statut);
        $this->assertStringContainsString('Trop de colis', $capacite->fresh()->notes);
        $this->assertStringContainsString('Résolu de force', $capacite->fresh()->notes);
        // Pas de re-clustering pour benevole_absent : aucune nouvelle tournée.
        $this->assertSame(1, $campagne->routes()->count());
        // Intacts : déjà ignoré, jalon sans statut, autre campagne.
        $this->assertSame('ignore', $deja->fresh()->statut);
        $this->assertNull($jalon->fresh()->statut);
        $this->assertSame('ouvert', $autre->fresh()->statut);
    }

    public function test_un_membre_ne_peut_ni_terminer_ni_voir_les_incidents(): void
    {
        $membre = $this->creerPersonne(['membre']);
        $campagne = $this->creerCampagne();

        // EnsureRole redirige vers l'accueil avec un message d'erreur (jamais de 403).
        $accueil = route(config('amana-shared.home_route'));
        $this->actingAs($membre)->postJson(route('livraison.campagnes.terminer', $campagne))->assertRedirect($accueil);
        $this->actingAs($membre)->get(route('livraison.campagnes.incidents-liste', $campagne))->assertRedirect($accueil)->assertSessionHas('error');
        $this->assertSame('preparation', $campagne->fresh()->statut);
    }

    // ── Suivi livraison : plus de génération des routes (06/10/2026) ─────

    public function test_suivi_livraison_ne_porte_plus_la_generation_des_routes(): void
    {
        $campagne = $this->creerCampagne();
        $campagne->ajouterJournee('2026-11-10');

        $this->actingAs($this->gestionnaire())->get(route('livraison.suivi-livraison.index', $campagne))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->component('Livraison/SuiviLivraison')
                // La génération et la tournée personnalisée vivent dans l'assistant du hub.
                ->missing('urls.genererRoutes')
                ->missing('urls.routesPersonnalisees')
                ->missing('urls.nonCouvertesTableau')
                ->missing('quartiers')
                // Ces deux URLs ne servaient qu'au contrôle « familles à contacter » du bloc de génération.
                ->missing('urls.contactsQueue')
                ->missing('urls.contactsStatistiques')
                ->where('urls.incidentIgnorer', route('livraison.incidents.ignorer', ['incident' => '__ID__']))
                ->has('campagnes', 1));
    }
}
