<?php
// app/Models/OrganismeAide.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Liste fermée des organismes d'aide tiers (formulaire d'intake, section
 * "Ressources" — "Percevez-vous actuellement des aides d'autres organismes ?"
 * du Google Form historique). Voir SecteurActivite pour le choix
 * table-plutôt-que-JSON.
 *
 * @property int    $id
 * @property string $code
 * @property string $libelle_fr
 * @property string $libelle_ar
 * @property string $libelle_en
 * @property bool   $actif
 * @property int    $ordre
 */
class OrganismeAide extends Model
{
    // Idem SecteurActivite : Eloquent déduirait 'organisme_aides', la
    // migration crée 'organismes_aide'.
    protected $table = 'organismes_aide';

    protected $fillable = ['code', 'libelle_fr', 'libelle_ar', 'libelle_en', 'actif', 'ordre'];

    protected $casts = [
        'actif' => 'boolean',
        'ordre' => 'integer',
    ];

    public function familles(): BelongsToMany
    {
        return $this->belongsToMany(Famille::class, 'famille_organisme_aide', 'id_organisme_aide', 'id_famille');
    }

    public function scopeActifs($query)
    {
        return $query->where('actif', true)->orderBy('ordre');
    }

    public function libelle(string $langue): string
    {
        return match ($langue) {
            'ar' => $this->libelle_ar,
            'en' => $this->libelle_en,
            default => $this->libelle_fr,
        };
    }
}
