<?php
// app/Models/BenevoleDisponibilite.php

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\Personne;
use Amana\Shared\Models\VehiculeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Confirmation de disponibilité d'un bénévole pour une journée de
 * campagne donnée — voir create_benevole_disponibilites_table.php.
 *
 * Rescopée de Campagne vers CampagneJournee le 05/09/2026 (suivi du
 * patch multi-jours du 03/09/2026) : un bénévole confirme séparément
 * pour chaque journée (ex: dispo le jour de collecte, pas le jour de
 * livraison). Pas de relation directe vers Campagne ici — elle reste
 * accessible via `$disponibilite->journee->campagne`.
 *
 * @property int    $id
 * @property int    $id_personne
 * @property int    $id_campagne_journee
 * @property bool   $vehicule_confirme   true = même véhicule que le profil
 * @property bool|null $permis           permis pour CETTE journée (si vehicule_confirme = false)
 * @property int|null  $id_vehicule_type véhicule de CETTE journée (si vehicule_confirme = false)
 * @property bool   $coverage_confirmee  true = même zone que le profil
 * @property string $statut  non_confirme|confirme
 */
class BenevoleDisponibilite extends Model
{
    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = [
        'id_personne', 'id_campagne_journee',
        'vehicule_confirme', 'permis', 'id_vehicule_type', 'coverage_confirmee', 'statut',
    ];

    protected $casts = [
        'vehicule_confirme' => 'boolean',
        'permis' => 'boolean',
        'coverage_confirmee' => 'boolean',
    ];

    public const STATUTS = ['non_confirme', 'confirme'];

    public function personne(): BelongsTo
    {
        return $this->belongsTo(Personne::class, 'id_personne');
    }

    public function journee(): BelongsTo
    {
        return $this->belongsTo(CampagneJournee::class, 'id_campagne_journee');
    }

    public function creneaux(): HasMany
    {
        return $this->hasMany(BenevoleDisponibiliteCreneau::class, 'id_benevole_disponibilite');
    }

    public function secteurs(): HasMany
    {
        return $this->hasMany(BenevoleDisponibiliteSecteur::class, 'id_benevole_disponibilite');
    }

    /**
     * Le bénévole a-t-il déclaré un véhicule PROPRE à cette journée ?
     * Faux pour « même véhicule que mon profil », mais aussi pour une
     * ligne créée sans information véhicule (ex. un gestionnaire n'a
     * modifié que les créneaux) : on retombe alors sur le profil, comme
     * le faisait le routage avant que le véhicule devienne par journée.
     */
    public function aVehiculePropre(): bool
    {
        return !$this->vehicule_confirme && $this->id_vehicule_type !== null;
    }

    /**
     * Véhicule effectif pour cette journée : celui déclaré pour la
     * journée, sinon celui du profil bénévole.
     */
    public function vehiculeEffectif(?\Amana\Shared\Models\BenevoleProfil $profil): ?VehiculeType
    {
        if ($this->aVehiculePropre()) {
            return VehiculeType::find($this->id_vehicule_type);
        }

        return $profil?->vehiculeType;
    }
}
