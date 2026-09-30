<?php
// tests/Feature/Http/LivraisonUiBatchTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Campagne;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Notifications\RouteIncidentNotification;
use App\Support\Creneau;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Lot d'évolutions Livraison du 29/09/2026 : ordre de la file de contact,
 * liste complète des personnes (PersonSelect), vue admin « chauffeur » d'une
 * tournée (avec ses actions), et mail d'incident stylé.
 */
class LivraisonUiBatchTest extends TestCase
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

    private function creerLivraison(Campagne $campagne, string $statutContact, array $famille = []): Livraison
    {
        $famille = Famille::factory()->create($famille);

        return Livraison::create([
            'id_famille' => $famille->id,
            'id_campagne' => $campagne->id,
            'statut' => 'assignee',
            'statut_conditionnement' => 'en_attente',
            'nombre_personnes' => 1,
            'poids_kg' => 10.0,
            'statut_contact' => $statutContact,
        ]);
    }

    private function creerRoute(Campagne $campagne, int $idBenevole, string $statut = 'en_cours'): RouteLivraison
    {
        return RouteLivraison::create([
            'id_campagne' => $campagne->id,
            'id_benevole' => $idBenevole,
            'id_vehicule_type' => 1,
            'creneau' => Creneau::MATIN_1,
            'statut' => $statut,
        ]);
    }

    private function creerEtape(RouteLivraison $route, Livraison $livraison, int $ordre = 1, string $statut = 'en_attente'): EtapeRoute
    {
        return EtapeRoute::create([
            'id_route' => $route->id,
            'id_livraison' => $livraison->id,
            'ordre' => $ordre,
            'statut' => $statut,
        ]);
    }

    // ── §2.1 — ordre de la file de contact ───────────────────────────────

    public function test_la_file_de_contact_suit_lordre_a_contacter_injoignable_confirme_archive_rejetee(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $campagne = $this->creerCampagne();

        // Créées volontairement dans le désordre.
        $rejetee = $this->creerLivraison($campagne, 'rejetee');
        $confirme = $this->creerLivraison($campagne, 'confirme');
        $aContacter = $this->creerLivraison($campagne, 'a_contacter');
        $archive = $this->creerLivraison($campagne, 'archive');
        $injoignable = $this->creerLivraison($campagne, 'injoignable');

        $reponse = $this->actingAs($gestionnaire)
            ->getJson(route('livraison.contacts.queue', ['id_campagne' => $campagne->id]))
            ->assertOk();

        $this->assertSame(
            [$aContacter->id, $injoignable->id, $confirme->id, $archive->id, $rejetee->id],
            collect($reponse->json('data'))->pluck('id')->all(),
        );
    }

    public function test_dans_un_meme_statut_les_familles_avec_email_passent_en_premier(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $campagne = $this->creerCampagne();

        $sansEmail = $this->creerLivraison($campagne, 'a_contacter', ['email' => null]);
        $avecEmail = $this->creerLivraison($campagne, 'a_contacter', ['email' => 'famille@example.fr']);

        $ids = collect(
            $this->actingAs($gestionnaire)
                ->getJson(route('livraison.contacts.queue', ['id_campagne' => $campagne->id]))
                ->json('data'),
        )->pluck('id')->all();

        $this->assertSame([$avecEmail->id, $sansEmail->id], $ids);
    }

    public function test_lordre_contact_couvre_tous_les_statuts_connus(): void
    {
        // Garde-fou : un nouveau statut ajouté à STATUTS_CONTACT doit aussi
        // recevoir un rang d'affichage (sinon il tomberait tout en bas).
        $this->assertEqualsCanonicalizing(Livraison::STATUTS_CONTACT, Livraison::ORDRE_AFFICHAGE_CONTACT);
        $this->assertSame('a_contacter', Livraison::ORDRE_AFFICHAGE_CONTACT[0]);
        $this->assertSame('rejetee', Livraison::ORDRE_AFFICHAGE_CONTACT[array_key_last(Livraison::ORDRE_AFFICHAGE_CONTACT)]);
    }

    // ── §2.2/§3 — liste complète des personnes (PersonSelect) ────────────

    public function test_personnes_recherche_tous_renvoie_la_liste_sans_plafond(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        for ($i = 0; $i < 30; $i++) {
            $this->creerPersonne(['membre']);
        }

        $sansTous = $this->actingAs($gestionnaire)->getJson(route('livraison.personnes.recherche'))->assertOk();
        $this->assertLessThanOrEqual(20, count($sansTous->json()));

        $avecTous = $this->actingAs($gestionnaire)->getJson(route('livraison.personnes.recherche', ['tous' => 1]))->assertOk();
        $this->assertGreaterThan(20, count($avecTous->json()));
        $this->assertArrayHasKey('prenom', $avecTous->json(0));
        $this->assertArrayHasKey('nom', $avecTous->json(0));
    }

    // ── §6.2 — vue admin « chauffeur » ───────────────────────────────────

    public function test_un_gestionnaire_peut_ouvrir_lecran_chauffeur_dune_tournee(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id);
        $this->creerEtape($route, $this->creerLivraison($campagne, 'confirme'));

        $this->actingAs($gestionnaire)
            ->get(route('livraison.routes.vue-chauffeur', $route))
            ->assertOk()
            ->assertSee("Tournée #{$route->id}", false)
            ->assertSee('vous agissez à sa place');
    }

    public function test_lecran_chauffeur_reste_visible_pour_une_tournee_terminee(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $benevole = $this->creerPersonne(['benevole']);
        $route = $this->creerRoute($this->creerCampagne(), $benevole->id, 'terminee');

        $this->actingAs($gestionnaire)
            ->get(route('livraison.routes.vue-chauffeur', $route))
            ->assertOk();
    }

    public function test_un_benevole_ne_peut_pas_ouvrir_lecran_chauffeur_admin(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $route = $this->creerRoute($this->creerCampagne(), $benevole->id);

        // EnsureLivraisonRole/role: redirige ou refuse — dans les deux cas pas de 200.
        $this->actingAs($benevole)
            ->get(route('livraison.routes.vue-chauffeur', $route))
            ->assertRedirect();
    }

    public function test_un_gestionnaire_peut_confirmer_une_etape_a_la_place_du_chauffeur(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id);
        $livraison = $this->creerLivraison($campagne, 'confirme');
        $etape = $this->creerEtape($route, $livraison);

        $this->actingAs($gestionnaire)
            ->postJson(route('livraison.benevole.etapes.confirmer', $etape))
            ->assertOk();

        $this->assertDatabaseHas('etapes_route', ['id' => $etape->id, 'statut' => 'livree']);
        $this->assertDatabaseHas('livraisons', ['id' => $livraison->id, 'statut' => 'livree']);
    }

    public function test_un_gestionnaire_peut_ignorer_une_etape_et_lincident_est_trace_a_son_nom(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id);
        $etape = $this->creerEtape($route, $this->creerLivraison($campagne, 'confirme'));

        $this->actingAs($gestionnaire)
            ->postJson(route('livraison.benevole.etapes.ignoree', $etape), ['notes' => 'Porte close'])
            ->assertOk();

        $this->assertDatabaseHas('etapes_route', ['id' => $etape->id, 'statut' => 'ignoree']);
        $this->assertDatabaseHas('route_incidents', [
            'id_route' => $route->id,
            'type' => 'livraison_ignoree',
            'signale_par' => $gestionnaire->id,
            'notes' => 'Porte close',
        ]);
    }

    public function test_un_gestionnaire_peut_terminer_puis_cloturer_la_tournee_et_le_chauffeur_devient_disponible(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id);
        $this->creerEtape($route, $this->creerLivraison($campagne, 'confirme'), 1, 'livree');

        $this->actingAs($gestionnaire)
            ->postJson(route('livraison.benevole.routes.livraison-terminee', $route))
            ->assertOk();
        $this->assertDatabaseHas('routes', ['id' => $route->id, 'statut' => 'livraisons_terminees']);

        $this->actingAs($gestionnaire)
            ->postJson(route('livraison.benevole.routes.retour-qg', $route))
            ->assertOk();
        $this->assertDatabaseHas('routes', ['id' => $route->id, 'statut' => 'terminee']);

        // Disponible = le CHAUFFEUR, pas l'admin qui a cliqué à sa place.
        $this->assertDatabaseHas('benevole_retours_qg', [
            'id_route_origine' => $route->id,
            'id_personne' => $benevole->id,
        ]);
        $this->assertDatabaseMissing('benevole_retours_qg', ['id_personne' => $gestionnaire->id]);
    }

    public function test_un_autre_benevole_ne_peut_toujours_pas_agir_sur_la_tournee_dun_autre(): void
    {
        $proprietaire = $this->creerPersonne(['benevole']);
        $intrus = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $proprietaire->id);
        $etape = $this->creerEtape($route, $this->creerLivraison($campagne, 'confirme'));

        $this->actingAs($intrus)
            ->postJson(route('livraison.benevole.etapes.confirmer', $etape))
            ->assertStatus(422);

        $this->assertDatabaseHas('etapes_route', ['id' => $etape->id, 'statut' => 'en_attente']);
    }

    public function test_le_chauffeur_proprietaire_peut_toujours_confirmer_sa_propre_etape(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $benevole->id);
        $etape = $this->creerEtape($route, $this->creerLivraison($campagne, 'confirme'));

        $this->actingAs($benevole)
            ->postJson(route('livraison.benevole.etapes.confirmer', $etape))
            ->assertOk();

        $this->assertDatabaseHas('etapes_route', ['id' => $etape->id, 'statut' => 'livree']);
    }

    // ── §5.3 — mail d'incident stylé ─────────────────────────────────────

    public function test_le_mail_dincident_utilise_le_gabarit_amana_et_affiche_les_notes(): void
    {
        $admin = $this->creerPersonne(['admin'], ['prenom' => 'Farid']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $this->creerPersonne(['benevole'])->id, 'chargement');

        $incident = RouteIncident::withoutEvents(fn () => RouteIncident::create([
            'id_route' => $route->id,
            'type' => 'capacite',
            'signale_par' => $admin->id,
            'statut' => 'ouvert',
            'notes' => 'Le camion est plein à 80 %',
        ]));

        $mail = (new RouteIncidentNotification($incident))->toMail($admin);
        $html = (string) $mail->render();

        $this->assertStringContainsString('Bonjour, Farid', $html);
        $this->assertStringContainsString("tournée #{$route->id}", $html);
        $this->assertStringContainsString('Le camion est plein à 80 %', $html);
        $this->assertStringContainsString(route('livraison.suivi-livraison.index'), $html);
        // Gabarit amana_shared (et non le « Hello! » / « Regards » de Laravel).
        $this->assertStringNotContainsString('Regards', $html);
    }

    public function test_le_mail_dincident_sans_notes_naffiche_pas_de_bloc_precisions(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $this->creerPersonne(['benevole'])->id, 'chargement');

        $incident = RouteIncident::withoutEvents(fn () => RouteIncident::create([
            'id_route' => $route->id,
            'type' => 'capacite',
            'signale_par' => $admin->id,
            'statut' => 'ouvert',
        ]));

        $html = (string) (new RouteIncidentNotification($incident))->toMail($admin)->render();

        $this->assertStringNotContainsString('Précisions du signalement', $html);
    }

    public function test_un_signalement_de_capacite_cree_un_incident_et_previent_les_admins(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $this->creerPersonne(['admin']);
        $campagne = $this->creerCampagne();
        $route = $this->creerRoute($campagne, $this->creerPersonne(['benevole'])->id, 'chargement');

        $this->actingAs($gestionnaire)
            ->postJson(route('livraison.chargement.capacite', $route), ['notes' => 'Trop de colis'])
            ->assertOk();

        $this->assertDatabaseHas('route_incidents', ['id_route' => $route->id, 'type' => 'capacite', 'notes' => 'Trop de colis']);
        // Comptage plutôt que assertSentTo($admin, …) : RouteIncident::booted()
        // notifie des Amana\Shared\Models\Personne alors que les fixtures créent
        // des App\Models\Personne — NotificationFake indexe par classe du
        // destinataire, donc assertSentTo ne retrouverait jamais l'instance.
        // Destinataires attendus : l'admin (adminsDe) + le gestionnaire.
        Notification::assertSentTimes(RouteIncidentNotification::class, 2);
    }
}
