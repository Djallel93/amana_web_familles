<?php
// app/Notifications/BenevoleCandidatureValideeDejaInscritNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Notification envoyée au bénévole quand un admin valide sa candidature,
 * MAIS que le compte (ref_personnes) possède déjà un mot de passe — ex :
 * la personne est déjà membre du staff Familles, ou bénévole/membre côté
 * Planning. Miroir de CandidatureValideeDejaInscritNotification (planning).
 */
class BenevoleCandidatureValideeDejaInscritNotification extends Notification
{
    use EmbedsLogo;

    public function __construct(
        private readonly string $loginUrl
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        Log::info('[BenevoleCandidatureValideeDejaInscritNotification] Envoi email', [
            'destinataire' => $notifiable->email,
            'mailer' => config('mail.default'),
        ]);

        return $this->embedLogo(new MailMessage)
            ->subject('Votre candidature bénévole AMANA est validée')
            ->view('emails.benevole-candidature-validee-deja-inscrit', [
                'prenom' => $notifiable->prenom,
                'loginUrl' => $this->loginUrl,
                'logoCid' => $this->logoCid(),
            ]);
    }
}
