<?php
// app/Notifications/NouvelleCandidatureBenevoleNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Models\BenevoleProfil;
use Amana\Shared\Models\Personne;
use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Notification envoyée à tout le staff familles (admin + gestionnaire)
 * quand une candidature bénévole est confirmée — miroir de
 * NouvelleDemandeFamilleNotification / NouveauMembreNotification
 * (planning). Pas de ShouldQueue — même raisonnement que
 * NouveauMembreNotification (pas de worker de queue sur IONOS).
 */
class NouvelleCandidatureBenevoleNotification extends Notification
{
    use EmbedsLogo;

    public function __construct(
        private readonly BenevoleProfil $profil,
        private readonly Personne $candidat,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        Log::info('[NouvelleCandidatureBenevoleNotification] Envoi email', [
            'destinataire' => $notifiable->email,
            'candidat_id' => $this->candidat->id,
        ]);

        return $this->embedLogo(new MailMessage)
            ->subject('Nouvelle candidature bénévole — ' . $this->candidat->prenom . ' ' . strtoupper($this->candidat->nom))
            ->view('emails.nouvelle-candidature-benevole', [
                'adminPrenom' => $notifiable->prenom,
                'candidat' => $this->candidat,
                'profil' => $this->profil,
                'urlValidation' => route('admin.benevoles.index'),
                'logoCid' => $this->logoCid(),
            ]);
    }
}
