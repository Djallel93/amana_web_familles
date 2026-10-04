<?php
// app/Services/IncidentResolutionService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Campagne;
use App\Models\RouteIncident;

/**
 * Résolution / fermeture des incidents de tournée (03/10/2026) — logique
 * extraite de LiveBoardController::resoudreIncident() pour être partagée par
 * Suivi livraison (panneau des incidents ouverts), la page Incidents de la
 * campagne et la clôture de campagne.
 *
 * Trois sorties d'un incident ouvert, toutes retirent sa notification :
 *  - resoudre()  → 'resolu' ; pour 'benevole_absent', relance d'abord le
 *    clustering des arrêts orphelins (comportement historique) ;
 *  - ignorer()   → 'ignore' (« Fermé (ignoré) »), AUCUN effet de bord ;
 *  - forcerResolutionPourCampagne() → 'resolu' pour TOUS les incidents
 *    ouverts d'une campagne, sans effet de bord (pas de re-clustering : on
 *    ferme une campagne, on ne génère pas de nouvelles tournées).
 *
 * Un incident résolu ou ignoré n'est jamais rouvert (décision du
 * 03/10/2026) : si le problème revient, un nouvel incident est ouvert.
 */
class IncidentResolutionService
{
    public function __construct(
        private readonly RouteGenerationService $generationService,
        private readonly NotificationCenterService $notificationCenter,
    ) {
    }

    /**
     * @return array<string, mixed> Résultat du re-clustering pour benevole_absent, [] sinon
     */
    public function resoudre(RouteIncident $incident): array
    {
        if ($incident->type === 'benevole_absent') {
            // Les arrêts encore en_attente de la tournée de ce bénévole
            // sont les livraisons « orphelines » à replacer ailleurs.
            $idsLivraisonsOrphelines = $incident->route->etapes()
                ->where('statut', 'en_attente')
                ->pluck('id_livraison')
                ->filter()
                ->all();

            $resultat = $this->generationService->relancerPourLivraisonsOrphelines(
                $incident->route->campagne,
                $idsLivraisonsOrphelines,
                $incident->route->id_benevole,
            );

            $incident->update([
                'statut' => 'resolu',
                'notes' => trim(($incident->notes ?? '') . "\n[Re-cluster] " . json_encode($resultat)),
            ]);
            $this->notificationCenter->resoudreParDonnee('id_incident', $incident->id);

            return $resultat;
        }

        $incident->update(['statut' => 'resolu']);
        $this->notificationCenter->resoudreParDonnee('id_incident', $incident->id);

        return [];
    }

    public function ignorer(RouteIncident $incident): void
    {
        $incident->update(['statut' => 'ignore']);
        $this->notificationCenter->resoudreParDonnee('id_incident', $incident->id);
    }

    /**
     * @return int Nombre d'incidents fermés
     */
    public function forcerResolutionPourCampagne(Campagne $campagne): int
    {
        $incidents = RouteIncident::ouverts()
            ->whereHas('route', fn ($q) => $q->where('id_campagne', $campagne->id))
            ->get();

        foreach ($incidents as $incident) {
            $incident->update([
                'statut' => 'resolu',
                'notes' => trim(($incident->notes ?? '') . "\n[Résolu de force à la clôture de la campagne]"),
            ]);
            $this->notificationCenter->resoudreParDonnee('id_incident', $incident->id);
        }

        return $incidents->count();
    }
}
