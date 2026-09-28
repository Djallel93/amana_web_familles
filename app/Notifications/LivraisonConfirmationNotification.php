<?php
// app/Notifications/LivraisonConfirmationNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use App\Models\Famille;
use App\Models\Livraison;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Email de confirmation famille pour une livraison — voir le prompt du
 * 30/08/2026 §3.1 : lien vers le formulaire public (adresse, membres du
 * foyer, creneaux_disponibles), uniquement pour les familles disposant
 * d'un email (voir App\Services\ContactTokenService, seul appelant).
 *
 * Envoyée via Notification::route('mail', $email)->notify(...), pas
 * $famille->notify(...) : même raison que les 3 flux existants (voir
 * IntakeConfirmationNotification) — le jeton, pas la famille
 * authentifiée, porte le contrôle d'accès ici.
 *
 * $token reçu EN CLAIR en paramètre séparé (jamais lu depuis une colonne
 * hachée) — même convention que les 3 flux existants depuis la
 * correction du 31/08/2026, voir App\Support\TokenHasher.
 */
class LivraisonConfirmationNotification extends Notification
{
    use EmbedsLogo;

    private const SUJETS = [
        'fr' => 'AMANA — Merci de confirmer votre disponibilité',
        'ar' => 'AMANA — يرجى تأكيد توفركم',
        'en' => 'AMANA — Please confirm your availability',
    ];

    public function __construct(
        private readonly Livraison $livraison,
        private readonly Famille $famille,
        private readonly string $token,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $langue = in_array($this->famille->langue, ['fr', 'ar', 'en'], true) ? $this->famille->langue : 'fr';

        Log::info('[LivraisonConfirmationNotification] Envoi email', [
            'id_livraison' => $this->livraison->id,
        ]);

        return $this->embedLogo(new MailMessage)
            ->subject(self::SUJETS[$langue])
            ->view('emails.livraison-confirmation', [
                'prenom' => $this->famille->prenom,
                'langue' => $langue,
                'confirmUrl' => route('livraison.confirmation.show', $this->token),
                'logoCid' => $this->logoCid(),
            ]);
    }
}
