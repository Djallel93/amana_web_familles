<?php
// app/Notifications/RouteIncidentNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use App\Models\RouteIncident;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte admin/gestionnaire pour un RouteIncident — voir
 * RouteIncident::booted() (déclenchée automatiquement à la création d'un
 * incident, quel que soit le contrôleur qui l'a levé — ChargementController
 * ou MaRouteController, voir le prompt du 03/09/2026).
 *
 * severity = 'urgent' pour tout type actionnable (benevole_absent,
 * capacite, livraison_ignoree) — bandeau rouge plein écran côté
 * UrgentAlertBar.vue tant que non résolu (voir
 * LiveBoardController::resoudreIncident(), qui appelle
 * NotificationCenterService::resoudreParDonnee('id_incident', ...)).
 * 'chargement_termine' reste 'info' : jalon, pas une alerte à traiter
 * (voir RouteIncident::TYPES_SANS_STATUT).
 */
class RouteIncidentNotification extends Notification
{
    use EmbedsLogo;

    public string $severity;

    public function __construct(
        private readonly RouteIncident $incident,
    ) {
        $this->severity = in_array($incident->type, RouteIncident::TYPES_SANS_STATUT, true) ? 'info' : 'urgent';
    }

    public function via(object $notifiable): array
    {
        return $this->severity === 'urgent' ? ['mail', 'amana-database'] : ['amana-database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->embedLogo(new MailMessage)
            ->subject('AMANA Livraison — Incident : ' . $this->libelleType())
            ->line("Un incident « {$this->libelleType()} » a été signalé sur la tournée #{$this->incident->id_route}.")
            ->when($this->incident->notes, fn ($m) => $m->line("Notes : {$this->incident->notes}"))
            ->action('Voir le tableau de bord', route('livraison.tableau-de-bord.index'));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'titre' => $this->libelleType(),
            'message' => "Tournée #{$this->incident->id_route}" . ($this->incident->notes ? " — {$this->incident->notes}" : ''),
            'url' => route('livraison.tableau-de-bord.index'),
            'id_incident' => $this->incident->id,
            'id_route' => $this->incident->id_route,
        ];
    }

    private function libelleType(): string
    {
        return match ($this->incident->type) {
            'benevole_absent' => 'Bénévole absent',
            'capacite' => 'Problème de capacité',
            'chargement_termine' => 'Chargement terminé',
            'livraison_ignoree' => 'Livraison ignorée',
            default => $this->incident->type,
        };
    }
}
