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
 * @property \Illuminate\Support\Carbon $date_livraison  Date de RÉFÉRENCE — voir journees() depuis le 03/09/2026
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

    protected $fillable = [
        'type', 'statut', 'date_livraison',
        'poids_moyen_kg', 'poids_moyen_hotel_kg', 'poids_moyen_etudiant_kg',
        'benevoles_notifies_le',
    ];

    protected $casts = [
        'date_livraison' => 'date',
        'poids_moyen_kg' => 'decimal:2',
        'poids_moyen_hotel_kg' => 'decimal:2',
        'poids_moyen_etudiant_kg' => 'decimal:2',
        'benevoles_notifies_le' => 'datetime',
    ];

    public const TYPES = ['zakat_el_fitr', 'collecte_alimentaire', 'don_ponctuel'];
    public const STATUTS = ['preparation', 'collecte', 'en_cours', 'terminee'];

    // ── Relations ─────────────────────────────────────────────────────────

    /**
     * Journées de collecte/livraison de cette campagne — voir le prompt du
     * 03/09/2026 (gestion multi-jours) et create_campagne_journees_table.php.
     * Ordonnées par `ordre` (pas forcément par date, voir commentaire sur
     * cette colonne dans la migration).
     */
    public function journees(): HasMany
    {
        return $this->hasMany(CampagneJournee::class, 'id_campagne')->orderBy('ordre');
    }

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

    // ── Journées (voir le prompt du 03/09/2026) ─────────────────────────────

    /**
     * Ajoute une journée à cette campagne — couvre à la fois la
     * planification initiale (plusieurs journées créées d'un coup à la
     * création de la campagne) et le cas "on vient de décider d'un jour
     * de collecte/livraison en plus" (ex: zakat el-fitr, décision prise
     * l'après-midi pour le lendemain — voir le prompt). N'affecte AUCUNE
     * livraison/contact déjà confirmé sur les journées existantes : une
     * journée ajoutée démarre vide, à peupler séparément (génération de
     * livraisons scopée à cette journée, voir LivraisonGenerationService).
     *
     * Si c'est la toute première journée de la campagne, synchronise
     * `date_livraison` (date de référence, voir docblock de classe) sur
     * sa date — pour toute campagne créée après cette évolution, qui n'a
     * donc jamais eu de date_livraison saisie directement.
     */
    public function ajouterJournee(\DateTimeInterface|string $date, ?string $label = null): CampagneJournee
    {
        $prochainOrdre = ((int) $this->journees()->max('ordre')) + 1;

        $journee = $this->journees()->create([
            'date' => $date,
            'label' => $label,
            'ordre' => $prochainOrdre,
        ]);

        if ($prochainOrdre === 1) {
            $this->update(['date_livraison' => $journee->date]);
        }

        return $journee;
    }
}
