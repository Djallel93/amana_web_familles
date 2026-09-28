<?php
// app/Http/Resources/RouteLivraisonResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tournée — extrait le 10/09/2026 (Section E3 du refactor) de
 * LiveBoardController::routes(), qui renvoyait jusqu'ici
 * `response()->json($routes)` (dump brut, `benevole`/`vehiculeType` sans
 * restriction de colonnes — voir PersonneResumeResource/VehiculeTypeResource
 * pour le détail de ce qui était exposé en trop). Réutilisée telle quelle
 * pour le champ `route` de RouteIncidentResource.
 *
 * whenLoaded() sur benevole/vehiculeType/etapes plutôt qu'un accès direct :
 * RouteIncidentResource ne charge que `route.benevole` (voir
 * LiveBoardController::incidents()), pas vehiculeType ni etapes — cette
 * classe reste correcte dans les deux contextes plutôt que d'exiger un
 * chargement complet à chaque usage.
 */
class RouteLivraisonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_campagne' => $this->id_campagne,
            'statut' => $this->statut,
            'creneau' => $this->creneau,
            'benevole' => $this->whenLoaded('benevole', fn () => $this->benevole ? new PersonneResumeResource($this->benevole) : null),
            'vehicule_type' => $this->whenLoaded('vehiculeType', fn () => $this->vehiculeType ? new VehiculeTypeResource($this->vehiculeType) : null),
            'etapes' => $this->whenLoaded('etapes', fn () => EtapeRouteResource::collection($this->etapes)),
        ];
    }
}
