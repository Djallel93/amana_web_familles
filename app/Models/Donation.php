<?php
// app/Models/Donation.php

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\Personne;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un relevé de pesée au poste "entrée QG" — poids total unique, jamais
 * ventilé par catégorie (voir create_donations_table.php).
 *
 * @property int   $id
 * @property int   $id_campagne
 * @property float $poids_kg
 * @property \Illuminate\Support\Carbon $horodatage
 * @property int   $logge_par
 */
class Donation extends Model
{
    public $timestamps = false;

    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['id_campagne', 'poids_kg', 'horodatage', 'logge_par'];

    protected $casts = [
        'poids_kg' => 'decimal:2',
        'horodatage' => 'datetime',
    ];

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }

    public function loggePar(): BelongsTo
    {
        return $this->belongsTo(Personne::class, 'logge_par');
    }
}
