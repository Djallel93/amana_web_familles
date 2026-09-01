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
 * @property string|null $code_postal_confirme
 * @property string|null $ville_confirmee
 * @property int|null    $nombre_adulte_confirme
 * @property int|null    $nombre_enfant_confirme
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
        'adresse_confirmee', 'code_postal_confirme', 'ville_confirmee',
        'nombre_adulte_confirme', 'nombre_enfant_confirme',
    ];

    protected $casts = [
        'nombre_personnes' => 'integer',
        'poids_kg' => 'decimal:2',
        'nombre_adulte_confirme' => 'integer',
        'nombre_enfant_confirme' => 'integer',
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

    // ── Calcul du poids (voir Patch 2 — LivraisonGenerationService) ────────

    /**
     * Poids total (kg) pour une famille dans une campagne — porté depuis
     * routeAssignmentService.js (amana_livraison) :
     * `poidsLiv = parts * (estHotel ? poids_moyen_hotel_kg : poids_moyen_kg)`,
     * "parts" = nombre_personnes (une part = un colis par personne, décision
     * du 31/08/2026 : "each person gets one package").
     *
     * Étend le taux hôtel du legacy à un taux étudiant symétrique
     * (poids_moyen_etudiant_kg, ajouté le 31/08/2026 — absent de l'ancien
     * système). Repli sur poids_moyen_kg si le taux spécifique est à 0/non
     * renseigné, même comportement que le fallback déjà présent côté
     * legacy pour poids_moyen_hotel_kg.
     *
     * PRÉCONDITION : ne doit JAMAIS être appelée pour une famille à la
     * fois etudiant ET est_hotel — ce cas est une anomalie de données à
     * détecter et signaler EN AMONT (voir
     * LivraisonGenerationService::genererPour(), qui exclut ces familles
     * avant d'atteindre cette méthode) plutôt que résolue silencieusement
     * ici par un ordre de priorité arbitraire (décision du 31/08/2026).
     * Lève une exception si appelée quand même, pour ne jamais masquer
     * l'anomalie derrière un calcul qui semblerait normal.
     */
    public static function calculerPoidsKg(Famille $famille, Campagne $campagne, int $nombrePersonnes): float
    {
        if ($famille->etudiant && $famille->est_hotel) {
            throw new \LogicException(
                "Famille #{$famille->id} : etudiant et est_hotel simultanément — anomalie devant être exclue avant l'appel à calculerPoidsKg(), voir LivraisonGenerationService."
            );
        }

        $taux = match (true) {
            $famille->est_hotel => (float) ($campagne->poids_moyen_hotel_kg ?: $campagne->poids_moyen_kg),
            $famille->etudiant => (float) ($campagne->poids_moyen_etudiant_kg ?: $campagne->poids_moyen_kg),
            default => (float) $campagne->poids_moyen_kg,
        };

        return $nombrePersonnes * $taux;
    }
}
