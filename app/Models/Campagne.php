<?php
// app/Models/Campagne.php

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\Personne;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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
 * @property string|null $hq_adresse      Libellé HQ propre à cette campagne — voir create_campagnes_table.php
 * @property float|null  $hq_latitude
 * @property float|null  $hq_longitude
 * @property string|null $commentaire     Dernière valeur seulement, pas d'historique
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
        // Ajoutés le 05/09/2026 (prompt §1.2/§1.3) — voir docblock de la
        // migration campagnes pour le raisonnement HQ (préremplissage à
        // la création, pas de fallback dynamique) et commentaire (dernière
        // valeur seulement, pas d'historique).
        'hq_adresse', 'hq_latitude', 'hq_longitude', 'commentaire',
    ];

    protected $casts = [
        'date_livraison' => 'date',
        'poids_moyen_kg' => 'decimal:2',
        'poids_moyen_hotel_kg' => 'decimal:2',
        'poids_moyen_etudiant_kg' => 'decimal:2',
        'benevoles_notifies_le' => 'datetime',
        'hq_latitude' => 'decimal:7',
        'hq_longitude' => 'decimal:7',
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

    /**
     * Disponibilités bénévoles de TOUTES les journées de cette campagne —
     * rescopée le 05/09/2026 : BenevoleDisponibilite pointe désormais vers
     * une CampagneJournee, pas directement vers la Campagne (voir
     * create_benevole_disponibilites_table.php). Utile pour une vue
     * d'ensemble au niveau campagne ; le filtrage par journée précise se
     * fait via CampagneJournee::disponibilites().
     */
    public function disponibilitesBenevoles(): HasManyThrough
    {
        return $this->hasManyThrough(
            BenevoleDisponibilite::class,
            CampagneJournee::class,
            'id_campagne',           // FK sur campagne_journees pointant vers campagnes
            'id_campagne_journee',   // FK sur benevole_disponibilites pointant vers campagne_journees
            'id',                    // PK locale (campagnes.id)
            'id',                    // PK sur campagne_journees
        );
    }

    public function routes(): HasMany
    {
        return $this->hasMany(RouteLivraison::class, 'id_campagne');
    }

    public function statsSnapshots(): HasMany
    {
        return $this->hasMany(CampagneStatsSnapshot::class, 'id_campagne');
    }

    /**
     * Affectations équipe_* (equipe_reception/pesee/packaging/chargement)
     * de cette campagne — voir create_campagne_equipe_membres_table.php et
     * App\Policies\CampagnePolicy, seul consommateur attendu de
     * aRole() ci-dessous.
     */
    public function equipeMembres(): HasMany
    {
        return $this->hasMany(CampagneEquipeMembre::class, 'id_campagne');
    }

    /**
     * Cette personne tient-elle CE rôle équipe_* sur CETTE campagne
     * précisément (pas le rôle global ref_personnes_roles, voir le
     * docblock de create_campagne_equipe_membres_table.php) ? Ne fait
     * PAS le bypass admin/gestionnaire — c'est le rôle de
     * App\Policies\CampagnePolicy, pas de cette méthode, qui ne répond
     * qu'à la question d'affectation brute.
     */
    public function aRole(int $idPersonne, string $role): bool
    {
        return $this->equipeMembres()
            ->where('id_personne', $idPersonne)
            ->where('role', $role)
            ->exists();
    }

    /**
     * Personnes (modèles Amana\Shared\Models\Personne, connexion
     * 'commun') affectées à CE rôle équipe_* sur CETTE campagne
     * précisément — remplace, le 08/09/2026,
     * Personne::avecRole('equipe_chargement') (rôle GLOBAL, toutes
     * campagnes confondues) dans
     * PackagingController::marquerColisPret()/annulerConditionnement()
     * pour le calcul des destinataires de notification : ces méthodes
     * doivent notifier l'équipe chargement DE CETTE campagne, pas tout
     * le monde ayant un jour coché la case equipe_chargement.
     *
     * Résolution en deux temps comme
     * Admin\Livraison\EquipeMembresController::liste() (jointure
     * cross-connexion en PHP, id_personne → Personne) plutôt qu'une
     * relation Eloquent directe : campagne_equipe_membres vit dans la
     * connexion locale, ref_personnes dans 'commun'.
     */
    public function personnesAvecRole(string $role): Collection
    {
        $idsPersonne = $this->equipeMembres()->where('role', $role)->pluck('id_personne');

        return Personne::whereIn('id', $idsPersonne)->get();
    }

    /**
     * Journal des modifications de poids_moyen_kg/hotel/etudiant — voir
     * create_campagne_poids_moyen_historiques_table.php et le prompt du
     * 05/09/2026 §5.2. Ordonné du plus récent au plus ancien pour
     * affichage direct en timeline (voir CampagneDetail.vue).
     */
    public function poidsMoyenHistorique(): HasMany
    {
        return $this->hasMany(CampagnePoidsMoyenHistorique::class, 'id_campagne')->orderByDesc('horodatage');
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
