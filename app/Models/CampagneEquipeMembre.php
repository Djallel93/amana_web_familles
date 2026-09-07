<?php
// app/Models/CampagneEquipeMembre.php

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\Personne;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Affectation d'une Personne à un rôle équipe_* sur une campagne précise —
 * voir create_campagne_equipe_membres_table.php pour le raisonnement
 * complet (pourquoi cette table existe en plus de ref_personnes_roles) et
 * App\Policies\CampagnePolicy pour son usage en autorisation.
 *
 * @property int    $id
 * @property int    $id_campagne
 * @property int    $id_personne
 * @property string $role  equipe_reception|equipe_pesee|equipe_packaging|equipe_chargement
 */
class CampagneEquipeMembre extends Model
{
    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['id_campagne', 'id_personne', 'role'];

    public const ROLES = ['equipe_reception', 'equipe_pesee', 'equipe_packaging', 'equipe_chargement'];

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }

    /**
     * Cross-connexion (commun) — même précaution que
     * BenevoleDisponibilite::personne(), voir ce modèle.
     */
    public function personne(): BelongsTo
    {
        return $this->belongsTo(Personne::class, 'id_personne');
    }
}
