<?php
// app/Http/Resources/ReleveResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\RelevePosteDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une ligne de relevé (pesée → Donation, réception → CampagneArrivee) —
 * extrait le 12/09/2026 (Section E3 du refactor, suite) de
 * PosteReleveController::journal(), qui renvoyait jusqu'ici la collection
 * brute (`id_campagne`/`id_campagne_journee` inclus, ni l'un ni l'autre
 * jamais lus — voir poste-releve.blade.php::afficherJournal()).
 *
 * Paramétré par RelevePosteDefinition plutôt que dupliqué en
 * DonationResource/CampagneArriveeResource : un seul champ dynamique
 * distingue les deux (`poids_kg` vs `nombre_donateur`, voir
 * $definition->champ) — même logique que PosteReleveController lui-même,
 * qui unifie déjà pesée/réception derrière ce même objet plutôt que deux
 * contrôleurs quasi identiques.
 *
 * `logge_par` : afficherJournal() gère explicitement les deux formes
 * (`typeof releve.logge_par === 'object'` sinon `#${releve.logge_par}`)
 * — reflète fidèlement whenLoaded() ci-dessous plutôt que de figer une
 * seule forme, journal() chargeant toujours `loggePar:id,nom,prenom`
 * aujourd'hui mais ce resource restant correct si un futur appelant ne le
 * charge pas.
 */
class ReleveResource extends JsonResource
{
    public function __construct(
        $resource,
        private readonly RelevePosteDefinition $definition,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            $this->definition->champ => $this->{$this->definition->champ},
            'horodatage' => $this->horodatage,
            'logge_par' => $this->whenLoaded('loggePar', fn () => $this->loggePar ? new PersonneResumeResource($this->loggePar) : $this->getAttribute('logge_par')),
        ];
    }
}
