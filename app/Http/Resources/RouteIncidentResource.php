<?php
// app/Http/Resources/RouteIncidentResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Incident de tournée — extrait le 10/09/2026 (Section E3 du refactor) de
 * LiveBoardController::incidents(), qui renvoyait jusqu'ici
 * `response()->json($incidents)` (dump brut). Charge `route.benevole`
 * (pas vehiculeType/etapes) et `livraison.famille:id,nom,prenom` — voir
 * RouteLivraisonResource, qui reste correcte même sans vehiculeType/
 * etapes chargées.
 */
class RouteIncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'statut' => $this->statut,
            'route' => $this->whenLoaded('route', fn () => $this->route ? new RouteLivraisonResource($this->route) : null),
            'livraison' => $this->whenLoaded('livraison', fn () => $this->livraison ? [
                'id' => $this->livraison->id,
                'famille' => new FamilleResumeResource($this->livraison->famille),
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
