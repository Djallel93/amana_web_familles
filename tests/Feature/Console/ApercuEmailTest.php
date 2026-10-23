<?php
// tests/Feature/Console/ApercuEmailTest.php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Support\ApercuEmail\ApercuEmail;
use App\Support\ApercuEmail\CatalogueEmails;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 23/10/2026 — outil d'aperçu des emails (`familles:apercu-email`, docs/emails.md).
 *
 * Notification::fake() n'appelle jamais toMail() : une faute dans un gabarit ou un nom de route
 * ne se voit qu'au rendu réel. Ces tests rendent donc VRAIMENT chaque email du catalogue — c'est
 * aussi ce qui garantit qu'un nouvel email ajouté à l'application finit dans l'outil.
 */
class ApercuEmailTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dossier = sys_get_temp_dir() . '/apercu-email-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dossier);
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function emails(): array
    {
        $cas = [];
        foreach ((new CatalogueEmails())->cles() as $cle) {
            $cas[$cle] = [$cle];
        }

        return $cas;
    }

    #[DataProvider('emails')]
    public function test_chaque_email_du_catalogue_se_rend(string $cle): void
    {
        $rendu = app(ApercuEmail::class)->rendre($cle);

        $this->assertNotSame('', $rendu['sujet']);
        $this->assertStringContainsString('<html', strtolower($rendu['html']));
        $this->assertStringNotContainsString('cid:', $rendu['html'], 'chaque cid: doit être remplacé par une URI data: dans l\'aperçu');
    }

    public function test_le_catalogue_couvre_toutes_les_notifications_a_gabarit_de_l_application(): void
    {
        $couvertes = array_column((new CatalogueEmails())->entrees(), 'classe');

        foreach (glob(app_path('Notifications/*Notification.php')) as $fichier) {
            $classe = 'App\\Notifications\\' . basename($fichier, '.php');
            $source = (string) file_get_contents($fichier);

            // RouteTerminee : canal base de données uniquement, aucun email.
            if (!str_contains($source, 'toMail')) {
                continue;
            }

            $this->assertContains($classe, $couvertes, "{$classe} envoie un email mais n'est pas dans CatalogueEmails.");
        }
    }

    public function test_le_qr_code_et_le_logo_sont_integres_a_l_apercu_du_retrait_qg(): void
    {
        $html = app(ApercuEmail::class)->rendre('retrait-hq')['html'];

        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }

    public function test_un_email_multilingue_se_rend_dans_la_langue_demandee(): void
    {
        $arabe = app(ApercuEmail::class)->rendre('retrait-hq', 'ar');
        $anglais = app(ApercuEmail::class)->rendre('retrait-hq', 'en');

        $this->assertSame('AMANA — موعد استلام الطرد من المقر', $arabe['sujet']);
        $this->assertStringContainsString('dir="rtl"', $arabe['html']);
        $this->assertSame('AMANA — Your pickup time at our office', $anglais['sujet']);
        $this->assertStringNotContainsString('dir="rtl"', $anglais['html']);
    }

    public function test_une_langue_inconnue_retombe_sur_le_francais(): void
    {
        $rendu = app(ApercuEmail::class)->rendre('retrait-hq', 'xx');

        $this->assertSame('AMANA — Votre créneau de retrait au QG', $rendu['sujet']);
    }

    public function test_une_cle_inconnue_est_refusee_avec_la_liste_des_cles(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retrait-hq');

        app(ApercuEmail::class)->rendre('nexiste-pas');
    }

    // ── La commande ─────────────────────────────────────────────────────

    public function test_la_commande_sans_argument_liste_les_emails(): void
    {
        $this->assertSame(0, Artisan::call('familles:apercu-email'));

        $sortie = Artisan::output();
        foreach ((new CatalogueEmails())->cles() as $cle) {
            $this->assertStringContainsString($cle, $sortie);
        }
        $this->assertStringContainsString('emails.retrait-hq', $sortie);
        $this->assertStringContainsString('--envoyer --a=', $sortie);
    }

    public function test_la_commande_ecrit_un_fichier_html_autonome(): void
    {
        $this->artisan('familles:apercu-email', ['email' => 'retrait-hq', '--langue' => 'ar', '--sortie' => $this->dossier])
            ->expectsOutputToContain('retrait-hq (ar)')
            ->assertSuccessful();

        $fichier = $this->dossier . '/retrait-hq.ar.html';
        $this->assertFileExists($fichier);
        $this->assertStringContainsString('dir="rtl"', (string) file_get_contents($fichier));
    }

    public function test_la_commande_tous_rend_chaque_email_du_catalogue(): void
    {
        $this->artisan('familles:apercu-email', ['email' => 'tous', '--sortie' => $this->dossier])
            ->assertSuccessful();

        foreach ((new CatalogueEmails())->cles() as $cle) {
            $this->assertFileExists("{$this->dossier}/{$cle}.fr.html");
        }
    }

    public function test_la_commande_refuse_une_cle_ou_une_langue_invalide(): void
    {
        $this->artisan('familles:apercu-email', ['email' => 'nexiste-pas', '--sortie' => $this->dossier])->assertFailed();
        $this->artisan('familles:apercu-email', ['email' => 'retrait-hq', '--langue' => 'de', '--sortie' => $this->dossier])->assertFailed();
    }

    public function test_envoyer_remet_l_email_au_transport_et_en_exige_l_adresse(): void
    {
        $this->artisan('familles:apercu-email', ['email' => 'retrait-hq', '--envoyer' => true])->assertFailed();
        $this->artisan('familles:apercu-email', ['email' => 'retrait-hq', '--envoyer' => true, '--a' => 'pas-une-adresse'])->assertFailed();
        $this->artisan('familles:apercu-email', ['email' => 'tous', '--envoyer' => true, '--a' => 'moi@exemple.fr'])->assertFailed();
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());

        $this->artisan('familles:apercu-email', ['email' => 'retrait-hq', '--envoyer' => true, '--a' => 'moi@exemple.fr'])
            ->expectsOutputToContain('Envoyé à moi@exemple.fr')
            ->assertSuccessful();

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('moi@exemple.fr', $messages[0]->getEnvelope()->getRecipients()[0]->getAddress());
    }

    public function test_envoyer_fonctionne_aussi_pour_un_email_a_destinataire_compte(): void
    {
        $this->artisan('familles:apercu-email', ['email' => 'route-incident', '--envoyer' => true, '--a' => 'admin@exemple.fr'])
            ->assertSuccessful();

        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
        // Canal mail seulement : aucune notification n'est écrite en base.
        $this->assertSame(0, \Amana\Shared\Models\Notification::query()->count());
    }
}
