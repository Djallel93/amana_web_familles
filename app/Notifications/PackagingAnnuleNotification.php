<?php
// app/Notifications/PackagingAnnuleNotification.php

declare(strict_types=1);

namespace App\Notifications;

use Amana\Shared\Notifications\Concerns\EmbedsLogo;
use App\Models\Livraison;
use App\Models\RouteLivraison;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Voir le prompt du 05/09/2026 §5.3 : l'équipe packaging a annulé un
 * conditionnement déjà marqué prêt sur une tournée déjà passée en
 * 'chargement' (voir PackagingController::annulerConditionnement()) — la
 * tournée redescend au statut 'packaging_annule' et un RouteIncident du
 * même nom est levé en parallèle (visible sur IncidentsPanel.vue).
 *
 * severity 'warning' (contrairement à RoutePretePourChargementNotification,
 * qui est une bonne nouvelle) : l'équipe chargement doit savoir qu'un
 * colis qu'elle pensait pouvoir charger ne l'est plus, pour éviter de le
 * chercher/charger par erreur.
 */
class PackagingAnnuleNotification extends Notification
{
    use EmbedsLogo;

    public string $severity = 'warning';

    public function __construct(
        private readonly RouteLivraison $route,
        private readonly Livraison $livraison,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'amana-database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $nomFamille = trim(($this->livraison->famille->prenom ?? '') . ' ' . ($this->livraison->famille->nom ?? ''));

        return $this->embedLogo(new MailMessage)
            ->subject('AMANA Livraison — Colis repris par le packaging')
            ->line("Le colis de la famille {$nomFamille} (tournée #{$this->route->id}) a été repris par l'équipe packaging pour correction.")
            ->line('Ce colis n\'est plus disponible au chargement pour le moment.')
            ->action('Voir l\'écran chargement', route('livraison.chargement.index', $this->route->id_campagne));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'titre' => 'Colis repris par le packaging',
            'message' => "Tournée #{$this->route->id} — un colis repart en préparation.",
            'url' => route('livraison.chargement.index', $this->route->id_campagne),
            'id_route' => $this->route->id,
        ];
    }
}
