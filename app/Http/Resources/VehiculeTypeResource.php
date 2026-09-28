<?php
// app/Http/Resources/VehiculeTypeResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Type de véhicule (ref_vehicules, connexion `commun`) — extrait le
 * 10/09/2026 (Section E3 du refactor) de
 * LiveBoardController::routes(). Mêmes champs que l'interface TS
 * VehiculeType.
 */
class VehiculeTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'capacite_kg' => $this->capacite_kg,
            'nombre_part_max' => $this->nombre_part_max,
        ];
    }
}
