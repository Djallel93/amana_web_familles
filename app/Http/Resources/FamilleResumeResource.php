<?php
// app/Http/Resources/FamilleResumeResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Résumé famille pour le domaine livraison — extrait le 10/09/2026
 * (Section E3 du refactor), utilisé par LiveBoardController::routes()/
 * incidents() (via RouteLivraisonResource/RouteIncidentResource), dont la
 * relation famille est chargée en `'famille:id,nom,prenom,adresse'`.
 *
 * `adresse` exposée seulement si effectivement chargée (colonne NOT NULL
 * en base — si sélectionnée, elle a toujours une vraie valeur, donc ce
 * test distingue fiablement "colonne chargée" de "colonne absente de la
 * sélection Eloquent" sans avoir besoin d'inspecter les attributs bruts
 * du modèle). Même mécanique pour `telephone` ci-dessous (colonne NOT
 * NULL également). `telephone_bis`/`email` sont, eux, nullables en base :
 * le test `!== null` ne peut donc pas y distinguer "colonne non
 * sélectionnée" de "valeur NULL réelle" — sans conséquence ici, les deux
 * cas doivent de toute façon aboutir à la même chose côté JSON (champ
 * absent), qui est bien le comportement optionnel attendu par
 * l'interface TS FamilleResume.
 *
 * Étendue le 12/09/2026 (Section E3 du refactor, suite) pour couvrir
 * aussi ContactTrackingController::queue(), qui chargeait jusqu'ici
 * 'famille:id,nom,prenom,telephone,telephone_bis,email,id_quartier' en
 * dump brut — voir le docblock de queue() : telephone/telephone_bis/
 * email en when() plutôt qu'un second resource, comme prévu ici depuis
 * l'extraction initiale. `id_quartier` (et la relation
 * `famille.quartier.secteur.ville`, également chargée par queue()) n'a
 * été trouvé lu nulle part dans ContactsQueue.vue (grep sur le template :
 * seuls id/nom/prenom/telephone/telephone_bis/email de `famille` sont
 * affichés) — l'eager load correspondant a été retiré de queue() en même
 * temps que ce champ ici, plutôt que de le sérialiser sans raison.
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
            'telephone' => $this->when($this->telephone !== null, $this->telephone),
            'telephone_bis' => $this->when($this->telephone_bis !== null, $this->telephone_bis),
            'email' => $this->when($this->email !== null, $this->email),
        ];
    }
}
