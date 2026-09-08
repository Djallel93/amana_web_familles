<?php
// app/Policies/LivraisonPolicy.php

declare(strict_types=1);

namespace App\Policies;

use Amana\Shared\Models\Personne;
use App\Models\Livraison;
use App\Policies\Concerns\AutoriseEquipeCampagne;

/**
 * Autorisation equipe_packaging sur une Livraison précise (routes POST
 * livraison/packaging/{livraison}/pret et /annuler, voir routes/web.php)
 * — Livraison::campagne() est une relation directe (id_campagne).
 *
 * Nommée gerer() comme les autres policies de ce lot plutôt que
 * marquerPret()/annuler() : mêmes deux routes, même rôle requis, aucune
 * distinction de droits entre les deux actions à exprimer via l'ability.
 */
final class LivraisonPolicy
{
    use AutoriseEquipeCampagne;

    public function gerer(Personne $personne, Livraison $livraison): bool
    {
        return $this->autoriseEquipe($personne, $livraison->campagne, 'equipe_packaging');
    }
}
