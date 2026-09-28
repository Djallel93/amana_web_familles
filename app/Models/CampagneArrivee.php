<?php
// app/Models/CampagneArrivee.php

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\Personne;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une tape "+1" au poste de comptage des donateurs (parking) — voir
 * create_campagne_arrivees_table.php pour le raisonnement complet
 * (notamment pourquoi nombre_donateur existe et pourquoi aucune identité
 * de donateur n'est enregistrée).
 *
 * @property int      $id
 * @property int      $id_campagne
 * @property int|null $id_campagne_journee  Ajouté le 05/09/2026, voir migration — nullable, pas de FK (ordre des migrations)
 * @property int      $nombre_donateur
 * @property \Illuminate\Support\Carbon $horodatage
 * @property int      $logge_par
 */
class CampagneArrivee extends Model
{
    public $timestamps = false;

    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['id_campagne', 'id_campagne_journee', 'nombre_donateur', 'horodatage', 'logge_par'];

    protected $casts = [
        'nombre_donateur' => 'integer',
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

    /**
     * logge_par identifie le membre du staff tenant le poste, jamais le
     * donateur (aucune identité de donateur n'est enregistrée) — voir
     * migration.
     */
    public function loggePar(): BelongsTo
    {
        return $this->belongsTo(Personne::class, 'logge_par');
    }
}
