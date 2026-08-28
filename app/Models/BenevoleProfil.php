<?php
// app/Models/BenevoleProfil.php
//
// Étend le modèle partagé Amana\Shared\Models\BenevoleProfil (voir
// amana/shared) — même schéma d'extension que App\Models\Personne. Les
// colonnes/relations/scopes de base (personne(), secteurs(),
// disponibilites(), scopeRecu(), scopeValide()) sont hérités tels quels.

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\BenevoleProfil as SharedBenevoleProfil;

class BenevoleProfil extends SharedBenevoleProfil
{
    /**
     * Candidatures en attente de revue par le staff Familles — voir
     * Admin\BenevoleCandidaturesController::index().
     */
    public function scopePourRevueStaff($query)
    {
        return $query->where('statut', 'Reçu');
    }
}
