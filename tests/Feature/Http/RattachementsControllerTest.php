<?php
// tests/Feature/Http/RattachementsControllerTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Famille;
use App\Models\FamilleOrganisationDemande;
use App\Models\Organisation;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Phase 5 (opportunistic): the highest-value pick among the admin CRUD
 * controllers — this is the review step for the multi-org rattachement
 * flow whose CREATION side (FamilleUpsertService) Phase 2 already covers,
 * so the two together close the loop on that whole feature.
 */
class RattachementsControllerTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerRolesFamilles();
    }

    private function creerDemande(): FamilleOrganisationDemande
    {
        $organisation = Organisation::create(['code' => 'PARTENAIRE', 'nom' => 'Partenaire', 'actif' => true]);
        $famille = Famille::factory()->create();

        return FamilleOrganisationDemande::create([
            'id_famille' => $famille->id,
            'id_organisation' => $organisation->id,
            'source' => 'import',
        ]);
    }

    /**
     * FINDING while writing this fixture: FamilleOrganisationDemande's
     * $fillable is missing 'statut'/'traite_par'/'traite_le' — the exact
     * three fields FamilleOrganisationDemandeService::valider()/rejeter()
     * pass to $demande->update([...]). Eloquent silently drops
     * non-fillable attributes on mass assignment (no exception here,
     * since the app doesn't call Model::preventSilentlyDiscardingAttributes()),
     * so — as far as I can tell from the model source — those two service
     * methods currently persist NEITHER the status change NOR who/when
     * treated it; only the organisations pivot attach in valider() would
     * actually take effect. That's the two tests below
     * (test_valider_attache.../test_rejeter_marque...) — they assert the
     * INTENDED behavior and are expected to fail against current code
     * until 'statut'/'traite_par'/'traite_le' are added to $fillable (or
     * the service switches to forceFill()). Flagging rather than
     * "fixing" the assertions to match the bug — that would defeat the
     * point of writing these at all. This fixture helper uses direct
     * attribute assignment (bypasses the guard) purely for TEST SETUP,
     * not as a statement about what the real code path does.
     */
    private function marquerCommeTraitee(FamilleOrganisationDemande $demande, string $statut): void
    {
        $demande->statut = $statut;
        $demande->traite_par = 1;
        $demande->traite_le = now();
        $demande->save();
    }

    public function test_valider_attache_lorganisation_et_marque_la_demande_validee(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $demande = $this->creerDemande();

        $reponse = $this->actingAs($gestionnaire)->post(route('rattachements.valider', $demande));

        $reponse->assertRedirect();
        $demande->refresh();
        $this->assertSame('validee', $demande->statut);
        $this->assertSame($gestionnaire->id, $demande->traite_par);
        $this->assertNotNull($demande->traite_le);
        $this->assertTrue($demande->famille->fresh()->estRattacheeA($demande->id_organisation));
    }

    public function test_rejeter_marque_la_demande_rejetee_sans_attacher_lorganisation(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $demande = $this->creerDemande();

        $reponse = $this->actingAs($gestionnaire)->post(route('rattachements.rejeter', $demande));

        $reponse->assertRedirect();
        $demande->refresh();
        $this->assertSame('rejetee', $demande->statut);
        $this->assertFalse($demande->famille->fresh()->estRattacheeA($demande->id_organisation));
    }

    public function test_un_simple_membre_ne_peut_pas_acceder_a_la_liste_ni_valider(): void
    {
        $membre = $this->creerPersonne(['membre']);
        $demande = $this->creerDemande();

        $this->actingAs($membre)->get(route('rattachements.index'))->assertRedirect();
        $this->actingAs($membre)->post(route('rattachements.valider', $demande))->assertRedirect();

        // No side effect from the rejected request.
        $this->assertSame('en_attente', $demande->fresh()->statut);
    }

    public function test_admin_peut_aussi_valider_role_gestionnaire_couvre_admin_et_gestionnaire(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $demande = $this->creerDemande();

        $this->actingAs($admin)->post(route('rattachements.valider', $demande))->assertRedirect();

        $this->assertSame('validee', $demande->fresh()->statut);
    }

    public function test_index_ne_liste_que_les_demandes_en_attente(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $enAttente = $this->creerDemande();
        $dejaTraitee = $this->creerDemande();
        $this->marquerCommeTraitee($dejaTraitee, 'validee');

        $reponse = $this->actingAs($gestionnaire)->get(route('rattachements.index'));

        $reponse->assertOk();
        $demandes = $reponse->viewData('demandes');
        $this->assertTrue($demandes->contains('id', $enAttente->id));
        $this->assertFalse($demandes->contains('id', $dejaTraitee->id));
    }
}
