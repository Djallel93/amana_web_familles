<?php
// app/Models/LivraisonCreneau.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot : un créneau (App\Support\Creneau::TOUS) pour lequel une famille
 * est disponible, pour une livraison donnée.
 *
 * @property int    $id
 * @property int    $id_livraison
 * @property string $creneau
 */
class LivraisonCreneau extends Model
{
    public $timestamps = false;

    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['id_livraison', 'creneau'];

    public function livraison(): BelongsTo
    {
        return $this->belongsTo(Livraison::class, 'id_livraison');
    }
}
