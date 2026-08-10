<?php
// app/Models/SecteurActivite.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Liste fermée des secteurs d'activité (formulaire d'intake, section
 * "Activité professionnelle" — question CHECKBOX du Google Form
 * historique). Table plutôt que colonne JSON pour pouvoir ajouter/désactiver
 * des entrées depuis l'admin plus tard sans migration (décision 09/08/2026).
 *
 * @property int    $id
 * @property string $code
 * @property string $libelle_fr
 * @property string $libelle_ar
 * @property string $libelle_en
 * @property bool   $actif
 * @property int    $ordre
 */
class SecteurActivite extends Model
{
    // Sans ceci, Eloquent déduirait 'secteur_activites' (pluriel du nom de
    // classe en snake_case) alors que la migration crée 'secteurs_activite'
    // (pluriel "à la française", cohérent avec famille_secteur_activite).
    protected $table = 'secteurs_activite';

    protected $fillable = ['code', 'libelle_fr', 'libelle_ar', 'libelle_en', 'actif', 'ordre'];

    protected $casts = [
        'actif' => 'boolean',
        'ordre' => 'integer',
    ];

    public function familles(): BelongsToMany
    {
        return $this->belongsToMany(Famille::class, 'famille_secteur_activite', 'id_secteur_activite', 'id_famille');
    }

    public function scopeActifs($query)
    {
        return $query->where('actif', true)->orderBy('ordre');
    }

    /**
     * Libellé dans la langue demandée (fr par défaut) — utilisé pour
     * peupler le formulaire d'intake multilingue.
     */
    public function libelle(string $langue): string
    {
        return match ($langue) {
            'ar' => $this->libelle_ar,
            'en' => $this->libelle_en,
            default => $this->libelle_fr,
        };
    }
}
