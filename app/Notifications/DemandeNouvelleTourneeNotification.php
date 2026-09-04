<?php
// app/Notifications/DemandeNouvelleTourneeNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use App\Models\RouteLivraison;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte admin/gestionnaire quand un bénévole confirme son retour au QG
 * et redevient disponible — voir MaRouteController::retourQg() (jusqu'au
 * 03/09/2026 : déclenchée par demanderNouvelleTournee(), un simple signal
 * "prêt à repartir" sans état persisté ; désormais déclenchée par le
 * retour effectif, avec BenevoleRetourQg comme état interrogeable — voir
 * ce modèle). Pertinent notamment pour zakat_el_fitr, où les tournées
 * sont générées progressivement par lots.
 *
 * Canal 'amana-database' en plus de 'mail' depuis le 03/09/2026 (voir
 * amana_shared/NotificationCenterService) : severity 'info' — utile à
 * savoir, pas une alerte à traiter, donc pas de bandeau rouge (voir
 * UrgentAlertBar.vue côté amana_shared_ui).
 */
class DemandeNouvelleTourneeNotification extends Notification
{
    use EmbedsLogo;

    public string $severity = 'info';

    public function __construct(
        private readonly RouteLivraison $route,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'amana-database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->embedLogo(new MailMessage)
            ->subject('AMANA Livraison — Un bénévole est de retour au QG et disponible')
            ->line("Le bénévole de la tournée #{$this->route->id} (campagne #{$this->route->id_campagne}) est de retour au QG et disponible pour une nouvelle tournée.")
            ->action('Voir le tableau de bord', route('livraison.tableau-de-bord.index'));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'titre' => 'Bénévole disponible',
            'message' => "Retour QG confirmé pour la tournée #{$this->route->id}.",
            'url' => route('livraison.tableau-de-bord.index'),
            'id_route' => $this->route->id,
        ];
    }
}
