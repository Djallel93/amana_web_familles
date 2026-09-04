<?php
// app/Models/RouteLivraison.php

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\Personne;
use Amana\Shared\Models\VehiculeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une tournée de livraison (bénévole + créneau + arrêts ordonnés).
 *
 * Nommé RouteLivraison et non `Route` — décision du 31/08/2026 — pour ne
 * jamais entrer en collision avec Illuminate\Support\Facades\Route,
 * utilisée partout dans routes/web.php ; la table DB reste `routes` (voir
 * $table ci-dessous et create_routes_table.php).
 *
 * @property int         $id
 * @property int         $id_campagne
 * @property int|null    $id_campagne_journee
 * @property int         $id_benevole
 * @property int         $id_vehicule_type
 * @property string|null $creneau             null pour une tournée composée uniquement de livraisons imposées (voir RouteGenerationService)
 * @property string      $statut              planifiee|chargement|en_cours|livraisons_terminees|terminee
 * @property float|null  $distance_totale_km
 * @property float|null  $poids_total_kg
 * @property string|null $lien_maps
 * @property int|null    $locked_by
 * @property \Illuminate\Support\Carbon|null $locked_at
 */
class RouteLivraison extends Model
{
    protected $table = 'routes';

    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = [
        'id_campagne', 'id_campagne_journee', 'id_benevole', 'id_vehicule_type', 'creneau',
        'statut', 'distance_totale_km', 'poids_total_kg', 'lien_maps',
    ];

    protected $casts = [
        'distance_totale_km' => 'decimal:2',
        'poids_total_kg' => 'decimal:2',
        'locked_at' => 'datetime',
    ];

    /**
     * 'livraisons_terminees' ajouté le 03/09/2026 — voir
     * create_routes_table.php et App\Http\Controllers\Livraison\
     * MaRouteController::livraisonTerminee()/retourQg() : état
     * intermédiaire entre "tous les arrêts sont traités" et "le bénévole
     * a confirmé son retour au QG et est de nouveau disponible".
     */
    public const STATUTS = ['planifiee', 'chargement', 'en_cours', 'livraisons_terminees', 'terminee'];

    // ── Relations ─────────────────────────────────────────────────────────

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }

    public function campagneJournee(): BelongsTo
    {
        return $this->belongsTo(CampagneJournee::class, 'id_campagne_journee');
    }

    /**
     * true si tous les arrêts (hors "retour QG" éventuel, id_livraison
     * null) sont livrés ou ignorés — condition d'activation du bouton
     * "Livraison terminé" côté MaRouteController/ma-route.blade.php (voir
     * le prompt du 03/09/2026).
     */
    public function toutesEtapesTraitees(): bool
    {
        return $this->etapes()
            ->whereNotNull('id_livraison')
            ->where('statut', 'en_attente')
            ->doesntExist();
    }

    public function benevole(): BelongsTo
    {
        return $this->belongsTo(Personne::class, 'id_benevole');
    }

    public function vehiculeType(): BelongsTo
    {
        return $this->belongsTo(VehiculeType::class, 'id_vehicule_type');
    }

    public function etapes(): HasMany
    {
        return $this->hasMany(EtapeRoute::class, 'id_route')->orderBy('ordre');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(RouteIncident::class, 'id_route');
    }
}
