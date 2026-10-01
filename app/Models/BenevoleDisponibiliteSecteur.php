<?php
// app/Models/BenevoleDisponibiliteSecteur.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot : un secteur couvert par un bénévole pour UNE journée de campagne
 * (quand il ne reprend pas la zone de son profil) — voir
 * create_livraison_equipe_tables.php. `id_secteur` référence `secteurs`
 * (base commun, pas de FK).
 *
 * @property int $id
 * @property int $id_benevole_disponibilite
 * @property int $id_secteur
 */
class BenevoleDisponibiliteSecteur extends Model
{
    // Eloquent pluraliserait "benevole_disponibilite_secteur" en
    // "...secteurs" correctement, mais on le fixe explicitement comme pour
    // BenevoleDisponibiliteCreneau.
    protected $table = 'benevole_disponibilite_secteurs';

    public $timestamps = false;

    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['id_benevole_disponibilite', 'id_secteur'];

    public function disponibilite(): BelongsTo
    {
        return $this->belongsTo(BenevoleDisponibilite::class, 'id_benevole_disponibilite');
    }
}
