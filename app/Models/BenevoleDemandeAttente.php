<?php
// app/Models/BenevoleDemandeAttente.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Soumission du formulaire public de candidature bénévole en attente de
 * confirmation par email — miroir d'IntakeDemandeAttente, voir migration
 * create_benevole_demandes_attente_table.
 *
 * @property int    $id
 * @property string $token
 * @property string $langue
 * @property array  $donnees          nom/prenom/email/telephone/permis/id_vehicule_type/zone_livraison
 * @property array|null $secteurs         IDs de secteurs (hors $donnees)
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 */
class BenevoleDemandeAttente extends Model
{
    protected $table = 'benevole_demandes_attente';

    public $timestamps = false;

    protected $fillable = [
        'token', 'langue', 'donnees', 'secteurs', 'expires_at', 'confirmed_at',
    ];

    protected $casts = [
        'donnees' => 'array',
        'secteurs' => 'array',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function estExpiree(): bool
    {
        return $this->expires_at->isPast();
    }

    public function estConfirmee(): bool
    {
        return $this->confirmed_at !== null;
    }
}
