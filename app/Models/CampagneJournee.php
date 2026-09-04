<?php
// app/Models/CampagneJournee.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une journée de collecte/livraison au sein d'une campagne — voir
 * create_campagne_journees_table.php pour le raisonnement complet
 * (une campagne, plusieurs journées, plutôt qu'une campagne par jour).
 *
 * @property int         $id
 * @property int         $id_campagne
 * @property \Illuminate\Support\Carbon $date
 * @property string|null $label
 * @property int         $ordre
 */
class CampagneJournee extends Model
{
    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['id_campagne', 'date', 'label', 'ordre'];

    protected $casts = [
        'date' => 'date',
        'ordre' => 'integer',
    ];

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }

    public function livraisons(): HasMany
    {
        return $this->hasMany(Livraison::class, 'id_campagne_journee');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(RouteLivraison::class, 'id_campagne_journee');
    }
}
