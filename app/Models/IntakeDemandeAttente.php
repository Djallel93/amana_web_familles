<?php
// app/Models/IntakeDemandeAttente.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Soumission du formulaire public d'intake en attente de confirmation par
 * email — voir migration 2026_08_11_000000_create_intake_demandes_attente_table
 * et IntakeController::store() / IntakeConfirmationController::confirmer().
 *
 * @property int    $id
 * @property string $token  hash sha256 (voir App\Support\TokenHasher) — jamais le jeton en clair
 * @property string $langue
 * @property array  $donnees           Champs validés, mêmes clés que Famille::$fillable
 * @property array|null $secteurs_activite  IDs (belongsToMany, hors $donnees)
 * @property array|null $organismes_aide    IDs (belongsToMany, hors $donnees)
 * @property array|null $documents_meta     Métadonnées fichiers, indexées "slot:index"
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 */
class IntakeDemandeAttente extends Model
{
    protected $table = 'intake_demandes_attente';

    public $timestamps = false;

    protected $fillable = [
        'token', 'langue', 'donnees', 'secteurs_activite', 'organismes_aide',
        'documents_meta', 'expires_at', 'confirmed_at',
    ];

    protected $casts = [
        'donnees' => 'array',
        'secteurs_activite' => 'array',
        'organismes_aide' => 'array',
        'documents_meta' => 'array',
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

    /**
     * Chemin du dossier de stockage temporaire des fichiers de cette
     * soumission, sur le disque 'local' (storage/app/private/...) — voir
     * IntakeAttenteService::stockerFichiersAttente() et
     * IntakeAttenteService::confirmer().
     *
     * Basé sur l'id (et non plus le token) depuis le 31/08/2026 :
     * `token` ne contient plus qu'un hash (voir App\Support\TokenHasher),
     * impropre à servir de nom de dossier lisible/stable, et l'id est de
     * toute façon disponible dès la création de la ligne (voir
     * IntakeAttenteService::creerDemande(), qui crée la ligne AVANT de
     * stocker les fichiers, pour disposer de cet id).
     */
    public function cheminStockageTemporaire(): string
    {
        return "intake-attente/{$this->id}";
    }
}
