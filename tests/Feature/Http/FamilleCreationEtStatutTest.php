<?php
// tests/Feature/Http/FamilleCreationEtStatutTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Jobs\ResoudreAdresseFamille;
use App\Models\Famille;
use App\Models\FamilleDocument;
use App\Models\Organisation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * 03/10/2026 — Dossiers familles : pastilles du filtre Statut (props
 * manquantes sur la liste) et création d'un dossier par le staff
 * (FamilleCreationController, bouton « Créer une famille »).
 */
class FamilleCreationEtStatutTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Bus::fake([ResoudreAdresseFamille::class]);
        $this->chargerRolesFamilles();
    }

    private function organisation(): Organisation
    {
        return Organisation::create(['code' => 'ORG_TEST', 'nom' => 'Org test', 'actif' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $surcharge = []): array
    {
        return array_merge([
            'nom' => 'Benali',
            'prenom' => 'Karim',
            'email' => 'karim.benali@example.test',
            'telephone' => '0612345678',
            'langue' => 'ar',
            'id_organisation' => $this->organisation()->id,
            'type_hebergement' => 'non',
            'adresse' => '1 rue de la Paix',
            'code_postal' => '44000',
            'ville_texte' => 'Nantes',
            'nombre_adulte' => 2,
            'nombre_enfant' => 3,
            'circonstances' => 'Famille en difficulté.',
            'type_piece_identite' => 'titre_sejour',
            'type_activite' => 'non',
            'documents_identite' => [UploadedFile::fake()->create('passeport.pdf', 20, 'application/pdf')],
            'labels_identite' => ['Passeport Karim'],
            'documents_aide' => [UploadedFile::fake()->create('caf.pdf', 20, 'application/pdf')],
        ], $surcharge);
    }

    // ── Pastilles du filtre Statut ───────────────────────────────────────

    public function test_la_liste_expose_les_statuts_et_leurs_couleurs_sans_recu(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);

        $this->actingAs($gestionnaire)->get(route('familles.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Familles/Index')
                ->where('etatsDisponibles', ['En cours', 'En attente', 'Validé', 'Rejeté', 'Archivé'])
                ->where('etatCouleurs', Famille::ETAT_COLORS));
    }

    // ── Visibilité du bouton / de la route ──────────────────────────────

    public function test_le_bouton_creer_est_propose_au_gestionnaire_pas_au_membre(): void
    {
        $this->actingAs($this->creerPersonne(['gestionnaire']))->get(route('familles.index'))
            ->assertInertia(fn ($page) => $page->where('peutCreerFamille', true)->where('creerFamilleUrl', route('familles.creer')));

        $this->actingAs($this->creerPersonne(['membre']))->get(route('familles.index'))
            ->assertInertia(fn ($page) => $page->where('peutCreerFamille', false));
    }

    public function test_la_page_de_creation_est_reservee_au_gestionnaire(): void
    {
        // EnsureRole redirige vers l'accueil avec un message d'erreur (jamais de 403).
        $accueil = route(config('amana-shared.home_route'));
        $this->actingAs($this->creerPersonne(['membre']))->get(route('familles.creer'))->assertRedirect($accueil);
        $this->actingAs($this->creerPersonne(['gestionnaire']))->get(route('familles.creer'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Familles/Creer')->where('storeUrl', route('familles.store')));
    }

    public function test_un_membre_ne_peut_pas_creer_de_famille(): void
    {
        $this->actingAs($this->creerPersonne(['membre']))->postJson(route('familles.store'), $this->payload())
            ->assertRedirect(route(config('amana-shared.home_route')));
        $this->assertSame(0, Famille::count());
    }

    // ── Création ─────────────────────────────────────────────────────────

    public function test_creation_staff_cree_le_dossier_en_attente_avec_ses_documents(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);

        $reponse = $this->actingAs($gestionnaire)->postJson(route('familles.store'), $this->payload());

        $reponse->assertCreated()->assertJsonPath('success', true);

        $famille = Famille::where('email', 'karim.benali@example.test')->firstOrFail();
        $this->assertSame('En attente', $famille->etat_dossier);
        $this->assertSame('ar', $famille->langue);
        $reponse->assertJsonPath('redirect', route('familles.index', ['ouvrir' => $famille->id, 'etat_dossier' => '']));

        // Pas de consentement ni d'email de confirmation en attente : le dossier existe tout de suite.
        $this->assertDatabaseHas('famille_documents', ['id_famille' => $famille->id, 'type' => 'identity', 'original_name' => 'Passeport Karim.pdf']);
        // titre_sejour ⇒ justificatif CAF (et non AME, réservé à « autre »).
        $this->assertDatabaseHas('famille_documents', ['id_famille' => $famille->id, 'type' => 'caf']);
        $this->assertSame(2, FamilleDocument::where('id_famille', $famille->id)->count());

        // Une seule résolution d'adresse, déclenchée pour ce dossier (idFamille est privé : on vérifie le compte).
        Bus::assertDispatchedTimes(ResoudreAdresseFamille::class, 1);
    }

    public function test_piece_autre_range_le_justificatif_daide_en_ame(): void
    {
        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->postJson(route('familles.store'), $this->payload(['type_piece_identite' => 'autre']))
            ->assertCreated();

        $this->assertDatabaseHas('famille_documents', ['type' => 'ame']);
        $this->assertDatabaseMissing('famille_documents', ['type' => 'caf']);
    }

    public function test_le_consentement_nest_pas_exige_cote_staff(): void
    {
        // payload() n'envoie aucun champ 'consentement' : 201 ci-dessus le prouve déjà ;
        // ici on vérifie qu'un envoi explicite à false n'est pas non plus bloquant.
        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->postJson(route('familles.store'), $this->payload(['consentement' => false]))
            ->assertCreated();
    }

    public function test_validation_documents_et_organisation_obligatoires(): void
    {
        $reponse = $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->postJson(route('familles.store'), array_diff_key($this->payload(), array_flip(['documents_identite', 'documents_aide', 'id_organisation'])));

        $reponse->assertStatus(422)->assertJsonValidationErrors(['documents_identite', 'documents_aide', 'id_organisation']);
        $this->assertSame(0, Famille::count());
    }

    public function test_activite_exige_un_secteur_ou_autre(): void
    {
        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->postJson(route('familles.store'), $this->payload(['type_activite' => 'temps_plein']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['secteurs_activite']);
    }

    public function test_un_doublon_est_refuse_sans_modifier_le_dossier_existant(): void
    {
        $existante = Famille::factory()->create([
            'email' => 'karim.benali@example.test',
            'nom' => 'Ancien nom',
            'etat_dossier' => 'Validé',
        ]);

        $reponse = $this->actingAs($this->creerPersonne(['gestionnaire']))->postJson(route('familles.store'), $this->payload());

        $reponse->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('doublon.id', $existante->id)
            ->assertJsonPath('doublon.url', route('familles.index', ['ouvrir' => $existante->id, 'etat_dossier' => '']));

        // Contrairement au flux public (upsert), rien n'est écrasé.
        $existante->refresh();
        $this->assertSame('Ancien nom', $existante->nom);
        $this->assertSame('Validé', $existante->etat_dossier);
        $this->assertSame(1, Famille::count());
        Bus::assertNotDispatched(ResoudreAdresseFamille::class);
    }

    public function test_le_formulaire_public_exige_toujours_le_consentement(): void
    {
        // Garde-fou du refactor FamilleIntakeRules : l'extraction ne doit pas
        // avoir assoupli le flux public.
        $this->postJson(route('intake.store'), $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consentement']);
    }
}
