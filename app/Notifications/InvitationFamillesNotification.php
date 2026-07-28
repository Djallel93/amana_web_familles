<?php
// app/Notifications/InvitationFamillesNotification.php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\EmbedsLogo;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Notification envoyée à un membre du staff quand un admin lui accorde
 * l'accès à AMANA Familles, ET que le compte n'a pas encore de mot de passe
 * (pattern identique à CandidatureValideeNotification de amana_web_planning).
 */
class InvitationFamillesNotification extends Notification
{
    use EmbedsLogo;

    public function __construct(
        private readonly string $resetUrl
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        Log::info('[InvitationFamillesNotification] Envoi email', [
            'destinataire' => $notifiable->email,
            'mailer' => config('mail.default'),
        ]);

        return $this->embedLogo(new MailMessage)
            ->subject('Bienvenue sur AMANA Familles — Créez votre mot de passe')
            ->view('emails.invitation-familles', [
                'prenom' => $notifiable->prenom,
                'resetUrl' => $this->resetUrl,
                'logoCid' => $this->logoCid(),
            ]);
    }
}
