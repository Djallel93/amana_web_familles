<?php
// app/Http/Resources/QuartierResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Quartier — extrait le 12/09/2026 (Section E3 du refactor, suite) pour
 * FamilleEligibleResource. `id_secteur` (présent sur l'interface TS
 * Quartier) est VOLONTAIREMENT absent ici : CampagneDetail.vue et
 * BuildRouteFlow.vue (les deux seuls consommateurs de ce champ imbriqué)
 * ne lisent que `quartier.nom` sur la ligne d'une famille éligible/non
 * couverte — même raisonnement que PersonneResumeResource pour
 * id_vehicule_type.
 */
class QuartierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
        ];
    }
}
