<?php
// app/Models/Quartier.php
//
// Étend le modèle partagé Amana\Shared\Models\Quartier (voir amana/shared)
// avec la relation vers Famille, propre à cette app — le modèle partagé ne
// la porte pas pour rester découplé de toute app consommatrice en
// particulier (voir le commentaire dans Amana\Shared\Models\Quartier).

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\Quartier as SharedQuartier;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quartier extends SharedQuartier
{
    public function familles(): HasMany
    {
        return $this->hasMany(Famille::class, 'id_quartier');
    }
}
