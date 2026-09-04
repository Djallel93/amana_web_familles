<?php
// app/Notifications/RoutePretePourChargementNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use App\Models\RouteLivraison;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Every time packaging finishes a delivery, loading team and driver are
 * notified" — voir le prompt du 03/09/2026 §2.9. Déclenchée depuis
 * PackagingController::marquerPret() quand TOUTES les livraisons d'une
 * tournée passent à statut_conditionnement = 'prete' (bascule
 * automatique de la tournée en 'chargement').
 *
 * severity 'info' (pas de bandeau rouge, voir UrgentAlertBar.vue) : c'est
 * une bonne nouvelle attendue dans le cours normal de la campagne, pas un
 * incident — à la différence de RouteIncidentNotification.
 */
class RoutePretePourChargementNotification extends Notification
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
            ->subject('AMANA Livraison — Colis prêts à charger')
            ->line("Tous les colis de la tournée #{$this->route->id} sont conditionnés et prêts à être chargés.")
            ->action('Voir l\'écran chargement', route('livraison.chargement.index', $this->route->id_campagne));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'titre' => 'Colis prêts à charger',
            'message' => "Tournée #{$this->route->id} — tous les colis sont conditionnés.",
            'url' => route('livraison.chargement.index', $this->route->id_campagne),
            'id_route' => $this->route->id,
        ];
    }
}
