<?php
// app/Http/Resources/FamilleResumeResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Résumé famille pour le domaine livraison — extrait le 10/09/2026
 * (Section E3 du refactor), utilisé aujourd'hui uniquement par
 * LiveBoardController::routes()/incidents() (via RouteLivraisonResource/
 * RouteIncidentResource), dont la relation famille est chargée en
 * `'famille:id,nom,prenom,adresse'`.
 *
 * `adresse` exposée seulement si effectivement chargée (colonne NOT NULL
 * en base — si sélectionnée, elle a toujours une vraie valeur, donc ce
 * test distingue fiablement "colonne chargée" de "colonne absente de la
 * sélection Eloquent" sans avoir besoin d'inspecter les attributs bruts
 * du modèle).
 *
 * NE couvre PAS encore le contexte ContactTrackingController::queue()
 * (charge 'famille:id,nom,prenom,telephone,email', sans adresse) — resté
 * en dump brut pour l'instant, voir le backlog "ad-hoc JSON restants"
 * dans les notes de refactor. Étendre ce resource (telephone/email en
 * `when()`) plutôt qu'en créer un second le jour où ce contexte est
 * traité.
 */
class FamilleResumeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'adresse' => $this->when($this->adresse !== null, $this->adresse),
        ];
    }
}
