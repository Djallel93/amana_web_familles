<?php
// app/Models/EtapeRoute.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un arrêt ordonné d'une tournée (RouteLivraison) — sortie de
 * l'optimisation TSP (Patch 3). id_livraison null = arrêt "retour QG".
 *
 * @property int      $id
 * @property int      $id_route
 * @property int|null $id_livraison
 * @property int      $ordre
 * @property string   $statut  en_attente|livree|ignoree
 */
class EtapeRoute extends Model
{
    public $timestamps = false;

    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['id_route', 'id_livraison', 'ordre', 'statut'];

    protected $casts = [
        'ordre' => 'integer',
    ];

    public const STATUTS = ['en_attente', 'livree', 'ignoree'];

    public function route(): BelongsTo
    {
        return $this->belongsTo(RouteLivraison::class, 'id_route');
    }

    public function livraison(): BelongsTo
    {
        return $this->belongsTo(Livraison::class, 'id_livraison');
    }
}
