<?php
// app/Models/BenevoleRetourQg.php

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\Personne;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un bénévole s'étant déclaré de retour au QG et disponible pour une
 * nouvelle tournée — voir create_benevole_retours_qg_table.php.
 *
 * @property int $id
 * @property int $id_campagne
 * @property int $id_personne
 * @property int|null $id_route_origine
 * @property \Illuminate\Support\Carbon $disponible_depuis
 * @property \Illuminate\Support\Carbon|null $recupere_le
 */
class BenevoleRetourQg extends Model
{
    public $timestamps = false;

    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['id_campagne', 'id_personne', 'id_route_origine', 'disponible_depuis', 'recupere_le'];

    protected $casts = [
        'disponible_depuis' => 'datetime',
        'recupere_le' => 'datetime',
    ];

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }

    public function personne(): BelongsTo
    {
        return $this->belongsTo(Personne::class, 'id_personne');
    }

    public function routeOrigine(): BelongsTo
    {
        return $this->belongsTo(RouteLivraison::class, 'id_route_origine');
    }

    public function scopeDisponibles($query)
    {
        return $query->whereNull('recupere_le');
    }
}
