<?php
// app/Support/OrdrePackaging.php

declare(strict_types=1);

namespace App\Support;

use App\Models\Livraison;

/**
 * Ordre de la file Packaging (11/10/2026), du plus au moins urgent :
 *
 *   1. préparation prioritaire demandée (chargement / retrait QG) ;
 *   2. poids du créneau : moins une famille a confirmé de créneaux, moins elle a de repli
 *      (un seul = le plus urgent, c'est la bordure rouge « urgente »), puis à nombre égal le
 *      créneau confirmé le plus tôt dans la journée ;
 *   3. criticité décroissante ;
 *   4. FIFO : la première confirmée est servie la première (livraisons.confirme_at, puis id).
 *
 * Attend livraison.creneaux et livraison.famille chargés. Utilisé comme clé de tri par
 * PackagingController — extraite ici pour être testée sans HTTP.
 */
final class OrdrePackaging
{
    /**
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}
     */
    public static function cle(Livraison $livraison): array
    {
        $creneaux = $livraison->creneaux->pluck('creneau')->all();

        $rangs = array_map(
            fn(string $creneau) => (int) array_search($creneau, Creneau::TOUS, true),
            $creneaux,
        );

        return [
            $livraison->preparation_prioritaire ? 0 : 1,
            // Sans aucun créneau (cas théorique) : après tous les autres.
            $creneaux === [] ? PHP_INT_MAX : count($creneaux),
            $rangs === [] ? PHP_INT_MAX : min($rangs),
            -((int) ($livraison->famille->criticite ?? 0)),
            $livraison->confirme_at?->getTimestamp() ?? PHP_INT_MAX,
            $livraison->id,
        ];
    }
}
