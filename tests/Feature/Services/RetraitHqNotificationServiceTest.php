<?php
// tests/Feature/Services/RetraitHqNotificationServiceTest.php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Models\Famille;
use App\Models\Livraison;
use App\Notifications\RetraitHqNotification;
use App\Services\QrCodeService;
use App\Services\RetraitHqNotificationService;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Email de retrait QG — rendu RÉEL (10/10/2026).
 *
 * Les autres tests de ce flux font Notification::fake() : toMail() n'y est jamais appelé, ce qui
 * a laissé passer un nom de route inexistant (« livraison.retrait-hq.livraisons.scan ») pour le QR
 * code — RouteNotFoundException avalée par RetraitHqNotificationService, aucun email envoyé en
 * dev. Ici la notification n'est PAS simulée : le mail est construit, rendu avec les gabarits
 * partagés (amana-shared) et remis au transport « array ».
 */
class RetraitHqNotificationServiceTest extends TestCase
{
    use SeedsCommunFixtures;

    private Campagne $campagne;

    private CampagneJournee $journee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerRolesFamilles();

        $this->campagne = Campagne::create([
            'type' => 'zakat_el_fitr',
            'statut' => 'en_cours',
            'date_livraison' => '2026-11-10',
            'poids_moyen_kg' => 5,
            'hq_latitude' => 0.0,
            'hq_longitude' => 0.0,
            'hq_adresse' => '1 rue du Quartier Général',
        ]);
        $this->journee = $this->campagne->ajouterJournee('2026-11-10');
    }

    private function livraisonPlanifiee(array $surcharge = [], array $famille = []): Livraison
    {
        $famille = Famille::factory()->create(array_merge(['email' => 'f' . uniqid() . '@amana-test.fr', 'langue' => 'fr'], $famille));

        return Livraison::create(array_merge([
            'id_famille' => $famille->id,
            'id_campagne' => $this->campagne->id,
            'id_campagne_journee' => $this->journee->id,
            'statut' => 'non_assignee',
            'statut_conditionnement' => 'en_attente',
            'nombre_personnes' => 2,
            'poids_kg' => 10.0,
            'statut_contact' => 'confirme',
            'se_deplace' => true,
            'heure_arrivee_prevue_hq' => '2026-11-10 09:30:00',
        ], $surcharge));
    }

    public function test_le_mail_de_retrait_se_construit_avec_un_qr_vers_une_route_existante(): void
    {
        $livraison = $this->livraisonPlanifiee();

        $mail = (new RetraitHqNotification($livraison, $livraison->famille, new QrCodeService()))
            ->toMail($livraison->famille);
        $html = (string) $mail->render();

        $this->assertSame('AMANA — Votre créneau de retrait au QG', $mail->subject);
        $this->assertStringContainsString('1 rue du Quartier Général', $html);
        $this->assertStringContainsString('cid:qr-retrait-hq-' . $livraison->id . '@amana-familles', $html);
        // Gabarits partagés (amana-shared), comme les autres emails.
        $this->assertStringContainsString('closing-text', $html);
    }

    public function test_envoyer_pour_remet_reellement_le_mail_au_transport(): void
    {
        $livraison = $this->livraisonPlanifiee();

        $resultat = app(RetraitHqNotificationService::class)->envoyerPour($livraison);

        $this->assertSame(RetraitHqNotificationService::ENVOYE, $resultat);
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_envoyer_pour_signale_une_famille_sans_email(): void
    {
        $livraison = $this->livraisonPlanifiee(famille: ['email' => null]);

        $resultat = app(RetraitHqNotificationService::class)->envoyerPour($livraison);

        $this->assertSame(RetraitHqNotificationService::SANS_EMAIL, $resultat);
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_envoyer_pour_signale_un_rendez_vous_non_planifie(): void
    {
        $livraison = $this->livraisonPlanifiee(['heure_arrivee_prevue_hq' => null]);

        $this->assertSame(
            RetraitHqNotificationService::NON_PLANIFIE,
            app(RetraitHqNotificationService::class)->envoyerPour($livraison),
        );
    }
}
