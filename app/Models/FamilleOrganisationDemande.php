<?php
// app/Models/FamilleOrganisationDemande.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande de rattachement d'une organisation à un dossier famille déjà
 * existant (rattaché à une AUTRE organisation) — voir migration
 * create_famille_organisation_demandes_table et
 * FamilleOrganisationDemandeService pour le cycle de vie complet.
 *
 * @property int         $id
 * @property int         $id_famille
 * @property int         $id_organisation
 * @property string      $source        intake | import | manuel
 * @property int|null    $submitted_by
 * @property array|null  $donnees_soumises
 * @property string      $statut        en_attente | validee | rejetee
 * @property int|null    $traite_par
 * @property \Carbon\Carbon|null $traite_le
 */
class FamilleOrganisationDemande extends Model
{
    protected $fillable = [
        'id_famille', 'id_organisation', 'source', 'submitted_by', 'donnees_soumises',
    ];

    protected $casts = [
        'donnees_soumises' => 'array',
        'traite_le' => 'datetime',
    ];

    public const SOURCES = ['intake', 'import', 'manuel'];
    public const STATUTS = ['en_attente', 'validee', 'rejetee'];

    public function famille(): BelongsTo
    {
        return $this->belongsTo(Famille::class, 'id_famille');
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'id_organisation');
    }

    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'en_attente');
    }
}
