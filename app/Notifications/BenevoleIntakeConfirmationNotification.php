<?php
// app/Notifications/BenevoleIntakeConfirmationNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use App\Models\BenevoleDemandeAttente;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Email de confirmation envoyé après la soumission du formulaire public de
 * candidature bénévole — miroir d'IntakeConfirmationNotification
 * (familles). Le compte/profil bénévole n'est créé qu'au clic sur le lien
 * (voir BenevoleIntakeConfirmationController::confirmer()), valable 48h.
 *
 * Envoyée via Notification::route('mail', $email)->notify(...) : à ce
 * stade il n'existe pas encore de Personne authentifiée à laquelle
 * attacher la notification.
 *
 * $token reçu EN CLAIR séparément de $demande depuis le 31/08/2026 (voir
 * App\Support\TokenHasher) : $demande->token ne contient plus que le
 * hash — voir BenevoleIntakeAttenteService::creerDemande().
 */
class BenevoleIntakeConfirmationNotification extends Notification
{
    use EmbedsLogo;

    private const SUJETS = [
        'fr' => 'AMANA — Merci de confirmer votre candidature bénévole',
        'ar' => 'AMANA — يرجى تأكيد ترشحكم للتطوع',
        'en' => 'AMANA — Please confirm your volunteer application',
    ];

    public function __construct(
        private readonly BenevoleDemandeAttente $demande,
        private readonly string $token,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $langue = in_array($this->demande->langue, ['fr', 'ar', 'en'], true) ? $this->demande->langue : 'fr';

        // Le hash (pas le jeton en clair) est loggé ici — voir
        // App\Support\TokenHasher.
        Log::info('[BenevoleIntakeConfirmationNotification] Envoi email', [
            'id_demande' => $this->demande->id,
        ]);

        return $this->embedLogo(new MailMessage)
            ->subject(self::SUJETS[$langue])
            ->view('emails.benevole-confirmation', [
                'prenom' => $this->demande->donnees['prenom'] ?? '',
                'langue' => $langue,
                'confirmUrl' => route('benevole.confirmer.show', $this->token),
                'logoCid' => $this->logoCid(),
            ]);
    }
}
