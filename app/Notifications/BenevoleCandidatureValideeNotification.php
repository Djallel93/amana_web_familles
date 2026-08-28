<?php
// app/Notifications/BenevoleCandidatureValideeNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Notification envoyée au bénévole quand un admin valide sa candidature,
 * pour un compte qui n'a pas encore de mot de passe — miroir de
 * CandidatureValideeNotification (planning) / InvitationFamillesNotification
 * (familles, staff).
 */
class BenevoleCandidatureValideeNotification extends Notification
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
        Log::info('[BenevoleCandidatureValideeNotification] Envoi email', [
            'destinataire' => $notifiable->email,
            'mailer' => config('mail.default'),
        ]);

        return $this->embedLogo(new MailMessage)
            ->subject('Votre candidature bénévole AMANA est validée — Créez votre mot de passe')
            ->view('emails.benevole-candidature-validee', [
                'prenom' => $notifiable->prenom,
                'resetUrl' => $this->resetUrl,
                'logoCid' => $this->logoCid(),
            ]);
    }
}
