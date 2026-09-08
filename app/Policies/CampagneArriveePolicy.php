<?php
// app/Policies/CampagneArriveePolicy.php

declare(strict_types=1);

namespace App\Policies;

use Amana\Shared\Models\Personne;
use App\Models\CampagneArrivee;
use App\Policies\Concerns\AutoriseEquipeCampagne;

/**
 * Autorisation des actions equipe_reception sur une CampagneArrivee
 * précise (routes PATCH/DELETE livraison/reception/arrivees/{arrivee},
 * voir routes/web.php) — pendant de App\Policies\CampagnePolicy pour un
 * modèle qui n'a pas {campagne} directement en paramètre de route.
 *
 * CampagneArrivee::campagne() est une relation directe (id_campagne),
 * pas de saut intermédiaire à vérifier ici contrairement à
 * LivraisonColisPolicy/EtapeRoutePolicy.
 *
 * Une seule méthode `gerer()` plutôt qu'un modifier()/supprimer()
 * séparé : le prompt du 07/09/2026 est explicite ("no restriction de
 * propriété (n'importe quel equipe_reception peut éditer une ligne
 * saisie par quelqu'un d'autre)"), les deux routes n'ont donc aucune
 * différence de droits à exprimer.
 */
final class CampagneArriveePolicy
{
    use AutoriseEquipeCampagne;

    public function gerer(Personne $personne, CampagneArrivee $arrivee): bool
    {
        return $this->autoriseEquipe($personne, $arrivee->campagne, 'equipe_reception');
    }
}
