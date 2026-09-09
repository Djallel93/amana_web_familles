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
 * @property string   $statut  en_attente|en_cours|livree|ignoree
 */
class EtapeRoute extends Model
{
    /**
     * Bug préexistant repéré le 05/09/2026 (indépendant de tout ce qui
     * précède dans ce fichier) : la migration crée la table
     * 'etapes_route' (2026_08_31_000010_create_etapes_route_table.php),
     * mais la convention Eloquent par défaut pour un modèle EtapeRoute
     * est 'etape_routes' (snake_case + s) — SANS cette surcharge
     * explicite, toute requête sur ce modèle échoue avec "Base table or
     * view not found: etape_routes". Resté invisible jusqu'ici car aucun
     * chemin de code n'avait encore sollicité cette relation avec une
     * vraie base de données (voir PackagingController::etiquettes(),
     * premier appelant réel à l'avoir fait remonter).
     */
    protected $table = 'etapes_route';

    public $timestamps = false;

    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['id_route', 'id_livraison', 'ordre', 'statut'];

    protected $casts = [
        'ordre' => 'integer',
    ];

    /**
     * 'en_cours' ajouté le 09/09/2026 (prompt de cette date §5.2.3) — voir
     * le docblock de la migration etapes_route pour le raisonnement.
     */
    public const STATUTS = ['en_attente', 'en_cours', 'livree', 'ignoree'];

    public function route(): BelongsTo
    {
        return $this->belongsTo(RouteLivraison::class, 'id_route');
    }

    public function livraison(): BelongsTo
    {
        return $this->belongsTo(Livraison::class, 'id_livraison');
    }
}
