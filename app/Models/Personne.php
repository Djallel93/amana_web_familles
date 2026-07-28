<?php
// app/Models/Personne.php
//
// Étend le modèle partagé Amana\Shared\Models\Personne (voir amana/shared).
// AMANA Familles est staff-only : seules les personnes ayant un rôle scopé
// sur l'application 'familles' se connectent ici. Les familles bénéficiaires
// ne sont PAS des ref_personnes — voir App\Models\Famille, sans compte.
//
// roles(), isAdmin(), isGestionnaire(), isBenevole(), isMembre(),
// hasAtLeastRole(), les scopes valide()/enAttente() et
// getNomCompletAttribute() sont hérités tels quels du modèle partagé.

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\Personne as SharedPersonne;

class Personne extends SharedPersonne
{
    /**
     * Personnes ayant un rôle sur l'application 'familles', toutes
     * casquettes confondues — utile pour lister le staff Familles dans
     * l'admin (contrairement à adminsDe(), hérité du modèle partagé, qui
     * ne filtre que sur le rôle admin).
     */
    public function scopeStaffFamilles($query)
    {
        return $query->whereHas('roles', function ($q) {
            $q->whereHas('application', fn($q2) => $q2->where('code', 'familles'));
        });
    }
}
