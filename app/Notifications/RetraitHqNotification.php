<?php
// app/Notifications/RetraitHqNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use App\Models\Famille;
use App\Models\Livraison;
use App\Notifications\Concerns\EmbedsQrCode;
use App\Services\QrCodeService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Email envoyé aux familles se_deplace une fois leur créneau de retrait
 * QG planifié (voir App\Services\RetraitHqSchedulingService, seul
 * déclencheur — jamais envoyée individuellement) : où et quand venir, et
 * le QR code d'identification pour l'équipe chargement — voir le prompt
 * du 24/09/2026 §2/§Additional points 1 : "send emails to all families
 * with where and when to come. also send the qr code in the email."
 *
 * Même convention que LivraisonConfirmationNotification (seul autre
 * email adressé à une Famille, pas une Personne) : envoyée via
 * Notification::route('mail', $email)->notify(...), pas
 * $famille->notify(...) — voir App\Services\RetraitHqNotificationService,
 * seul appelant, qui applique le même garde-fou "pas d'email = repli
 * téléphonique" que ContactTokenService::emettrePour() (le repli
 * lui-même reste entièrement hors app, voir le prompt §6 : "purely
 * offline").
 *
 * Le QR encode une URL AUTHENTIFIÉE (espace équipe_chargement, voir
 * routes/livraison.php groupe livraison/retrait-hq) — même principe que
 * QrCodeService partout ailleurs dans ce domaine (étiquettes colis,
 * scan bénévole) : jamais un endpoint public.
 */
class RetraitHqNotification extends Notification
{
    use EmbedsLogo;
    use EmbedsQrCode;

    private const SUJETS = [
        'fr' => 'AMANA — Votre créneau de retrait au QG',
        'ar' => 'AMANA — موعد استلام الطرد من المقر',
        'en' => 'AMANA — Your pickup time at our office',
    ];

    public function __construct(
        private readonly Livraison $livraison,
        private readonly Famille $famille,
        private readonly QrCodeService $qrCode,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $langue = in_array($this->famille->langue, ['fr', 'ar', 'en'], true) ? $this->famille->langue : 'fr';

        Log::info('[RetraitHqNotification] Envoi email', ['id_livraison' => $this->livraison->id]);

        $svg = $this->qrCode->genererSvg(route('livraison.retrait-hq.livraisons.scan', $this->livraison));

        $campagne = $this->livraison->campagne;

        $message = $this->embedLogo(new MailMessage);
        $message = $this->embedQrCode($message, $svg, $this->livraison->id);

        return $message
            ->subject(self::SUJETS[$langue])
            ->view('emails.retrait-hq', [
                'prenom' => $this->famille->prenom,
                'langue' => $langue,
                'heureArrivee' => $this->livraison->heure_arrivee_prevue_hq,
                'hqAdresse' => $campagne->hq_adresse,
                'logoCid' => $this->logoCid(),
                'qrCid' => $this->qrCid($this->livraison->id),
            ]);
    }
}
