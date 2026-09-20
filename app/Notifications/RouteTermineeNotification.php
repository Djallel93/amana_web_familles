<?php
// app/Notifications/RouteTermineeNotification.php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\RouteLivraison;
use Illuminate\Notifications\Notification;

/**
 * "Tournée terminée" — admin/gestionnaire sont prévenus dès que le
 * bénévole marque sa tournée 'livraisons_terminees' (bouton "Livraison
 * terminé", voir MaRouteController::livraisonTerminee()), sans attendre
 * (ni dépendre de) son "Retour QG" : c'est l'objectif explicite de ce
 * premier bouton de fin de tournée (voir son docblock — "This way if
 * they do not return they have a way to notify that route is done").
 *
 * Déclenchée par RouteLivraison::booted() (changement de statut vers
 * 'livraisons_terminees'), pas par le contrôleur : même principe que
 * RouteIncident::booted() — aucun futur point de bascule ne peut
 * l'oublier, et un second appel sans changement de statut n'en envoie pas
 * de doublon.
 *
 * severity 'info' et canal 'amana-database' seul (pas d'email, pas de
 * bandeau rouge, voir UrgentAlertBar.vue) : jalon attendu du déroulé
 * normal, pas une alerte. Volontairement UNE notification par tournée et
 * non une par arrêt livré : le centre de notifications n'affiche que les
 * 30 'info' les plus récentes par personne (voir NotificationCenterService::
 * pourPersonne()) — une notification par arrêt noierait tout le reste en
 * quelques minutes. L'avancement arrêt par arrêt est visible en direct
 * sur le Suivi livraison (voir LiveBoard.vue, rafraîchi par polling).
 */
class RouteTermineeNotification extends Notification
{
    public string $severity = 'info';

    private readonly int $livrees;

    private readonly int $ignorees;

    private readonly int $total;

    private readonly string $nomBenevole;

    /**
     * Compteurs calculés UNE fois ici plutôt que dans toDatabase() : ce
     * dernier est appelé une fois par destinataire (voir
     * AmanaDatabaseChannel::send()).
     */
    public function __construct(
        private readonly RouteLivraison $route,
    ) {
        // "Retour QG" (id_livraison null) n'est pas un arrêt famille — même
        // convention que RouteLivraison::toutesEtapesTraitees().
        $parStatut = $route->etapes()
            ->whereNotNull('id_livraison')
            ->selectRaw('statut, count(*) as n')
            ->groupBy('statut')
            ->pluck('n', 'statut');

        $this->livrees = (int) ($parStatut['livree'] ?? 0);
        $this->ignorees = (int) ($parStatut['ignoree'] ?? 0);
        $this->total = (int) $parStatut->sum();

        $benevole = $route->benevole;
        $this->nomBenevole = $benevole ? trim("{$benevole->prenom} {$benevole->nom}") : '';
    }

    public function via(object $notifiable): array
    {
        return ['amana-database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $resume = "{$this->livrees} livrée(s)"
            . ($this->ignorees > 0 ? ", {$this->ignorees} ignorée(s)" : '')
            . " sur {$this->total} arrêt(s)";

        return [
            'titre' => 'Tournée terminée',
            'message' => "Tournée #{$this->route->id}"
                . ($this->nomBenevole !== '' ? " ({$this->nomBenevole})" : '')
                . " — {$resume}.",
            // Présélectionne la campagne sur le Suivi livraison (voir
            // LiveBoardController::index(?Campagne)).
            'url' => route('livraison.suivi-livraison.index', $this->route->id_campagne),
            'id_route' => $this->route->id,
            'id_campagne' => $this->route->id_campagne,
        ];
    }
}
