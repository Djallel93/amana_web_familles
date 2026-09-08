<?php
// app/Policies/DonationPolicy.php

declare(strict_types=1);

namespace App\Policies;

use Amana\Shared\Models\Personne;
use App\Models\Donation;
use App\Policies\Concerns\AutoriseEquipeCampagne;

/**
 * Autorisation des actions equipe_pesee sur une Donation précise (routes
 * PATCH/DELETE livraison/pesee/dons/{don}, voir routes/web.php) —
 * pendant de App\Policies\CampagneArriveePolicy pour le domaine pesée.
 *
 * Donation::campagne() est une relation directe (id_campagne), même
 * raisonnement que CampagneArriveePolicy pour gerer() unique (pas de
 * distinction modifier/supprimer, même absence de restriction de
 * propriété côté prompt).
 */
final class DonationPolicy
{
    use AutoriseEquipeCampagne;

    public function gerer(Personne $personne, Donation $don): bool
    {
        return $this->autoriseEquipe($personne, $don->campagne, 'equipe_pesee');
    }
}
