<?php
// app/Notifications/DemandeNouvelleTourneeNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use App\Models\RouteLivraison;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte admin/gestionnaire quand un bénévole signale qu'il a terminé sa
 * tournée et est prêt à en reprendre une — voir
 * MaRouteController::demanderNouvelleTournee() pour le contexte complet
 * (pertinent notamment pour les lots progressifs de zakat_el_fitr).
 */
class DemandeNouvelleTourneeNotification extends Notification
{
    use EmbedsLogo;

    public function __construct(
        private readonly RouteLivraison $route,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->embedLogo(new MailMessage)
            ->subject('AMANA Livraison — Un bénévole est prêt pour une nouvelle tournée')
            ->line("Le bénévole de la tournée #{$this->route->id} (campagne #{$this->route->id_campagne}) a terminé sa tournée et est disponible pour en reprendre une.")
            ->action('Voir le tableau de bord', route('livraison.tableau-de-bord.index'));
    }
}
