<?php
// tests/Feature/Http/FamilleLockingTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Famille;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * The locking mechanism (FamillesController::show()/prendreVerrou()/
 * deverrouiller()/forcerDeverrouillage()/renouvelerVerrou()) is the one
 * piece of FamillesController the Phase 4 brief calls out specifically —
 * this file focuses there. index()/nouvelles()/update() are NOT covered
 * here (out of scope for this file — would need their own test given
 * their size); see the accompanying summary for that scoping call.
 *
 * Route group for these endpoints is `Route::middleware('auth')` with NO
 * role gate (routes/familles.php: "Dossiers familles — staff, tous
 * rôles") except forcerDeverrouillage(), which is behind role:admin — see
 * that route's own comment.
 */
class FamilleLockingTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerRolesFamilles();
    }

    // ── show() — prise de verrou ────────────────────────────────────────

    public function test_show_prend_le_verrou_et_capture_letat_dossier_dorigine(): void
    {
        $personne = $this->creerPersonne(['membre']);
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé']);

        $reponse = $this->actingAs($personne)->getJson(route('familles.show', $famille->id));

        $reponse->assertOk();
        $famille->refresh();
        $this->assertSame($personne->id, $famille->locked_by);
        $this->assertNotNull($famille->locked_at);
        $this->assertSame('Validé', $famille->etat_dossier_avant_verrouillage);
        // Persisted state flips to 'En cours' while the panel is open...
        $this->assertSame('En cours', $famille->etat_dossier);
    }

    /**
     * ...but the JSON the panel actually renders shows the ORIGINAL
     * selectable status, not the internal 'En cours' toggle (docblock:
     * the <select> must stay preselected correctly).
     */
    public function test_show_renvoie_le_vrai_statut_dorigine_pas_en_cours(): void
    {
        $personne = $this->creerPersonne(['membre']);
        $famille = Famille::factory()->create(['etat_dossier' => 'Rejeté']);

        $reponse = $this->actingAs($personne)->getJson(route('familles.show', $famille->id));

        $reponse->assertOk()->assertJsonPath('etat_dossier', 'Rejeté');
    }

    /**
     * 'Recu' isn't in ETATS_SELECTIONNABLES — falls back to 'En attente'
     * in the returned JSON specifically (not persisted as such).
     */
    public function test_show_replie_recu_sur_en_attente_dans_la_reponse_json(): void
    {
        $personne = $this->creerPersonne(['membre']);
        $famille = Famille::factory()->create(['etat_dossier' => 'Recu']);

        $reponse = $this->actingAs($personne)->getJson(route('familles.show', $famille->id));

        $reponse->assertOk()->assertJsonPath('etat_dossier', 'En attente');
        // The captured "before" value must still be the real 'Recu', so a
        // deverrouiller() without saving reverts to 'Recu', not 'En attente'.
        $this->assertSame('Recu', $famille->fresh()->etat_dossier_avant_verrouillage);
    }

    public function test_reouverture_par_le_meme_utilisateur_necrase_pas_letat_dorigine_deja_capture(): void
    {
        $personne = $this->creerPersonne(['membre']);
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé']);

        $this->actingAs($personne)->getJson(route('familles.show', $famille->id))->assertOk();
        // Reopen (e.g. page reload) — etat_dossier is now 'En cours' in DB.
        $this->actingAs($personne)->getJson(route('familles.show', $famille->id))->assertOk();

        // Still 'Validé', not overwritten to 'En cours' by the second pass.
        $this->assertSame('Validé', $famille->fresh()->etat_dossier_avant_verrouillage);
    }

    public function test_verrou_frais_dun_autre_utilisateur_refuse_louverture_avec_423(): void
    {
        $premier = $this->creerPersonne(['membre']);
        $second = $this->creerPersonne(['membre']);
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé']);
        $this->actingAs($premier)->getJson(route('familles.show', $famille->id))->assertOk();

        $reponse = $this->actingAs($second)->getJson(route('familles.show', $famille->id));

        $reponse->assertStatus(423)
            ->assertJsonPath('error', 'verrouille')
            ->assertJsonPath('peut_forcer', false);
    }

    public function test_peut_forcer_est_vrai_dans_la_reponse_423_pour_un_admin(): void
    {
        $premier = $this->creerPersonne(['membre']);
        $admin = $this->creerPersonne(['admin']);
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé']);
        $this->actingAs($premier)->getJson(route('familles.show', $famille->id))->assertOk();

        $reponse = $this->actingAs($admin)->getJson(route('familles.show', $famille->id));

        $reponse->assertStatus(423)->assertJsonPath('peut_forcer', true);
    }

    public function test_verrou_perime_est_traverse_normalement(): void
    {
        $premier = $this->creerPersonne(['membre']);
        $second = $this->creerPersonne(['membre']);
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé']);
        $this->actingAs($premier)->getJson(route('familles.show', $famille->id))->assertOk();

        // Simulate a lock older than Famille::VERROU_TTL_MINUTES (20).
        $famille->refresh();
        $famille->locked_at = now()->subMinutes(21);
        $famille->saveQuietly();

        $reponse = $this->actingAs($second)->getJson(route('familles.show', $famille->id));

        $reponse->assertOk();
        $this->assertSame($second->id, $famille->fresh()->locked_by);
    }

    // ── deverrouiller() ──────────────────────────────────────────────────

    public function test_deverrouiller_restaure_letat_dossier_et_libere_le_verrou(): void
    {
        $personne = $this->creerPersonne(['membre']);
        $famille = Famille::factory()->create(['etat_dossier' => 'Rejeté']);
        $this->actingAs($personne)->getJson(route('familles.show', $famille->id))->assertOk();

        $reponse = $this->actingAs($personne)->postJson(route('familles.deverrouiller', $famille->id));

        $reponse->assertOk()->assertJsonPath('ok', true);
        $famille->refresh();
        $this->assertSame('Rejeté', $famille->etat_dossier);
        $this->assertNull($famille->locked_by);
        $this->assertNull($famille->locked_at);
        $this->assertNull($famille->etat_dossier_avant_verrouillage);
    }

    /**
     * Docblock: "un appel tardif ne doit jamais libérer le verrou de
     * quelqu'un d'autre" — a late deverrouiller() from a PREVIOUS holder
     * must be a no-op once someone else has taken over the lock.
     */
    public function test_deverrouiller_par_un_non_detenteur_ne_touche_a_rien(): void
    {
        $premier = $this->creerPersonne(['membre']);
        $second = $this->creerPersonne(['membre']);
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé']);
        $this->actingAs($premier)->getJson(route('familles.show', $famille->id))->assertOk();
        // Second takes over after the first's lock expires.
        $famille->refresh();
        $famille->locked_at = now()->subMinutes(21);
        $famille->saveQuietly();
        $this->actingAs($second)->getJson(route('familles.show', $famille->id))->assertOk();

        // The FIRST user's (now stale) deverrouiller() call arrives late.
        $reponse = $this->actingAs($premier)->postJson(route('familles.deverrouiller', $famille->id));

        $reponse->assertOk()->assertJsonPath('message', 'Rien à faire.');
        $this->assertSame($second->id, $famille->fresh()->locked_by);
    }

    // ── forcerDeverrouillage() ───────────────────────────────────────────

    /**
     * EnsureRole (amana_shared) redirects on a role failure — even for a
     * fetch()-style JSON request, it doesn't special-case
     * expectsJson()/wantsJson() (see its own source) — so this asserts a
     * redirect, NOT a 403/422 JSON error the frontend might expect.
     */
    public function test_forcer_deverrouillage_est_reserve_aux_admins(): void
    {
        $premier = $this->creerPersonne(['membre']);
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé']);
        $this->actingAs($premier)->getJson(route('familles.show', $famille->id))->assertOk();

        $reponse = $this->actingAs($gestionnaire)
            ->postJson(route('familles.forcer-deverrouillage', $famille->id));

        $reponse->assertRedirect();

        // The lock must still be intact — the rejected request had no effect.
        $this->assertSame($premier->id, $famille->fresh()->locked_by);
    }

    public function test_admin_peut_forcer_le_deverrouillage_dun_verrou_dautrui(): void
    {
        $premier = $this->creerPersonne(['membre']);
        $admin = $this->creerPersonne(['admin']);
        $famille = Famille::factory()->create(['etat_dossier' => 'Rejeté']);
        $this->actingAs($premier)->getJson(route('familles.show', $famille->id))->assertOk();

        $reponse = $this->actingAs($admin)->postJson(route('familles.forcer-deverrouillage', $famille->id));

        $reponse->assertOk()->assertJsonPath('ok', true);
        $famille->refresh();
        $this->assertNull($famille->locked_by);
        $this->assertSame('Rejeté', $famille->etat_dossier);
    }
}
