<?php
// app/Http/Resources/PersonneResumeResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Résumé personne (bénévole) pour le domaine livraison — extrait le
 * 10/09/2026 (Section E3 du refactor) de LiveBoardController::routes()/
 * incidents(), qui chargeaient jusqu'ici la relation `benevole` (table
 * ref_personnes, connexion `commun`) sans aucune restriction de colonnes.
 * Le modèle Personne masque déjà `password`/`remember_token`
 * (Amana\Shared\Models\Personne::$hidden), mais d'autres colonnes
 * (email, etc.) auraient tout de même été exposées sans intérêt pour
 * resources/js/components/livraison/tableau-de-bord/RoutesPanel.vue/
 * IncidentsPanel.vue, qui ne lisent que id/nom/prenom sur ce champ.
 *
 * id_vehicule_type/vehicule_type (présents sur l'interface TS
 * PersonneResume) sont VOLONTAIREMENT absents ici : ce sont des colonnes
 * virtuelles posées uniquement par PickersController::personnes() (une
 * jointure propre à cet endpoint, pas un vrai attribut du modèle
 * Personne) — sans objet pour le `benevole` d'une RouteLivraison, qui
 * expose son véhicule via son propre champ `vehicule_type` (relation
 * directe de la tournée, voir RouteLivraisonResource), pas via le
 * bénévole.
 */
class PersonneResumeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
        ];
    }
}
