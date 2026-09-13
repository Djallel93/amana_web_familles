<?php
// app/Http/Resources/CampagnePoidsMoyenHistoriqueResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une ligne d'historique de modification de poids moyen — extrait le
 * 12/09/2026 (Section E3 du refactor, suite) de
 * CampagnesController::mettreAJourPoidsMoyen(), consommé par le
 * <script> vanilla JS de packaging.blade.php (pas par le reste de l'app
 * Vue — cet endpoint n'est appelé que depuis cet écran-là, voir
 * enregistrerPoidsMoyen()) : `.type`, `.ancienne_valeur`,
 * `.nouvelle_valeur`, `.horodatage`, `.logge_par?.prenom`/`.nom` y sont
 * tous lus. `id_campagne` (colonne réelle du modèle) n'est en revanche lu
 * nulle part côté template et est donc omis.
 *
 * `logge_par` : la requête de mettreAJourPoidsMoyen() charge toujours
 * `loggePar:id,nom,prenom` (aucun contexte où ce resource serait utilisé
 * sans ce eager load aujourd'hui), mais whenLoaded() reste plus sûr qu'un
 * accès direct — même précaution que RouteLivraisonResource pour
 * benevole/vehiculeType. Le template lit systématiquement
 * `logge_par?.prenom`/`.nom` (jamais l'entier brut), cohérent avec
 * l'union `number | PersonneResume` de l'interface TS
 * CampagnePoidsMoyenHistorique : ce resource ne pose donc jamais l'entier
 * brut en repli, whenLoaded() renvoie simplement le champ absent si la
 * relation n'est pas chargée.
 */
class CampagnePoidsMoyenHistoriqueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'ancienne_valeur' => $this->ancienne_valeur,
            'nouvelle_valeur' => $this->nouvelle_valeur,
            'horodatage' => $this->horodatage,
            'logge_par' => $this->whenLoaded('loggePar', fn () => $this->loggePar ? new PersonneResumeResource($this->loggePar) : null),
        ];
    }
}
