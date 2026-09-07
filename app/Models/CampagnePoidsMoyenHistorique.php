<?php
// app/Models/CampagnePoidsMoyenHistorique.php

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\Personne;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une modification de poids_moyen_kg/poids_moyen_hotel_kg/
 * poids_moyen_etudiant_kg sur une campagne — voir
 * create_campagne_poids_moyen_historiques_table.php pour le raisonnement
 * complet et CampagnesController::mettreAJourPoidsMoyen() pour l'écriture.
 *
 * @property int    $id
 * @property int    $id_campagne
 * @property string $type              normal|hotel|etudiant
 * @property float  $ancienne_valeur
 * @property float  $nouvelle_valeur
 * @property \Illuminate\Support\Carbon $horodatage
 * @property int    $logge_par
 */
class CampagnePoidsMoyenHistorique extends Model
{
    public $timestamps = false;

    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['id_campagne', 'type', 'ancienne_valeur', 'nouvelle_valeur', 'horodatage', 'logge_par'];

    protected $casts = [
        'ancienne_valeur' => 'decimal:2',
        'nouvelle_valeur' => 'decimal:2',
        'horodatage' => 'datetime',
    ];

    public const TYPES = ['normal', 'hotel', 'etudiant'];

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }

    public function loggePar(): BelongsTo
    {
        return $this->belongsTo(Personne::class, 'logge_par');
    }
}
