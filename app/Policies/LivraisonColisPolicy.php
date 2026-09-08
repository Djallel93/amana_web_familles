<?php
// app/Policies/LivraisonColisPolicy.php

declare(strict_types=1);

namespace App\Policies;

use Amana\Shared\Models\Personne;
use App\Models\LivraisonColis;
use App\Policies\Concerns\AutoriseEquipeCampagne;

/**
 * Autorisation equipe_packaging sur un LivraisonColis précis (route POST
 * livraison/packaging/colis/{colis}/statut, voir routes/web.php).
 *
 * Pas de relation directe LivraisonColis → Campagne : deux sauts
 * (colis → livraison → campagne, voir LivraisonColis::livraison() et
 * Livraison::campagne()) plutôt qu'une nouvelle relation belongsTo
 * dupliquant id_campagne sur livraison_colis — la table n'a que
 * id_livraison, pas de raison d'ajouter une colonne dénormalisée pour ce
 * seul usage.
 */
final class LivraisonColisPolicy
{
    use AutoriseEquipeCampagne;

    public function gerer(Personne $personne, LivraisonColis $colis): bool
    {
        return $this->autoriseEquipe($personne, $colis->livraison->campagne, 'equipe_packaging');
    }
}
