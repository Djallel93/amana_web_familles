<?php
// app/Models/LivraisonColis.php

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\Personne;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un colis individuel (un par personne du foyer) d'une Livraison — voir
 * create_livraison_colis_table.php pour le raisonnement complet.
 * livraisons.statut_conditionnement reste la valeur agrégée dérivée de
 * l'ensemble des colis d'une livraison (voir
 * PackagingController::marquerColisPret()/annulerConditionnement()).
 *
 * @property int    $id
 * @property int    $id_livraison
 * @property int    $numero          1 à nombre_personnes
 * @property string $statut          a_preparer|pret
 * @property \Illuminate\Support\Carbon|null $pret_le
 * @property int|null $pret_par
 */
class LivraisonColis extends Model
{
    public $timestamps = false;

    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['id_livraison', 'numero', 'statut', 'pret_le', 'pret_par'];

    protected $casts = [
        'numero' => 'integer',
        'pret_le' => 'datetime',
    ];

    public const STATUTS = ['a_preparer', 'pret'];

    public function livraison(): BelongsTo
    {
        return $this->belongsTo(Livraison::class, 'id_livraison');
    }

    public function pretPar(): BelongsTo
    {
        return $this->belongsTo(Personne::class, 'pret_par');
    }
}
