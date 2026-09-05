<?php
// app/Models/BenevoleDisponibilite.php

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\Personne;
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
 * @property bool   $vehicule_confirme
 * @property bool   $coverage_confirmee
 * @property string|null $coverage_notes
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
        'vehicule_confirme', 'coverage_confirmee', 'coverage_notes', 'statut',
    ];

    protected $casts = [
        'vehicule_confirme' => 'boolean',
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
}
