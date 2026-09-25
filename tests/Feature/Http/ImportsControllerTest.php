<?php
// tests/Feature/Http/ImportsControllerTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Jobs\SynchroniserContactGoogle;
use App\Models\Famille;
use App\Models\FamilleImport;
use App\Models\Organisation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Phase 5 (opportunistic): ImportsController is the largest/highest-risk
 * of the remaining admin controllers — shared pipeline between staff
 * (/admin/imports) and gestionnaire_externe (/mes-imports), per its own
 * docblock (28/08/2026 organisations partenaires revision).
 *
 * SECOND FINDING while writing this file, same shape as the
 * FamilleOrganisationDemande one already reported: App\Models\FamilleImport's
 * $fillable is `['type', 'source', 'uploaded_by', 'status']` — missing
 * 'id_organisation', which ImportsController::traiterImport() passes to
 * FamilleImport::create(). As far as I can trace, this means
 * famille_imports.id_organisation is ALWAYS persisted as null, regardless
 * of what resoudreIdOrganisation() resolves — which breaks index()'s
 * gestionnaire_externe scope (whereIn('id_organisation', ...) can never
 * match null) and assertAccesImport() (abort_unless($import->id_organisation
 * && in_array(...)) is always false for null), i.e. a gestionnaire_externe
 * would see NONE of their own imports and get 403 accessing them directly.
 * The tests below marked with "FINDING" assert the INTENDED behavior and
 * are expected to fail until 'id_organisation' is added to $fillable.
 */
class ImportsControllerTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake(); // ResoudreAdresseFamille / SynchroniserContactGoogle must not actually run
        $this->chargerRolesFamilles();
    }

    private function organisation(string $code): Organisation
    {
        return Organisation::create(['code' => $code, 'nom' => $code, 'actif' => true]);
    }

    private function rattacherPersonneAOrganisation(int $idPersonne, int $idOrganisation): void
    {
        DB::table('personne_organisation')->insert([
            'id_personne' => $idPersonne,
            'id_organisation' => $idOrganisation,
            'date_attribution' => now()->toDateString(),
        ]);
    }

    private function ligneValide(array $overrides = []): array
    {
        return array_merge([
            'nom' => 'Dupont',
            'prenom' => 'Fatima',
            'telephone' => '0600000000',
        ], $overrides);
    }

    // ── storeManuel() — pipeline commun ─────────────────────────────────

    public function test_storeManuel_cree_un_import_avec_une_ligne_reussie_par_famille_creee(): void
    {
        $admin = $this->creerPersonne(['admin']);

        $reponse = $this->actingAs($admin)->postJson(route('admin.imports.store-manuel'), [
            'lignes' => [$this->ligneValide()],
        ]);

        $reponse->assertOk();
        $import = FamilleImport::with('rows')->findOrFail($reponse->json('importId'));
        $this->assertSame('terminé', $import->status);
        $this->assertCount(1, $import->rows);
        $this->assertSame('success', $import->rows->first()->status);
        $this->assertTrue($import->rows->first()->cree);
        $this->assertNotNull($import->rows->first()->id_famille);
        $this->assertDatabaseHas('familles', ['nom' => 'Dupont', 'prenom' => 'Fatima']);
    }

    public function test_storeManuel_ignore_les_lignes_entierement_vides(): void
    {
        $admin = $this->creerPersonne(['admin']);

        $reponse = $this->actingAs($admin)->postJson(route('admin.imports.store-manuel'), [
            'lignes' => [$this->ligneValide(), ['nom' => '', 'prenom' => '', 'telephone' => '']],
        ]);

        $import = FamilleImport::with('rows')->findOrFail($reponse->json('importId'));
        $this->assertSame('success', $import->rows[0]->status);
        $this->assertSame('skipped', $import->rows[1]->status);
    }

    public function test_storeManuel_marque_une_ligne_invalide_en_erreur_sans_creer_de_famille(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $avant = Famille::count();

        $reponse = $this->actingAs($admin)->postJson(route('admin.imports.store-manuel'), [
            // telephone manquant — required par FamilleImportService::traiterLigne()
            'lignes' => [['nom' => 'Dupont', 'prenom' => 'Fatima']],
        ]);

        $import = FamilleImport::with('rows')->findOrFail($reponse->json('importId'));
        $this->assertSame('error', $import->rows->first()->status);
        $this->assertNotNull($import->rows->first()->error_message);
        $this->assertSame($avant, Famille::count());
    }

    public function test_storeManuel_deuxieme_ligne_meme_telephone_et_nom_est_traitee_comme_mise_a_jour_pas_creation(): void
    {
        $admin = $this->creerPersonne(['admin']);

        $reponse = $this->actingAs($admin)->postJson(route('admin.imports.store-manuel'), [
            'lignes' => [
                // No email on either line, so trouverDoublon() matches on
                // telephone+nom together — both must stay identical across
                // the two lines for the second to be recognized as a
                // duplicate of the first (only 'prenom' differs here).
                $this->ligneValide(['telephone' => '0611112222']),
                $this->ligneValide(['telephone' => '0611112222', 'prenom' => 'Autre Prenom']),
            ],
        ]);

        $import = FamilleImport::with('rows')->findOrFail($reponse->json('importId'));
        $this->assertTrue($import->rows[0]->cree);
        $this->assertFalse($import->rows[1]->cree);
        $this->assertSame(1, Famille::where('telephone', '0611112222')->count());
    }

    // ── resoudreIdOrganisation() / accès (id_organisation bug — see class docblock) ──

    public function test_admin_peut_choisir_lorganisation_de_limport_ou_la_laisser_par_defaut(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $organisation = $this->organisation('PARTENAIRE');

        $this->actingAs($admin)->postJson(
            route('admin.imports.store-manuel') . '?id_organisation=' . $organisation->id,
            ['lignes' => [$this->ligneValide()]],
        )->assertOk();

        $famille = Famille::firstWhere('telephone', '0600000000');
        $this->assertSame($organisation->id, $famille->id_organisation);
    }

    /**
     * FINDING (see class docblock): expected to fail — id_organisation is
     * never actually persisted on famille_imports itself due to the
     * missing $fillable entry, even though the FAMILLE it creates does
     * correctly get the forced organisation (a separate code path via
     * FamilleUpsertService, unaffected by this bug).
     */
    public function test_gestionnaire_externe_a_son_organisation_forcee_sur_limport_lui_meme(): void
    {
        $externe = $this->creerPersonne(['gestionnaire_externe']);
        $organisation = $this->organisation('PARTENAIRE');
        $this->rattacherPersonneAOrganisation($externe->id, $organisation->id);

        $reponse = $this->actingAs($externe)->postJson(route('externe.imports.store-manuel'), [
            'lignes' => [$this->ligneValide()],
        ]);

        $import = FamilleImport::findOrFail($reponse->json('importId'));
        $this->assertSame($organisation->id, $import->id_organisation);
    }

    public function test_gestionnaire_externe_a_son_organisation_forcee_sur_la_famille_creee(): void
    {
        $externe = $this->creerPersonne(['gestionnaire_externe']);
        $organisation = $this->organisation('PARTENAIRE');
        $this->rattacherPersonneAOrganisation($externe->id, $organisation->id);

        $this->actingAs($externe)->postJson(route('externe.imports.store-manuel'), [
            // A submitted id_organisation must be IGNORED for gestionnaire_externe.
            'lignes' => [$this->ligneValide(['id_organisation' => 999999])],
        ])->assertOk();

        $famille = Famille::firstWhere('telephone', '0600000000');
        $this->assertSame($organisation->id, $famille->id_organisation);
    }

    /**
     * FINDING (see class docblock): expected to fail for the same reason
     * as test_gestionnaire_externe_a_son_organisation_forcee_sur_limport_lui_meme.
     */
    public function test_gestionnaire_externe_ne_voit_que_les_imports_de_sa_propre_organisation(): void
    {
        $organisationA = $this->organisation('ORG_A');
        $organisationB = $this->organisation('ORG_B');
        $externeA = $this->creerPersonne(['gestionnaire_externe']);
        $this->rattacherPersonneAOrganisation($externeA->id, $organisationA->id);

        $this->actingAs($externeA)->postJson(route('externe.imports.store-manuel'), ['lignes' => [$this->ligneValide()]])->assertOk();

        $importAutreOrg = FamilleImport::create(['type' => 'import', 'source' => 'manual', 'status' => 'terminé']);
        DB::table('famille_imports')->where('id', $importAutreOrg->id)->update(['id_organisation' => $organisationB->id]);

        $reponse = $this->actingAs($externeA)->get(route('externe.imports.index'));

        $imports = $reponse->viewData('imports');
        $this->assertSame(1, $imports->total());
    }

    public function test_admin_voit_tous_les_imports_de_toutes_organisations(): void
    {
        $admin = $this->creerPersonne(['admin']);
        FamilleImport::create(['type' => 'import', 'source' => 'manual', 'status' => 'terminé']);
        FamilleImport::create(['type' => 'import', 'source' => 'manual', 'status' => 'terminé']);

        $reponse = $this->actingAs($admin)->get(route('admin.imports.index'));

        $this->assertSame(2, $reponse->viewData('imports')->total());
    }

    // ── rollback() ───────────────────────────────────────────────────────

    public function test_rollback_supprime_les_familles_creees_et_restaure_les_familles_mises_a_jour(): void
    {
        $admin = $this->creerPersonne(['admin']);
        // Email match (not telephone+nom) so the second line below is
        // recognized as a duplicate of this family despite the different
        // 'nom' — see FamilleUpsertService::trouverDoublon()'s priority.
        $existante = Famille::factory()->create(['nom' => 'AvantImport', 'email' => 'existante@example.fr']);

        $this->actingAs($admin)->postJson(route('admin.imports.store-manuel'), [
            'lignes' => [
                $this->ligneValide(['telephone' => '0699998888']), // creation
                $this->ligneValide(['email' => 'existante@example.fr', 'nom' => 'ApresImport']), // update
            ],
        ])->assertOk();
        $import = FamilleImport::latest('id')->first();

        $this->actingAs($admin)->post(route('admin.imports.rollback', $import->id))->assertRedirect();

        $this->assertDatabaseMissing('familles', ['telephone' => '0699998888']);
        $this->assertSame('AvantImport', $existante->fresh()->nom);
        $this->assertNotNull($import->fresh()->rolled_back_at);
    }

    public function test_rollback_refuse_dannuler_deux_fois(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $this->actingAs($admin)->postJson(route('admin.imports.store-manuel'), ['lignes' => [$this->ligneValide()]])->assertOk();
        $import = FamilleImport::latest('id')->first();
        $this->actingAs($admin)->post(route('admin.imports.rollback', $import->id))->assertRedirect();

        $reponse = $this->actingAs($admin)->post(route('admin.imports.rollback', $import->id));

        $reponse->assertSessionHasErrors('import');
    }

    // ── syncGoogleContacts() ─────────────────────────────────────────────

    public function test_syncGoogleContacts_ne_dispatche_que_pour_les_lignes_reussies(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $this->actingAs($admin)->postJson(route('admin.imports.store-manuel'), [
            'lignes' => [
                $this->ligneValide(['telephone' => '0699998888']), // success
                ['nom' => 'Invalide'], // error — no telephone
            ],
        ])->assertOk();
        $import = FamilleImport::latest('id')->first();

        $this->actingAs($admin)->post(route('admin.imports.sync-google-contacts', $import->id))->assertRedirect();

        Bus::assertDispatchedTimes(SynchroniserContactGoogle::class, 1);
    }

    public function test_syncGoogleContacts_refuse_pour_un_import_annule(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $this->actingAs($admin)->postJson(route('admin.imports.store-manuel'), ['lignes' => [$this->ligneValide()]])->assertOk();
        $import = FamilleImport::latest('id')->first();
        $this->actingAs($admin)->post(route('admin.imports.rollback', $import->id))->assertRedirect();
        Bus::fake(); // reset the dispatch count captured during storeManuel()/rollback()

        $reponse = $this->actingAs($admin)->post(route('admin.imports.sync-google-contacts', $import->id));

        $reponse->assertSessionHasErrors('sync');
        Bus::assertNotDispatched(SynchroniserContactGoogle::class);
    }
}
