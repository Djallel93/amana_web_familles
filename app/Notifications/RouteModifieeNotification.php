<?php
// app/Notifications/RouteModifieeNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte bénévole après une mutation de tournée (ajout/retrait/
 * réassignation/scission/tournée personnalisée) — voir le prompt du
 * 30/08/2026 §3.3 : "Any such change must notify the affected driver(s)
 * and admin/gestionnaire." Le message précis vient de
 * RouteMutationService, qui connaît le type exact de mutation.
 */
class RouteModifieeNotification extends Notification
{
    use EmbedsLogo;

    public function __construct(
        private readonly string $message,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->embedLogo(new MailMessage)
            ->subject('AMANA Livraison — Votre tournée a été modifiée')
            ->line($this->message)
            ->action('Voir ma tournée', route('livraison.benevole.ma-route.show'));
    }
}
