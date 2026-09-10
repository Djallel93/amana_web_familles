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
 *
 * Revu le 09/09/2026 (prompt de cette date §2.4) : jusque-là un même
 * MailMessage générique (->line()/->action(), pas le gabarit stylé
 * amana_shared) partait par email à TOUS les destinataires — équipe
 * chargement ET chauffeur. Désormais :
 *  - équipe chargement : notification web uniquement (reste sur l'app,
 *    n'a pas besoin d'un email en plus) ;
 *  - chauffeur : email (gabarit route-prete-a-charger, français,
 *    instructions concrètes) + notification web.
 * Le bouton "Voir l'écran chargement" est retiré partout : ni l'équipe
 * chargement (qui a déjà l'écran ouvert en pratique) ni le chauffeur (qui
 * n'y a pas accès, can:equipeChargement) n'en ont l'usage.
 */
class RoutePretePourChargementNotification extends Notification
{
    use EmbedsLogo;

    public string $severity = 'info';

    public function __construct(
        private readonly RouteLivraison $route,
    ) {
    }

    /**
     * Distingue le chauffeur (route.id_benevole) des autres destinataires
     * (équipe chargement) — voir le docblock de la classe.
     */
    private function estLeChauffeur(object $notifiable): bool
    {
        return $this->route->id_benevole !== null && $notifiable->id === $this->route->id_benevole;
    }

    public function via(object $notifiable): array
    {
        return $this->estLeChauffeur($notifiable) ? ['mail', 'amana-database'] : ['amana-database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->embedLogo(new MailMessage)
            ->subject('AMANA Livraison — Votre tournée est prête à charger')
            ->view('emails.route-prete-a-charger', [
                'prenom' => $notifiable->prenom ?? '',
                'numeroTournee' => $this->route->id,
                'logoCid' => $this->logoCid(),
            ]);
    }

    /**
     * Le lien vers l'écran chargement (equipe_chargement uniquement, voir
     * can:equipeChargement) n'a de sens que pour l'équipe chargement — pas
     * pour le chauffeur, qui n'y a pas accès (voir §2.4 : seul le bouton
     * DANS L'EMAIL a été retiré pour tout le monde, cette notification web
     * est un mécanisme distinct et l'équipe chargement, elle, a bien accès
     * à cet écran).
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'titre' => 'Colis prêts à charger',
            'message' => "Tournée #{$this->route->id} — tous les colis sont conditionnés.",
            'url' => $this->estLeChauffeur($notifiable) ? null : route('livraison.chargement.index', $this->route->id_campagne),
            'id_route' => $this->route->id,
        ];
    }
}
