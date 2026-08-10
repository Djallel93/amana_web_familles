<?php
// app/Notifications/NouvelleDemandeFamilleNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use App\Models\Famille;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Notifie le staff (admin + gestionnaire) à chaque nouvelle soumission du
 * formulaire public d'intake — avant même la résolution géographique
 * asynchrone, qui peut échouer (voir ResoudreAdresseFamille) sans que
 * personne n'en soit informé jusqu'ici. Demande du 09/08/2026 : "staff
 * should at least be aware that there was a submission".
 *
 * Envoyée à TOUTE nouvelle famille (etat_dossier = 'Recu'), pas seulement
 * en cas de problème de géocodage — ce dernier cas est distinct et se
 * traite via Famille::probleme_traitement, affiché dans la vue "Nouvelles
 * demandes" plutôt que par une notification séparée (décision du
 * 09/08/2026 : éviter une deuxième notification qui ferait doublon).
 */
class NouvelleDemandeFamilleNotification extends Notification
{
    use EmbedsLogo;

    public function __construct(
        private readonly Famille $famille,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        Log::info('[NouvelleDemandeFamilleNotification] Envoi email', [
            'destinataire' => $notifiable->email,
            'id_famille' => $this->famille->id,
            'mailer' => config('mail.default'),
        ]);

        return $this->embedLogo(new MailMessage)
            ->subject("Nouvelle demande d'aide — {$this->famille->prenom} {$this->famille->nom}")
            ->view('emails.nouvelle-demande-famille', [
                'destinatairePrenom' => $notifiable->prenom,
                'famille' => $this->famille,
                'dossierUrl' => route('familles.nouvelles'),
                'logoCid' => $this->logoCid(),
            ]);
    }
}
