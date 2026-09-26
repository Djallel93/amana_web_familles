<?php
// tests/Feature/Http/VerificationsControllerTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Famille;
use App\Models\FamilleVerification;
use App\Notifications\FamilleVerificationNotification;
use App\Support\TokenHasher;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Phase 5 (opportunistic): the batch-send eligibility rules in
 * FamilleVerificationService::envoyerParLot() are exactly the kind of
 * "quietly skips the wrong family" risk worth a regression net — tested
 * here at the HTTP level (envoyer()) since that's the only way staff
 * actually triggers this.
 */
class VerificationsControllerTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
    }

    public function test_envoyer_envoie_a_une_famille_validee_avec_email_et_sans_verification_existante(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé', 'email' => 'famille@example.fr']);

        $this->actingAs($admin)->post(route('admin.verifications.envoyer'))->assertRedirect();

        $this->assertSame(1, FamilleVerification::where('id_famille', $famille->id)->count());
        Notification::assertSentTo($famille, FamilleVerificationNotification::class);
    }

    public function test_envoyer_ignore_une_famille_sans_email(): void
    {
        $admin = $this->creerPersonne(['admin']);
        Famille::factory()->create(['etat_dossier' => 'Validé', 'email' => null]);

        $this->actingAs($admin)->post(route('admin.verifications.envoyer'))->assertRedirect();

        $this->assertSame(0, FamilleVerification::count());
    }

    public function test_envoyer_ignore_une_famille_non_validee(): void
    {
        $admin = $this->creerPersonne(['admin']);
        Famille::factory()->create(['etat_dossier' => 'En cours', 'email' => 'famille@example.fr']);

        $this->actingAs($admin)->post(route('admin.verifications.envoyer'))->assertRedirect();

        $this->assertSame(0, FamilleVerification::count());
    }

    public function test_envoyer_ignore_une_famille_avec_une_verification_non_expiree_en_cours(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé', 'email' => 'famille@example.fr']);
        FamilleVerification::create([
            'id_famille' => $famille->id,
            'token' => TokenHasher::hash('un-token'),
            'expires_at' => now()->addDays(3), // still valid
        ]);

        $this->actingAs($admin)->post(route('admin.verifications.envoyer'))->assertRedirect();

        // Still just the one (pre-existing) row — no new send.
        $this->assertSame(1, FamilleVerification::where('id_famille', $famille->id)->count());
        Notification::assertNotSentTo($famille, FamilleVerificationNotification::class);
    }

    public function test_envoyer_renvoie_a_une_famille_dont_la_verification_precedente_a_expire(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé', 'email' => 'famille@example.fr']);
        FamilleVerification::create([
            'id_famille' => $famille->id,
            'token' => TokenHasher::hash('un-vieux-token'),
            'expires_at' => now()->subDay(), // expired
        ]);

        $this->actingAs($admin)->post(route('admin.verifications.envoyer'))->assertRedirect();

        $this->assertSame(2, FamilleVerification::where('id_famille', $famille->id)->count());
    }

    public function test_envoyer_ignore_une_famille_confirmee_il_y_a_moins_de_90_jours(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé', 'email' => 'famille@example.fr']);
        FamilleVerification::create([
            'id_famille' => $famille->id,
            'token' => TokenHasher::hash('un-token'),
            'expires_at' => now()->subDays(80), // itself expired...
            'confirmed_at' => now()->subDays(30), // ...but confirmed recently, so still skipped
        ]);

        $this->actingAs($admin)->post(route('admin.verifications.envoyer'))->assertRedirect();

        $this->assertSame(1, FamilleVerification::where('id_famille', $famille->id)->count());
    }

    public function test_envoyer_renvoie_a_une_famille_confirmee_il_y_a_plus_de_90_jours(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé', 'email' => 'famille@example.fr']);
        FamilleVerification::create([
            'id_famille' => $famille->id,
            'token' => TokenHasher::hash('un-vieux-token'),
            'expires_at' => now()->subDays(100),
            'confirmed_at' => now()->subDays(91),
        ]);

        $this->actingAs($admin)->post(route('admin.verifications.envoyer'))->assertRedirect();

        $this->assertSame(2, FamilleVerification::where('id_famille', $famille->id)->count());
    }

    public function test_envoyer_traite_un_lot_mixte_et_totalise_correctement(): void
    {
        $admin = $this->creerPersonne(['admin']);
        Famille::factory()->create(['etat_dossier' => 'Validé', 'email' => 'a@example.fr']); // envoyé
        $enCours = Famille::factory()->create(['etat_dossier' => 'Validé', 'email' => 'b@example.fr']); // ignoré
        FamilleVerification::create(['id_famille' => $enCours->id, 'token' => TokenHasher::hash('t'), 'expires_at' => now()->addDays(3)]);
        Famille::factory()->create(['etat_dossier' => 'Validé', 'email' => null]); // ignoré (pas d'email — filtré en amont, hors du tally)

        $this->actingAs($admin)->post(route('admin.verifications.envoyer'));

        $this->assertSame(1, FamilleVerification::where('id_famille', '!=', $enCours->id)->count());
    }
}
