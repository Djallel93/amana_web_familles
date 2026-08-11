<?php
// app/Notifications/IntakeConfirmationNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use App\Models\IntakeDemandeAttente;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Email de confirmation envoyé après la soumission du formulaire public
 * d'intake (ajout du 11/08/2026) — le dossier Famille n'est créé qu'au clic
 * sur le lien contenu ici (voir IntakeConfirmationController::confirmer()),
 * pas à la soumission elle-même. Valable 48h (IntakeAttenteService::
 * DUREE_VALIDITE_HEURES).
 *
 * Envoyée via Notification::route('mail', $email)->notify(...) plutôt que
 * $famille->notify(...) : à ce stade il n'existe pas encore de ligne
 * Famille (ni de Personne authentifiée) à laquelle attacher la
 * notification — voir IntakeController::store().
 */
class IntakeConfirmationNotification extends Notification
{
    use EmbedsLogo;

    private const SUJETS = [
        'fr' => 'AMANA — Merci de confirmer votre demande',
        'ar' => 'AMANA — يرجى تأكيد طلبكم',
        'en' => 'AMANA — Please confirm your request',
    ];

    public function __construct(
        private readonly IntakeDemandeAttente $demande,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $langue = in_array($this->demande->langue, ['fr', 'ar', 'en'], true) ? $this->demande->langue : 'fr';

        Log::info('[IntakeConfirmationNotification] Envoi email', [
            'token' => $this->demande->token,
        ]);

        return $this->embedLogo(new MailMessage)
            ->subject(self::SUJETS[$langue])
            ->view('emails.intake-confirmation', [
                'prenom' => $this->demande->donnees['prenom'] ?? '',
                'langue' => $langue,
                'confirmUrl' => route('intake.confirmer.show', $this->demande->token),
                'logoCid' => $this->logoCid(),
            ]);
    }
}
