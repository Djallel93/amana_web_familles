<?php
// app/Models/BenevoleDisponibiliteCreneau.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot : un créneau pour lequel un bénévole se déclare disponible, sur
 * une campagne donnée.
 *
 * @property int    $id
 * @property int    $id_benevole_disponibilite
 * @property string $creneau
 */
class BenevoleDisponibiliteCreneau extends Model
{
    // Même correctif que LivraisonCreneau : Eloquent pluraliserait
    // "benevole_disponibilite_creneau" en "...creneaus" (règle anglaise) —
    // la migration crée bien 'benevole_disponibilite_creneaux'.
    protected $table = 'benevole_disponibilite_creneaux';

    public $timestamps = false;

    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['id_benevole_disponibilite', 'creneau'];

    public function disponibilite(): BelongsTo
    {
        return $this->belongsTo(BenevoleDisponibilite::class, 'id_benevole_disponibilite');
    }
}
