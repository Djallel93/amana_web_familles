<?php
// app/Notifications/InvitationFamillesDejaInscritNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Notification envoyée quand un admin accorde l'accès à AMANA Familles à
 * une personne qui possède déjà un compte (mot de passe déjà défini, par
 * exemple staff Planning) — pattern identique à
 * CandidatureValideeDejaInscritNotification de amana_web_planning.
 */
class InvitationFamillesDejaInscritNotification extends Notification
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
        Log::info('[InvitationFamillesDejaInscritNotification] Envoi email', [
            'destinataire' => $notifiable->email,
            'mailer' => config('mail.default'),
        ]);

        return $this->embedLogo(new MailMessage)
            ->subject('Votre accès AMANA Familles est activé')
            ->view('emails.invitation-familles-deja-inscrit', [
                'prenom' => $notifiable->prenom,
                'loginUrl' => $this->loginUrl,
                'logoCid' => $this->logoCid(),
            ]);
    }
}
