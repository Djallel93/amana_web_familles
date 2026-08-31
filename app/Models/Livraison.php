<?php
// app/Models/Livraison.php

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\Personne;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une famille bénéficiaire, dans une campagne donnée — le pivot central du
 * domaine livraison, côté "sortie" (voir Campagne pour le côté "entrée" :
 * arrivees()/donations(), entièrement séparé de cette table). Voir le
 * prompt du 30/08/2026 §2 pour le détail complet de chaque colonne.
 *
 * @property int         $id
 * @property int         $id_famille
 * @property int         $id_campagne
 * @property string      $statut                  non_assignee|assignee|en_cours|livree|ignoree
 * @property string      $statut_conditionnement  en_attente|prete
 * @property int         $nombre_personnes
 * @property float       $poids_kg
 * @property int|null    $id_benevole_impose
 * @property string|null $note_besoins_speciaux
 * @property string      $statut_contact          a_contacter|contacte|injoignable|confirme
 * @property int|null    $id_personne_assignee
 * @property string|null $adresse_confirmee
 * @property int|null    $membres_foyer_confirmes
 * @property int|null    $locked_by
 * @property \Illuminate\Support\Carbon|null $locked_at
 */
class Livraison extends Model
{
    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = [
        'id_famille', 'id_campagne',
        'statut', 'statut_conditionnement',
        'nombre_personnes', 'poids_kg',
        'id_benevole_impose',
        'note_besoins_speciaux',
        'statut_contact', 'id_personne_assignee',
        'adresse_confirmee', 'membres_foyer_confirmes',
    ];

    protected $casts = [
        'nombre_personnes' => 'integer',
        'poids_kg' => 'decimal:2',
        'membres_foyer_confirmes' => 'integer',
        'locked_at' => 'datetime',
    ];

    public const STATUTS = ['non_assignee', 'assignee', 'en_cours', 'livree', 'ignoree'];
    public const STATUTS_CONDITIONNEMENT = ['en_attente', 'prete'];
    public const STATUTS_CONTACT = ['a_contacter', 'contacte', 'injoignable', 'confirme'];

    // Même filet de sécurité que Famille::VERROU_TTL_MINUTES.
    public const VERROU_TTL_MINUTES = 20;

    // ── Relations ─────────────────────────────────────────────────────────

    public function famille(): BelongsTo
    {
        return $this->belongsTo(Famille::class, 'id_famille');
    }

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'id_campagne');
    }

    public function creneaux(): HasMany
    {
        return $this->hasMany(LivraisonCreneau::class, 'id_livraison');
    }

    public function contactTokens(): HasMany
    {
        return $this->hasMany(ContactToken::class, 'id_livraison');
    }

    public function etapesRoute(): HasMany
    {
        return $this->hasMany(EtapeRoute::class, 'id_livraison');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(RouteIncident::class, 'id_livraison');
    }

    /**
     * Livraison "imposée" à ce bénévole précis — résolue avant clustering,
     * exemptée de toute vérification créneau (voir Patch 3).
     */
    public function benevoleImpose(): BelongsTo
    {
        return $this->belongsTo(Personne::class, 'id_benevole_impose');
    }

    /**
     * Qui appelle cette famille pour confirmation téléphonique — doit être
     * gestionnaire (ou admin, cascade existante), vérifié côté application
     * (voir contrôleur, Patch 2), pas en contrainte DB.
     */
    public function personneAssignee(): BelongsTo
    {
        return $this->belongsTo(Personne::class, 'id_personne_assignee');
    }
}
