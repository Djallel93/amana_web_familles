<?php
// app/Notifications/FamilleVerificationNotification.php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\FamilleVerification;
use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Email de vérification périodique des informations d'une famille —
 * remplace generateVerificationEmailHtml()/sendVerificationEmail()
 * (emailVerificationService.js, amana_familles). Contenu multilingue selon
 * famille.langue (fr/ar/en, RTL pour l'arabe), thème AMANA terracotta
 * (partials communs à l'app, pas le template HTML basique bleu d'origine).
 *
 * $token reçu EN CLAIR séparément de $verification depuis le 31/08/2026
 * (voir App\Support\TokenHasher) : $verification->token ne contient plus
 * que le hash, impropre à construire l'URL de confirmation — voir
 * FamilleVerificationService::envoyerPourFamille().
 */
class FamilleVerificationNotification extends Notification
{
    use EmbedsLogo;

    private const SUJETS = [
        'fr' => 'AMANA — Merci de vérifier vos informations',
        'ar' => 'AMANA — يرجى التحقق من معلوماتك',
        'en' => 'AMANA — Please verify your information',
    ];

    public function __construct(
        private readonly FamilleVerification $verification,
        private readonly string $token,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $langue = in_array($notifiable->langue, ['fr', 'ar', 'en'], true) ? $notifiable->langue : 'fr';

        // Le hash (pas le jeton en clair) est loggé ici — voir
        // App\Support\TokenHasher.
        Log::info('[FamilleVerificationNotification] Envoi email', [
            'destinataire' => $notifiable->email,
            'id_famille' => $notifiable->id,
        ]);

        return $this->embedLogo(new MailMessage)
            ->subject(self::SUJETS[$langue])
            ->view('emails.verification-famille', [
                'famille' => $notifiable,
                'langue' => $langue,
                'confirmUrl' => route('verification.show', $this->token),
                'updateUrl' => route('intake.show', ['langue' => $langue]),
                'logoCid' => $this->logoCid(),
            ]);
    }
}
