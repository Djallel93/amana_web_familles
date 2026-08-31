<?php
// app/Models/CampagneStatsSnapshot.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Photo des métriques d'une campagne à un instant donné (conclusion ou
 * capture périodique) — voir create_campagne_stats_snapshots_table.php,
 * forme provisoire à confirmer au Patch 5 (stats).
 *
 * @property int    $id
 * @property int    $id_campagne
 * @property \Illuminate\Support\Carbon $snapshot_at
 * @property array  $donnees
 */
class CampagneStatsSnapshot extends Model
{
    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['id_campagne', 'snapshot_at', 'donnees'];

    protected $casts = [
        'snapshot_at' => 'datetime',
        'donnees' => 'array',
    ];

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }
}
