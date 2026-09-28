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
 *
 * $token reçu EN CLAIR séparément de $demande depuis le 31/08/2026 (voir
 * App\Support\TokenHasher) : $demande->token ne contient plus que le hash,
 * impropre à construire l'URL de confirmation — voir
 * IntakeAttenteService::creerDemande().
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

        // Le hash (pas le jeton en clair) est loggé ici : voir
        // App\Support\TokenHasher — un jeton en clair n'a rien à faire
        // dans un journal applicatif, même celui de la demande à laquelle
        // il appartient.
        Log::info('[IntakeConfirmationNotification] Envoi email', [
            'id_demande' => $this->demande->id,
        ]);

        return $this->embedLogo(new MailMessage)
            ->subject(self::SUJETS[$langue])
            ->view('emails.intake-confirmation', [
                'prenom' => $this->demande->donnees['prenom'] ?? '',
                'langue' => $langue,
                'confirmUrl' => route('intake.confirmer.show', $this->token),
                'logoCid' => $this->logoCid(),
            ]);
    }
}
