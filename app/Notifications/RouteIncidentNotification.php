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
        // Gabarit stylé amana_shared (29/09/2026, prompt de cette date §5.3) —
        // remplace le MailMessage Laravel par défaut (->line()->action()),
        // même convention que RouteChargeeNotification.
        return $this->embedLogo(new MailMessage())
            ->subject('AMANA Livraison — Incident : ' . $this->libelleType())
            ->view('emails.route-incident', [
                'prenom' => $notifiable->prenom ?? '',
                'libelle' => $this->libelleType(),
                'numeroTournee' => $this->incident->id_route,
                'notes' => $this->incident->notes,
                'suiviUrl' => $this->urlIncident(),
                'logoCid' => $this->logoCid(),
            ]);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'titre' => $this->libelleType(),
            // 23/10/2026 : plus de « Tournée # » quand l'incident n'a pas de tournée (types de campagne
            // ou de famille), et le lien mène au hub de la campagne, seul emplacement des incidents.
            'message' => trim(($this->incident->id_route ? "Tournée #{$this->incident->id_route}" : '')
                . ($this->incident->notes ? ($this->incident->id_route ? ' — ' : '') . $this->incident->notes : '')),
            'url' => $this->urlIncident(),
            'id_incident' => $this->incident->id,
            'id_route' => $this->incident->id_route,
        ];
    }

    private function libelleType(): string
    {
        return RouteIncident::LABELS_TYPE[$this->incident->type] ?? $this->incident->type;
    }

    /** Hub de la campagne (section Incidents) ; repli sur le suivi livraison si la campagne est inconnue. */
    private function urlIncident(): string
    {
        $idCampagne = $this->incident->idCampagneEffectif();

        return $idCampagne
            ? route('livraison.campagnes.show', $idCampagne)
            : route('livraison.suivi-livraison.index');
    }
}
