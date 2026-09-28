<?php
// app/Models/Donation.php

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\Personne;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un relevé de pesée au poste "entrée QG" — poids total unique, jamais
 * ventilé par catégorie (voir create_campagnes_domain_tables.php).
 *
 * @property int      $id
 * @property int      $id_campagne
 * @property int|null $id_campagne_journee  Ajouté le 05/09/2026, voir migration — nullable, pas de FK (ordre des migrations)
 * @property float    $poids_kg
 * @property \Illuminate\Support\Carbon $horodatage
 * @property int      $logge_par
 */
class Donation extends Model
{
    public $timestamps = false;

    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['id_campagne', 'id_campagne_journee', 'poids_kg', 'horodatage', 'logge_par'];

    protected $casts = [
        'poids_kg' => 'decimal:2',
        'horodatage' => 'datetime',
    ];

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }

    public function journee(): BelongsTo
    {
        return $this->belongsTo(CampagneJournee::class, 'id_campagne_journee');
    }

    public function loggePar(): BelongsTo
    {
        return $this->belongsTo(Personne::class, 'logge_par');
    }
}
