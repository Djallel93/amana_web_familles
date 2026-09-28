<?php
// app/Notifications/RouteChargeeNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use App\Models\RouteLivraison;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Ajoutée le 09/09/2026 (prompt de cette date §2.5) : jusque-là,
 * ChargementController::confirmer() (tournée passée à statut = 'charge',
 * chargement terminé) ne notifiait personne — seul un RouteIncident type
 * 'chargement_termine' était créé pour la traçabilité. Chauffeur
 * uniquement (contrairement à RoutePretePourChargementNotification qui
 * touche aussi l'équipe chargement) : c'est lui qui part en tournée, gabarit
 * stylé amana_shared (même convention que route-prete-a-charger.blade.php),
 * pointant vers sa propre page de tournée (livraison.benevole.ma-route.show
 * — même route que RouteModifieeNotification utilise déjà pour la même
 * raison).
 */
class RouteChargeeNotification extends Notification
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
            ->subject('AMANA Livraison — Votre tournée est chargée, en route !')
            ->view('emails.route-chargee', [
                'prenom' => $notifiable->prenom ?? '',
                'numeroTournee' => $this->route->id,
                'maRouteUrl' => route('livraison.benevole.ma-route.show'),
                'logoCid' => $this->logoCid(),
            ]);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'titre' => 'Tournée chargée',
            'message' => "Tournée #{$this->route->id} — chargement terminé, vous pouvez partir.",
            'url' => route('livraison.benevole.ma-route.show'),
            'id_route' => $this->route->id,
        ];
    }
}
