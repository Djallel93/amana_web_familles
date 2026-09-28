<?php
// app/Http/Resources/CampagneJourneeResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Journée d'une campagne — extrait le 12/09/2026 (Section E3 du refactor,
 * suite) de CampagnesController::ajouterJournee(), qui renvoyait jusqu'ici
 * `response()->json(['success' => true, 'journee' => $journee])` en dump
 * brut. Reprend exactement l'interface TS CampagneJournee
 * (id/id_campagne/date/label/ordre) — CampagneDetail.vue pousse
 * directement l'objet reçu dans sa liste `journees` (le sélecteur de
 * journée), qui a besoin de la forme complète, pas d'un sous-ensemble.
 *
 * Réutilisée telle quelle pour `journees` dans CampagneResource.
 */
class CampagneJourneeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_campagne' => $this->id_campagne,
            'date' => $this->date,
            'label' => $this->label,
            'ordre' => $this->ordre,
        ];
    }
}
