<?php
// app/Models/Campagne.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une campagne de livraison — zakat_el_fitr (annuelle, toutes les
 * familles éligibles, un seul jour), collecte_alimentaire (ponctuelle, N
 * familles sélectionnées par l'admin) ou don_ponctuel (ad hoc, au fil de
 * l'année). Voir le prompt du 30/08/2026 §1 pour le détail métier de
 * chaque type.
 *
 * @property int    $id
 * @property string $type          zakat_el_fitr | collecte_alimentaire | don_ponctuel
 * @property string $statut        preparation | collecte | en_cours | terminee
 * @property \Illuminate\Support\Carbon $date_livraison
 * @property float  $poids_moyen_kg
 */
class Campagne extends Model
{
    /**
     * Vit dans amana_familles (connexion par défaut), pas amana_commun —
     * même précaution que Famille::getConnectionName() : sans cette
     * déclaration explicite, Eloquent ferait hériter à ce modèle la
     * connexion 'commun' dès qu'il est chargé via une relation partant
     * d'un modèle commun (ex: Personne).
     */
    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['type', 'statut', 'date_livraison', 'poids_moyen_kg'];

    protected $casts = [
        'date_livraison' => 'date',
        'poids_moyen_kg' => 'decimal:2',
    ];

    public const TYPES = ['zakat_el_fitr', 'collecte_alimentaire', 'don_ponctuel'];
    public const STATUTS = ['preparation', 'collecte', 'en_cours', 'terminee'];

    // ── Relations ─────────────────────────────────────────────────────────

    public function arrivees(): HasMany
    {
        return $this->hasMany(CampagneArrivee::class, 'id_campagne');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'id_campagne');
    }

    public function livraisons(): HasMany
    {
        return $this->hasMany(Livraison::class, 'id_campagne');
    }

    public function disponibilitesBenevoles(): HasMany
    {
        return $this->hasMany(BenevoleDisponibilite::class, 'id_campagne');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(RouteLivraison::class, 'id_campagne');
    }

    public function statsSnapshots(): HasMany
    {
        return $this->hasMany(CampagneStatsSnapshot::class, 'id_campagne');
    }

    // ── Accesseurs calculés (voir create_campagnes_table.php) ──────────────

    /**
     * Nombre de "ménages" (donateurs) comptés au poste parking — somme de
     * campagne_arrivees.nombre_donateur, calculée à la volée plutôt que
     * stockée (décision du 31/08/2026, voir migration).
     */
    public function getNombreMenagesAttribute(): int
    {
        return (int) $this->arrivees()->sum('nombre_donateur');
    }

    /**
     * Poids total collecté (kg) — somme de donations.poids_kg, calculée à
     * la volée pour la même raison que nombre_menages ci-dessus.
     */
    public function getPoidsCollecteKgAttribute(): float
    {
        return (float) $this->donations()->sum('poids_kg');
    }
}
