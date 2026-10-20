<?php
// app/Services/RetourQgService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Models\Personne;
use Amana\Shared\Services\NotificationCenterService;
use App\Models\BenevoleRetourQg;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Notifications\DemandeNouvelleTourneeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Réponse du chauffeur à « Revenez-vous au QG ? » (18/10/2026) — logique extraite de
 * MaRouteController::retourQg() pour être partagée avec « Remettre dans le pool » (gestionnaire/admin).
 *
 *  - Oui : tournée 'terminee', chauffeur de nouveau disponible (BenevoleRetourQg), retour_qg = true.
 *    Les incidents « chauffeur indisponible » encore ouverts sur SES autres tournées sont résolus.
 *  - Non : la tournée reste 'livraisons_terminees', retour_qg = false — le chauffeur sort du pool des
 *    chauffeurs disponibles (RouteGenerationService). S'il avait déjà une tournée pas encore partie dans la
 *    campagne (retenu alors qu'il était encore en livraison), un incident 'chauffeur_indisponible' est levé
 *    par tournée concernée : à résoudre par réassignation ou répartition (IncidentResolutionService).
 *
 * Un « Non » n'est pas définitif : le chauffeur peut répondre « Oui » plus tard, ou un gestionnaire le remet dans
 * le pool (remettreDansLePool()) — dans les deux cas c'est enregistrer($route, true).
 */
class RetourQgService
{
    public function __construct(private readonly NotificationCenterService $notificationCenter) {}

    /**
     * @throws \RuntimeException message prêt à afficher quand la réponse est refusée
     */
    public function enregistrer(RouteLivraison $route, bool $retour, ?int $parId = null): void
    {
        if ($route->statut !== 'livraisons_terminees') {
            throw new \RuntimeException("La livraison n'est pas encore marquée terminée.");
        }

        if ($retour) {
            $this->confirmerRetour($route);

            return;
        }

        $this->declarerNonRetour($route, $parId);
    }

    /**
     * Remet dans le pool un chauffeur ayant répondu « Non » (gestionnaire/admin). Sans effet utile si la tournée
     * n'est pas un « Non » : refusé pour ne pas clôturer à tort une tournée sans réponse.
     *
     * @throws \RuntimeException
     */
    public function remettreDansLePool(RouteLivraison $route): void
    {
        if ($route->retour_qg !== false) {
            throw new \RuntimeException("Ce chauffeur n'a pas répondu « Non » au retour au QG.");
        }

        $this->enregistrer($route, true);
    }

    private function confirmerRetour(RouteLivraison $route): void
    {
        DB::transaction(function () use ($route) {
            $route->update(['statut' => 'terminee', 'retour_qg' => true, 'retour_qg_le' => now()]);

            BenevoleRetourQg::create([
                'id_campagne' => $route->id_campagne,
                // Le chauffeur de la tournée, pas l'utilisateur connecté : un admin qui clôt la tournée à sa
                // place ne devient pas « disponible pour le prochain lot ».
                'id_personne' => $route->id_benevole,
                'id_route_origine' => $route->id,
                'disponible_depuis' => now(),
            ]);

            $this->resoudreIncidentsChauffeur($route);
        });

        $destinataires = Personne::adminsDe()->orWhere(fn($q) => $q->avecRole('gestionnaire'))->get();
        Notification::send($destinataires, new DemandeNouvelleTourneeNotification($route));
    }

    private function declarerNonRetour(RouteLivraison $route, ?int $parId): void
    {
        DB::transaction(function () use ($route, $parId) {
            $route->update(['retour_qg' => false, 'retour_qg_le' => now()]);

            $aRisque = RouteLivraison::where('id_campagne', $route->id_campagne)
                ->where('id_benevole', $route->id_benevole)
                ->where('id', '!=', $route->id)
                ->whereIn('statut', RouteLivraison::STATUTS_PAS_PARTIE)
                ->get();

            foreach ($aRisque as $autre) {
                $existe = RouteIncident::where('id_route', $autre->id)
                    ->where('type', 'chauffeur_indisponible')
                    ->where('statut', 'ouvert')
                    ->exists();
                if ($existe) {
                    continue;
                }

                RouteIncident::create([
                    'id_route' => $autre->id,
                    'id_campagne' => $autre->id_campagne,
                    'type' => 'chauffeur_indisponible',
                    'signale_par' => $parId ?? $route->id_benevole,
                    'statut' => 'ouvert',
                    'notes' => "Le chauffeur a répondu « Non » au retour au QG à la fin de la tournée #{$route->id}.",
                ]);
            }
        });
    }

    private function resoudreIncidentsChauffeur(RouteLivraison $route): void
    {
        $incidents = RouteIncident::ouverts()
            ->where('type', 'chauffeur_indisponible')
            ->whereHas('route', fn($q) => $q
                ->where('id_campagne', $route->id_campagne)
                ->where('id_benevole', $route->id_benevole))
            ->get();

        foreach ($incidents as $incident) {
            $incident->update([
                'statut' => 'resolu',
                'notes' => trim(($incident->notes ?? '') . "\n[Résolu] Le chauffeur est de retour au QG."),
            ]);
            $this->notificationCenter->resoudreParDonnee('id_incident', $incident->id);
        }
    }
}
