<?php
// tests/Feature/Http/MaRouteDemarrageTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Campagne;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\LivraisonColis;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Services\MaRouteVueService;
use App\Services\RouteGenerationService;
use App\Support\Creneau;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Lot du 30/09/2026 : écran chauffeur (démarrage explicite, retour d'un arrêt
 * ignoré à en_cours, ordre/stats/polling), pills « jamais couvertes » côté
 * serveur (se_deplace en bas), polling de la file Packaging et id famille
 * dans la file de contact.
 */
class MaRouteDemarrageTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
    }

    private function creerCampagne(): Campagne
    {
        return Campagne::create([
            'type' => 'zakat_el_fitr',
            'statut' => 'en_cours',
            'date_livraison' => now()->addWeek()->toDateString(),
            'hq_latitude' => 0.0,
            'hq_longitude' => 0.0,
        ]);
    }

    private function creerLivraison(Campagne $campagne, array $livraison = [], array $famille = []): Livraison
    {
        $famille = Famille::factory()->create($famille);

        return Livraison::create($livraison + [
            'id_famille' => $famille->id,
            'id_campagne' => $campagne->id,
            'statut' => 'assignee',
            'statut_conditionnement' => 'prete',
            'nombre_personnes' => 1,
            'poids_kg' => 10.0,
            'statut_contact' => 'confirme',
        ]);
    }

    private function creerRoute(Campagne $campagne, int $idBenevole, string $statut): RouteLivraison
    {
        return RouteLivraison::create([
            'id_campagne' => $campagne->id,
            'id_benevole' => $idBenevole,
            'id_vehicule_type' => 1,
            'creneau' => Creneau::MATIN_1,
            'statut' => $statut,
        ]);
    }

    private function creerEtape(RouteLivraison $route, Livraison $livraison, int $ordre, string $statut = 'en_attente'): EtapeRoute
    {
        return EtapeRoute::create([
            'id_route' => $route->id,
            'id_livraison' => $livraison->id,
            'ordre' => $ordre,
            'statut' => $statut,
        ]);
    }

    // ── « Je commence ma tournée » ───────────────────────────────────────

    public function test_demarrer_passe_la_tournee_ses_arrets_et_ses_livraisons_en_cours(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, 'charge');
        $l1 = $this->creerLivraison($campagne);
        $l2 = $this->creerLivraison($campagne);
        $this->creerEtape($route, $l1, 1);
        $this->creerEtape($route, $l2, 2);

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.routes.demarrer', $route))
            ->assertOk();

        $this->assertDatabaseHas('routes', ['id' => $route->id, 'statut' => 'en_cours']);
        $this->assertSame(2, EtapeRoute::where('id_route', $route->id)->where('statut', 'en_cours')->count());
        $this->assertDatabaseHas('livraisons', ['id' => $l1->id, 'statut' => 'en_cours']);
        $this->assertDatabaseHas('livraisons', ['id' => $l2->id, 'statut' => 'en_cours']);
    }

    #[DataProvider('statutsNonDemarrables')]
    public function test_demarrer_est_refuse_tant_que_le_chargement_nest_pas_termine(string $statut): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $route = $this->creerRoute($this->creerCampagne(), $benevole->id, $statut);

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.routes.demarrer', $route))
            ->assertStatus(422);

        $this->assertDatabaseHas('routes', ['id' => $route->id, 'statut' => $statut]);
    }

    public static function statutsNonDemarrables(): array
    {
        return [['planifiee'], ['chargement'], ['en_cours'], ['livraisons_terminees']];
    }

    public function test_un_autre_benevole_ne_peut_pas_demarrer_la_tournee(): void
    {
        $proprietaire = $this->creerPersonne(['benevole']);
        $intrus = $this->creerPersonne(['benevole']);
        $route = $this->creerRoute($this->creerCampagne(), $proprietaire->id, 'charge');

        $this->actingAs($intrus)
            ->postJson(route('livraison.benevole.routes.demarrer', $route))
            ->assertStatus(422);

        $this->assertDatabaseHas('routes', ['id' => $route->id, 'statut' => 'charge']);
    }

    public function test_confirmer_et_ignorer_sont_refuses_avant_le_demarrage(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, 'charge');
        $etape = $this->creerEtape($route, $this->creerLivraison($campagne), 1);

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.etapes.confirmer', $etape))
            ->assertStatus(422);
        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.etapes.ignoree', $etape), ['notes' => 'x'])
            ->assertStatus(422);

        $this->assertDatabaseHas('etapes_route', ['id' => $etape->id, 'statut' => 'en_attente']);
    }

    public function test_le_scan_avant_demarrage_ne_confirme_rien_et_invite_a_demarrer(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, 'charge');
        $etape = $this->creerEtape($route, $this->creerLivraison($campagne), 1);

        $this->actingAs($benevole)
            ->get(route('livraison.benevole.etapes.scan', $etape))
            ->assertOk()
            ->assertSee('Tournée non démarrée');

        $this->assertDatabaseHas('etapes_route', ['id' => $etape->id, 'statut' => 'en_attente']);
    }

    public function test_apres_demarrage_un_arret_en_cours_peut_etre_livre_et_la_reponse_indique_si_tout_est_traite(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, 'charge');
        $e1 = $this->creerEtape($route, $this->creerLivraison($campagne), 1);
        $e2 = $this->creerEtape($route, $this->creerLivraison($campagne), 2);
        $this->actingAs($benevole)->postJson(route('livraison.benevole.routes.demarrer', $route))->assertOk();

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.etapes.confirmer', $e1))
            ->assertOk()->assertJson(['tout_traite' => false]);
        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.etapes.confirmer', $e2))
            ->assertOk()->assertJson(['tout_traite' => true]);
    }

    public function test_un_arret_en_cours_ne_compte_pas_comme_traite(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, 'en_cours');
        $this->creerEtape($route, $this->creerLivraison($campagne), 1, 'en_cours');

        $this->assertFalse($route->toutesEtapesTraitees());
    }

    // ── Remettre un arrêt ignoré en cours ────────────────────────────────

    public function test_remettre_en_cours_rouvre_larret_la_livraison_et_clot_lincident(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, 'en_cours');
        $livraison = $this->creerLivraison($campagne, ['statut' => 'ignoree']);
        $etape = $this->creerEtape($route, $livraison, 1, 'ignoree');
        $incident = RouteIncident::withoutEvents(fn() => RouteIncident::create([
            'id_route' => $route->id,
            'type' => 'livraison_ignoree',
            'id_livraison' => $livraison->id,
            'signale_par' => $benevole->id,
            'statut' => 'ouvert',
        ]));

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.etapes.remettre-en-cours', $etape))
            ->assertOk();

        $this->assertDatabaseHas('etapes_route', ['id' => $etape->id, 'statut' => 'en_cours']);
        $this->assertDatabaseHas('livraisons', ['id' => $livraison->id, 'statut' => 'en_cours']);
        $this->assertDatabaseHas('route_incidents', ['id' => $incident->id, 'statut' => 'resolu']);
    }

    public function test_un_gestionnaire_peut_remettre_un_arret_en_cours_et_la_tournee_terminee_se_rouvre(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, 'livraisons_terminees');
        $etape = $this->creerEtape($route, $this->creerLivraison($campagne, ['statut' => 'ignoree']), 1, 'ignoree');

        $this->actingAs($gestionnaire)
            ->postJson(route('livraison.benevole.etapes.remettre-en-cours', $etape))
            ->assertOk();

        $this->assertDatabaseHas('routes', ['id' => $route->id, 'statut' => 'en_cours']);
        $this->assertFalse($route->fresh()->toutesEtapesTraitees());
    }

    public function test_remettre_en_cours_est_refuse_pour_un_arret_non_ignore_ou_une_tournee_cloturee(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, 'en_cours');
        $livre = $this->creerEtape($route, $this->creerLivraison($campagne), 1, 'livree');
        $routeTerminee = $this->creerRoute($campagne, $benevole->id, 'terminee');
        $ignoreeCloturee = $this->creerEtape($routeTerminee, $this->creerLivraison($campagne), 1, 'ignoree');

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.etapes.remettre-en-cours', $livre))
            ->assertStatus(422);
        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.etapes.remettre-en-cours', $ignoreeCloturee))
            ->assertStatus(422);
    }

    // ── Données de l'écran chauffeur ─────────────────────────────────────

    public function test_lecran_ordonne_a_faire_puis_ignore_puis_livre_selon_lordre_de_tournee_avec_colis_et_distance(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, 'en_cours');

        $livre = $this->creerEtape($route, $this->creerLivraison($campagne, [], ['latitude' => 0.1, 'longitude' => 0.1]), 1, 'livree');
        $ignore = $this->creerEtape($route, $this->creerLivraison($campagne, [], ['latitude' => 0.1, 'longitude' => 0.1]), 2, 'ignoree');
        $faireTard = $this->creerEtape($route, $this->creerLivraison($campagne, [], ['latitude' => 0.2, 'longitude' => 0.2]), 4, 'en_cours');
        $faireTot = $this->creerEtape($route, $this->creerLivraison($campagne, [], ['latitude' => 0.3, 'longitude' => 0.3]), 3, 'en_cours');

        foreach ([1, 2] as $numero) {
            LivraisonColis::create(['id_livraison' => $faireTot->id_livraison, 'numero' => $numero, 'statut' => 'a_preparer']);
        }

        $donnees = app(MaRouteVueService::class)->preparer($route);

        $this->assertSame(
            [$faireTot->id, $faireTard->id, $ignore->id, $livre->id],
            array_column($donnees['etapes'], 'id'),
        );
        $this->assertSame(2, $donnees['etapes'][0]['nb_colis']);
        $this->assertNotNull($donnees['etapes'][0]['distance_km']);
        $this->assertFalse($donnees['tout_traite']);
    }

    public function test_les_stats_de_la_tournee_sont_calculees(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, 'en_cours');
        $this->creerEtape($route, $this->creerLivraison($campagne, ['poids_kg' => 10.0]), 1, 'livree');
        $this->creerEtape($route, $this->creerLivraison($campagne, ['poids_kg' => 5.0]), 2, 'ignoree');
        $this->creerEtape($route, $this->creerLivraison($campagne, ['poids_kg' => 7.5]), 3, 'en_cours');

        $stats = app(MaRouteVueService::class)->preparer($route)['stats'];

        $this->assertSame(3, $stats['arrets_total']);
        $this->assertSame(1, $stats['arrets_restants']);
        $this->assertSame(1, $stats['arrets_livres']);
        $this->assertSame(1, $stats['arrets_ignores']);
        $this->assertSame(22.5, $stats['poids_total_kg']);
        $this->assertSame(7.5, $stats['poids_restant_kg']);
        $this->assertSame(67, $stats['avancement_pct']);
    }

    public function test_le_bouton_demarrer_nest_actif_que_pour_une_tournee_chargee(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $this->creerRoute($campagne, $benevole->id, 'chargement');

        $this->actingAs($benevole)->get(route('livraison.benevole.ma-route.show'))
            ->assertOk()
            ->assertSee('Je commence ma tournée')
            ->assertSee('Disponible une fois le chargement terminé.');
    }

    public function test_la_signature_de_polling_change_quand_un_arret_change_de_statut(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, 'en_cours');
        $etape = $this->creerEtape($route, $this->creerLivraison($campagne), 1, 'en_cours');

        $avant = $this->actingAs($benevole)->getJson(route('livraison.benevole.routes.etat', $route))
            ->assertOk()->json('signature');
        $etape->update(['statut' => 'ignoree']);
        $apres = $this->actingAs($benevole)->getJson(route('livraison.benevole.routes.etat', $route))
            ->assertOk()->json('signature');

        $this->assertNotSame($avant, $apres);
    }

    public function test_letat_de_polling_est_interdit_a_un_autre_benevole(): void
    {
        $proprietaire = $this->creerPersonne(['benevole']);
        $intrus = $this->creerPersonne(['benevole']);
        $route = $this->creerRoute($this->creerCampagne(), $proprietaire->id, 'en_cours');

        $this->actingAs($intrus)->getJson(route('livraison.benevole.routes.etat', $route))->assertForbidden();
    }

    // ── Suivi : « jamais couvertes » ─────────────────────────────────────

    public function test_les_non_couvertes_placent_les_familles_se_deplace_en_dernier(): void
    {
        $campagne = $this->creerCampagne();
        $seDeplace = $this->creerLivraison($campagne, ['statut' => 'non_assignee', 'se_deplace' => true]);
        $normale = $this->creerLivraison($campagne, ['statut' => 'non_assignee', 'se_deplace' => false]);
        $autreNormale = $this->creerLivraison($campagne, ['statut' => 'non_assignee', 'se_deplace' => false]);

        $ids = app(RouteGenerationService::class)
            ->livraisonsNonCouvertes($campagne)->pluck('id')->all();

        $this->assertSame([$normale->id, $autreNormale->id, $seDeplace->id], $ids);
    }

    // ── Packaging : polling ──────────────────────────────────────────────

    public function test_la_liste_packaging_renvoie_cartes_signatures_et_stats(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $campagne = $this->creerCampagne();
        $livraison = $this->creerLivraison($campagne, ['statut_conditionnement' => 'en_attente']);

        $reponse = $this->actingAs($gestionnaire)
            ->getJson(route('livraison.packaging.liste', $campagne))
            ->assertOk()
            ->assertJsonPath('lignes.0.id', $livraison->id)
            ->assertJsonPath('stats.restantes', 1);

        $this->assertStringContainsString("id=\"livraison-{$livraison->id}\"", $reponse->json('lignes.0.html'));
        $this->assertSame(md5($reponse->json('lignes.0.html')), $reponse->json('lignes.0.sig'));
    }

    public function test_la_signature_packaging_change_quand_le_conditionnement_change(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $campagne = $this->creerCampagne();
        $livraison = $this->creerLivraison($campagne, ['statut_conditionnement' => 'en_attente']);

        $avant = $this->actingAs($gestionnaire)->getJson(route('livraison.packaging.liste', $campagne))->json('lignes.0.sig');
        $livraison->update(['statut_conditionnement' => 'en_cours']);
        $apres = $this->actingAs($gestionnaire)->getJson(route('livraison.packaging.liste', $campagne))->json('lignes.0.sig');

        $this->assertNotSame($avant, $apres);
    }

    public function test_la_page_packaging_rend_les_memes_cartes_que_le_polling(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $campagne = $this->creerCampagne();
        $livraison = $this->creerLivraison($campagne, ['statut_conditionnement' => 'en_attente']);

        $this->actingAs($gestionnaire)
            ->get(route('livraison.packaging.index', $campagne))
            ->assertOk()
            ->assertSee("id=\"livraison-{$livraison->id}\"", false)
            ->assertSee('id="stat-restantes"', false);
    }

    // ── Contacts : id famille ────────────────────────────────────────────

    public function test_la_file_de_contact_expose_lid_famille(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $campagne = $this->creerCampagne();
        $livraison = $this->creerLivraison($campagne, ['statut_contact' => 'a_contacter']);

        $this->actingAs($gestionnaire)
            ->getJson(route('livraison.contacts.queue', ['id_campagne' => $campagne->id]))
            ->assertOk()
            ->assertJsonPath('data.0.famille.id', $livraison->id_famille);
    }
    // ── 09/10/2026 : démarrage seul avant le départ, annulation de « livrée » ──

    public function test_avant_le_demarrage_seul_le_bouton_et_son_explication_sont_affiches(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, 'charge');
        $this->creerEtape($route, $this->creerLivraison($campagne, [], ['nom' => 'Zebulon', 'prenom' => 'Famille']), 1);

        $this->actingAs($benevole)->get(route('livraison.benevole.ma-route.show'))
            ->assertOk()
            ->assertSee('Je commence ma tournée')
            ->assertSee('cliquez ici pour démarrer votre tournée')
            ->assertDontSee('Zebulon')
            ->assertDontSee('Colis à livrer')
            ->assertDontSee('Google Maps');
    }

    public function test_une_tournee_en_cours_affiche_ses_arrets_sans_le_bouton_de_demarrage(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, 'en_cours');
        $this->creerEtape($route, $this->creerLivraison($campagne, [], ['nom' => 'Zebulon']), 1, 'en_cours');

        $this->actingAs($benevole)->get(route('livraison.benevole.ma-route.show'))
            ->assertOk()
            ->assertSee('Zebulon')
            ->assertSee('Colis à livrer')
            ->assertDontSee('Je commence ma tournée');
    }

    public function test_annuler_une_livraison_remet_larret_et_la_livraison_en_cours(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, 'en_cours');
        $livraison = $this->creerLivraison($campagne, ['statut' => 'livree']);
        $etape = $this->creerEtape($route, $livraison, 1, 'livree');

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.etapes.annuler-livraison', $etape))
            ->assertOk();

        $this->assertSame('en_cours', $etape->fresh()->statut);
        $this->assertSame('en_cours', $livraison->fresh()->statut);
    }

    public function test_annuler_une_livraison_rouvre_une_tournee_deja_terminee(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id, 'livraisons_terminees');
        $etape = $this->creerEtape($route, $this->creerLivraison($campagne, ['statut' => 'livree']), 1, 'livree');

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.etapes.annuler-livraison', $etape))
            ->assertOk();

        $this->assertSame('en_cours', $route->fresh()->statut);
    }

    public function test_annuler_une_livraison_est_refuse_pour_un_arret_non_livre_ou_une_tournee_terminee(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $enCours = $this->creerRoute($campagne, $benevole->id, 'en_cours');
        $pasLivre = $this->creerEtape($enCours, $this->creerLivraison($campagne), 1, 'en_cours');
        $terminee = $this->creerRoute($campagne, $benevole->id, 'terminee');
        $livre = $this->creerEtape($terminee, $this->creerLivraison($campagne, ['statut' => 'livree']), 1, 'livree');

        $this->actingAs($benevole)->postJson(route('livraison.benevole.etapes.annuler-livraison', $pasLivre))->assertStatus(422);
        $this->postJson(route('livraison.benevole.etapes.annuler-livraison', $livre))->assertStatus(422);
        $this->assertSame('livree', $livre->fresh()->statut);
    }

    public function test_un_autre_chauffeur_ne_peut_pas_annuler_la_livraison(): void
    {
        $proprietaire = $this->creerPersonne(['benevole']);
        $autre = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $proprietaire->id, 'en_cours');
        $etape = $this->creerEtape($route, $this->creerLivraison($campagne, ['statut' => 'livree']), 1, 'livree');

        $this->actingAs($autre)
            ->postJson(route('livraison.benevole.etapes.annuler-livraison', $etape))
            ->assertStatus(422);

        $this->assertSame('livree', $etape->fresh()->statut);
    }
}
