<?php
// app/Services/RouteChargementService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Models\Personne;
use Amana\Shared\Services\NotificationCenterService;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Notifications\RoutePretePourChargementNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Bascule d'une tournée vers 'chargement' dès que TOUS ses colis sont prêts
 * — extraite de PackagingController::finaliserConditionnement() (01/10/2026)
 * pour pouvoir aussi être appelée à la CRÉATION d'une tournée.
 *
 * Bug corrigé : le conditionnement est découplé des tournées par
 * conception (une livraison peut être conditionnée avant d'avoir une
 * tournée, voir PackagingController). Or l'unique écrivain du statut
 * 'chargement' était le dernier colis coché : si tout était déjà prêt
 * AVANT la génération des tournées, plus rien ne déclenchait jamais la
 * bascule, les tournées restaient 'planifiee' pour toujours et l'écran
 * chargement (qui ne charge que chargement/charge/packaging_annule)
 * n'affichait aucune ligne.
 *
 * Appelée depuis : PackagingController (dernier colis coché),
 * RouteGenerationService / RouteMutationService (après création d'une
 * tournée) et la commande amana:livraison:promouvoir-tournees-pretes
 * (rattrapage des tournées déjà bloquées).
 */
class RouteChargementService
{
    public function __construct(
        private readonly NotificationCenterService $notificationCenter,
    ) {}

    /**
     * Vrai si la tournée a basculé en 'chargement' (et que l'équipe
     * chargement + le chauffeur ont été notifiés). Sans effet — et faux — si
     * le statut n'est ni 'planifiee' ni 'packaging_annule', si la tournée
     * n'a aucune étape, ou si au moins une livraison n'est pas encore
     * 'prete'.
     */
    public function promouvoirSiPrete(RouteLivraison $route): bool
    {
        if (!in_array($route->statut, ['planifiee', 'packaging_annule'], true)) {
            return false;
        }

        $route->load('etapes.livraison');

        if ($route->etapes->isEmpty()) {
            return false;
        }

        $toutesPretes = $route->etapes->every(
            fn($e) => $e->livraison === null || $e->livraison->statut_conditionnement === 'prete',
        );

        if (!$toutesPretes) {
            return false;
        }

        // 'packaging_annule' accepté en plus de 'planifiee' : une tournée
        // repassée là par annulerConditionnement() ne redevenait jamais
        // 'chargement' une fois ses colis re-conditionnés. Les
        // RouteIncident 'packaging_annule' ouverts sont résolus dans la
        // foulée — voir resoudreIncidentsPackagingAnnule().
        $etaitAnnulee = $route->statut === 'packaging_annule';

        $route->update(['statut' => 'chargement']);

        if ($etaitAnnulee) {
            $this->resoudreIncidentsPackagingAnnule($route);
        }

        // Campagne::personnesAvecRole() plutôt que Personne::avecRole() :
        // rôle par affectation de campagne, pas rôle global — voir son
        // docblock.
        $destinataires = $route->campagne->personnesAvecRole('equipe_chargement');

        if ($route->id_benevole) {
            $chauffeur = Personne::find($route->id_benevole);
            if ($chauffeur) {
                $destinataires->push($chauffeur);
            }
        }

        Notification::send($destinataires, new RoutePretePourChargementNotification($route));

        return true;
    }

    /**
     * Une tournée re-conditionnée après une annulation n'a plus de raison de
     * garder son incident 'packaging_annule' ouvert — mêmes deux gestes que
     * la résolution manuelle (LiveBoardController::resoudreIncident()) :
     * statut 'resolu' + retrait de la notification urgente correspondante.
     */
    private function resoudreIncidentsPackagingAnnule(RouteLivraison $route): void
    {
        RouteIncident::ouverts()
            ->where('id_route', $route->id)
            ->where('type', 'packaging_annule')
            ->get()
            ->each(function (RouteIncident $incident) {
                $incident->update(['statut' => 'resolu']);
                $this->notificationCenter->resoudreParDonnee('id_incident', $incident->id);
            });
    }
}
