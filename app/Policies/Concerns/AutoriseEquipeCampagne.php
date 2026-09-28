<?php
// app/Policies/Concerns/AutoriseEquipeCampagne.php

declare(strict_types=1);

namespace App\Policies\Concerns;

use Amana\Shared\Models\Personne;
use App\Models\Campagne;

/**
 * Bypass admin/gestionnaire + vérification campagne_equipe_membres,
 * extrait de App\Policies\CampagnePolicy (07/09/2026) pour être réutilisé
 * tel quel par les 6 policies des sous-ressources livraison ajoutées le
 * 08/09/2026 (CampagneArrivee, Donation, LivraisonColis, Livraison,
 * RouteLivraison, EtapeRoute — voir routes/web.php, groupes equipe_*).
 *
 * Chacune de ces policies résout sa propre Campagne à partir de son
 * modèle (relation directe ou à travers une relation intermédiaire, voir
 * le docblock de chaque policy pour le chemin exact) puis délègue ici —
 * la logique métier (qui a le droit, dans quel ordre) ne vit qu'à un seul
 * endroit, seule la résolution de la Campagne diffère d'une policy à
 * l'autre.
 */
trait AutoriseEquipeCampagne
{
    private function autoriseEquipe(Personne $personne, Campagne $campagne, string $role): bool
    {
        return $personne->isAdmin()
            || $personne->isGestionnaire()
            || $campagne->aRole($personne->id, $role);
    }
}
