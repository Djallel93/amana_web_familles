<?php
// app/Http/Resources/FamilleEligibleResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Famille éligible/non couverte — extrait le 12/09/2026 (Section E3 du
 * refactor, suite) de CampagnesController::eligibles() et
 * LiveBoardController::nonCouvertesTable(), qui renvoyaient jusqu'ici
 * `response()->json($query->paginate(...))` sur une requête sélectionnant
 * `familles.*` en entier (voir LivraisonGenerationService::eligibles()/
 * nonCouvertesEligibles()) — c'est-à-dire TOUTES les colonnes de familles,
 * y compris des champs internes/sensibles sans rapport avec ces deux
 * écrans (ex. notes internes, identifiants d'organisation d'origine, etc.).
 *
 * Champs retenus : uniquement ceux effectivement lus par
 * CampagneDetail.vue (table éligibilité) et BuildRouteFlow.vue (table non
 * couvertes) — voir grep sur les deux templates. En particulier,
 * `email`/`nombre_adulte`/`nombre_enfant`/`id_organisation`/`est_hotel`/
 * `etudiant` (présents sur l'interface TS FamilleEligible) ne sont lus nulle
 * part dans ces deux tables (les seules propriétés de même nom qui y
 * apparaissent sont en réalité des valeurs de filtre, pas des champs de
 * ligne) et sont donc délibérément omis ici.
 *
 * `quartier` : les deux contrôleurs chargeaient jusqu'ici
 * `quartier.secteur.ville`, mais ni l'un ni l'autre template ne lit
 * `.secteur`/`.ville` (seul `quartier.nom` est affiché) — l'eager load a
 * été réduit à `quartier` seul dans les deux contrôleurs en même temps que
 * ce resource (voir CampagnesController::eligibles()/
 * LiveBoardController::nonCouvertesTable()).
 *
 * `id_livraison` : colonne virtuelle posée par selectSub() UNIQUEMENT dans
 * LivraisonGenerationService::nonCouvertesEligibles() (voir son docblock) —
 * absente de eligibles(), d'où le when() plutôt qu'un accès direct.
 */
class FamilleEligibleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'telephone' => $this->telephone,
            'telephone_bis' => $this->telephone_bis,
            'adresse' => $this->adresse,
            'quartier' => $this->whenLoaded('quartier', fn () => $this->quartier ? new QuartierResource($this->quartier) : null),
            'criticite' => $this->criticite,
            'derniere_livraison_le' => $this->derniere_livraison_le,
            'id_livraison' => $this->when(isset($this->id_livraison), $this->id_livraison),
        ];
    }
}
