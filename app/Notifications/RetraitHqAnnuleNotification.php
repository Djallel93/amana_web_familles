<?php
// app/Notifications/RetraitHqAnnuleNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use App\Models\Famille;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email « votre retrait au QG est annulé » (06/10/2026) — envoyé à une
 * famille qui avait un rendez-vous de retrait et repasse en livraison à
 * domicile (voir App\Services\LivraisonChangementService::changerSeDeplace()).
 * Même convention que RetraitHqNotification : adressée à une Famille via
 * Notification::route('mail', ...), envoyée par RetraitHqNotificationService.
 */
class RetraitHqAnnuleNotification extends Notification
{
    use EmbedsLogo;

    private const SUJETS = [
        'fr' => 'AMANA — Votre retrait au QG est annulé',
        'ar' => 'AMANA — تم إلغاء موعد الاستلام من المقر',
        'en' => 'AMANA — Your pickup at our office is cancelled',
    ];

    public function __construct(
        private readonly Famille $famille,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $langue = in_array($this->famille->langue, ['fr', 'ar', 'en'], true) ? $this->famille->langue : 'fr';

        return $this->embedLogo(new MailMessage())
            ->subject(self::SUJETS[$langue])
            ->view('emails.retrait-hq-annule', [
                'prenom' => $this->famille->prenom,
                'langue' => $langue,
                'logoCid' => $this->logoCid(),
            ]);
    }
}
