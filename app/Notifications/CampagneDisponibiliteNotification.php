<?php
// app/Notifications/CampagneDisponibiliteNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Models\BenevoleProfil;
use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use App\Models\Campagne;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email envoyé à chaque bénévole (BenevoleProfil statut='Validé') au
 * lancement d'une campagne — voir le prompt du 30/08/2026 §3.2 : pointe
 * vers la page de disponibilité EN APP (pas de lien à jeton — la personne
 * est déjà authentifiée). Envoyée via $personne->notify(...) directement
 * (Personne utilise Notifiable, voir Amana\Shared\Models\Personne),
 * contrairement aux 3 flux à jeton de cette app qui utilisent
 * Notification::route('mail', ...) faute de compte existant à ce stade.
 *
 * $profil reçu séparément de $notifiable (qui sera la Personne) : la
 * langue préférée (langue_preferee) vit sur BenevoleProfil, pas sur
 * Personne — voir Amana\Shared\Models\BenevoleProfil.
 */
class CampagneDisponibiliteNotification extends Notification
{
    use EmbedsLogo;

    public function __construct(
        private readonly Campagne $campagne,
        private readonly BenevoleProfil $profil,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $langue = in_array($this->profil->langue_preferee, ['fr', 'ar', 'en'], true)
            ? $this->profil->langue_preferee
            : 'fr';

        return $this->embedLogo(new MailMessage)
            ->subject('AMANA — Confirmez votre disponibilité pour la prochaine campagne')
            ->view('emails.campagne-disponibilite', [
                'prenom' => $notifiable->prenom ?? '',
                'langue' => $langue,
                'disponibiliteUrl' => route('livraison.benevole.disponibilite.show', $this->campagne),
                'logoCid' => $this->logoCid(),
            ]);
    }
}
