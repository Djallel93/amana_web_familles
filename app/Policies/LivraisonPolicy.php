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

    /**
     * Actions équipe_chargement sur une Livraison se_deplace précise
     * (livraison/retrait-hq/livraisons/{livraison}/livre|non-livre|scan,
     * voir routes/livraison.php) — ajouté le 24/09/2026 (prompt de cette
     * date §2/§3) : DÉLIBÉRÉMENT une ability séparée de gerer() ci-dessus
     * (equipe_packaging) plutôt qu'une réutilisation — même équipe que
     * livraison/chargement (equipe_chargement, voir RouteLivraisonPolicy
     * ci-dessus, PAS un nouveau rôle, voir le prompt §Additional points 3
     * : "I insist that there is NO NEW TEAM ROLE created in this
     * change"), mais un rôle différent de celui de gerer().
     */
    public function gererRetraitHq(Personne $personne, Livraison $livraison): bool
    {
        return $this->autoriseEquipe($personne, $livraison->campagne, 'equipe_chargement');
    }
}
