<?php
// app/Http/Resources/CampagneResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Campagne — extrait le 12/09/2026 (Section E3 du refactor, suite) de
 * CampagnesController::store()/update(), qui renvoyaient jusqu'ici
 * `$campagne->load('journees')`/`$campagne->fresh()` en dump brut
 * (colonnes internes incluses, ex. timestamps).
 *
 * Reprend exactement l'interface TS Campagne (id/type/date_livraison/
 * statut/poids_moyen_kg(_hotel_kg/_etudiant_kg)/hq_adresse(_latitude/_longitude/_confirmee_le)/
 * livraisons_max_par_tournee/commentaire/journees?/poids_moyen_historique?) plutôt qu'un sous-ensemble minimal :
 * contrairement aux dumps de mutation de LiveBoardController (Section E3,
 * chunk précédent), le front DÉCLARE explicitement ce contrat complet
 * (`apiPost<{ success: boolean; campagne: Campagne }>` dans
 * CampagnesIndex.vue/CampagneDetail.vue) même si store() aujourd'hui ne
 * lit que `.campagne.id` après création — un contrat TS explicite est un
 * signal plus fort qu'une absence totale de typage, donc le champ complet
 * est conservé plutôt que retiré au motif d'un usage actuel partiel.
 *
 * update() fusionne la réponse par spread (`{ ...campagne.value,
 * ...resultat.data.campagne }`, voir CampagneDetail.vue) — un champ non
 * modifié par update() garde simplement sa valeur précédente côté client,
 * donc renvoyer le même contrat complet ici qu'à store() ne pose pas de
 * problème de cohérence.
 */
class CampagneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'date_livraison' => $this->date_livraison,
            'statut' => $this->statut,
            'poids_moyen_kg' => $this->poids_moyen_kg,
            'poids_moyen_hotel_kg' => $this->poids_moyen_hotel_kg,
            'poids_moyen_etudiant_kg' => $this->poids_moyen_etudiant_kg,
            'hq_adresse' => $this->hq_adresse,
            'hq_latitude' => $this->hq_latitude,
            'hq_longitude' => $this->hq_longitude,
            'hq_confirmee_le' => $this->hq_confirmee_le,
            'livraisons_max_par_tournee' => $this->livraisons_max_par_tournee,
            'commentaire' => $this->commentaire,
            'poids_moyen_historique' => $this->whenLoaded('poidsMoyenHistorique', fn () => CampagnePoidsMoyenHistoriqueResource::collection($this->poidsMoyenHistorique)),
            'journees' => $this->whenLoaded('journees', fn () => CampagneJourneeResource::collection($this->journees)),
        ];
    }
}
