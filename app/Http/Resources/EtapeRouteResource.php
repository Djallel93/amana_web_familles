<?php
// app/Http/Resources/EtapeRouteResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Arrêt d'une tournée — extrait le 10/09/2026 (Section E3 du refactor) de
 * LiveBoardController::routes(), qui chargeait `etapes.livraison.famille`
 * en restreignant déjà les colonnes de `famille` (`:id,nom,prenom,adresse`)
 * mais pas celles de `livraison` elle-même, dumpée en entier.
 *
 * `livraison` réduite à `id` + `famille` : seuls champs lus par
 * resources/js/components/livraison/tableau-de-bord/RoutesPanel.vue
 * (vérifié — aucun autre champ de Livraison n'est accédé sur `e.livraison`
 * dans ce composant ni dans BuildRouteFlow.vue). `null` si l'étape est un
 * retour QG (voir EtapeRoute.id_livraison, nullable).
 */
class EtapeRouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ordre' => $this->ordre,
            'statut' => $this->statut,
            'livraison' => $this->whenLoaded('livraison', fn () => $this->livraison ? [
                'id' => $this->livraison->id,
                'famille' => new FamilleResumeResource($this->livraison->famille),
            ] : null),
        ];
    }
}
