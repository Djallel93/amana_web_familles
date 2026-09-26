<?php
// tests/Feature/Http/GoogleContactsControllerTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Phase 5 (opportunistic), deliberately narrow scope: redirect() and the
 * early-return branches of callback() only. `Google\Client::createAuthUrl()`
 * builds a URL locally from config (client_id/secret/redirect_uri) with no
 * network call, so redirect() is safe to test as-is even without real
 * Google OAuth credentials configured.
 *
 * NOT covered here: callback()'s actual token exchange
 * ($client->fetchAccessTokenWithAuthCode($code)) and the "missing
 * refresh_token" branch after it — both require a REAL request to
 * Google's OAuth token endpoint, which needs a fake/mocked Google\Client
 * bound into the container to test properly (this app doesn't currently
 * inject one — GoogleContactsService::createClient() always constructs a
 * real Google\Client). Building that seam is a reasonable follow-up but
 * felt like more than "opportunistic" scope for this pass; flagging
 * rather than skipping silently, same as the earlier Vitest gap.
 */
class GoogleContactsControllerTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerRolesFamilles();
    }

    public function test_redirect_renvoie_vers_lecran_de_consentement_google(): void
    {
        $admin = $this->creerPersonne(['admin']);

        $reponse = $this->actingAs($admin)->get(route('admin.google-contacts.authorize'));

        $reponse->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $reponse->headers->get('Location'));
    }

    public function test_callback_redirige_avec_une_erreur_quand_google_renvoie_un_refus(): void
    {
        $admin = $this->creerPersonne(['admin']);

        $reponse = $this->actingAs($admin)->get(route('admin.google-contacts.callback', ['error' => 'access_denied']));

        $reponse->assertRedirect(route('admin.activite.index'));
        $this->assertStringContainsString('access_denied', session('error'));
    }

    public function test_callback_redirige_avec_une_erreur_quand_le_code_est_absent(): void
    {
        $admin = $this->creerPersonne(['admin']);

        $reponse = $this->actingAs($admin)->get(route('admin.google-contacts.callback'));

        $reponse->assertRedirect(route('admin.activite.index'));
        $this->assertNotNull(session('error'));
    }

    public function test_un_gestionnaire_ne_peut_pas_declencher_lautorisation_google(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);

        $this->actingAs($gestionnaire)
            ->get(route('admin.google-contacts.authorize'))
            ->assertRedirect(); // role:admin — redirects, doesn't 403 (and never reaches Google either way)
    }
}
