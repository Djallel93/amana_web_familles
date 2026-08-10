<?php
// app/Models/Famille.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Remplace la feuille "Famille" de l'ancien système Google Apps Script
 * (voir migration 2026_07_12_000004_create_familles_table.php).
 *
 * @property int         $id
 * @property string      $nom
 * @property string      $prenom
 * @property string|null $email
 * @property string      $telephone
 * @property string|null $telephone_bis
 * @property bool        $zakat_el_fitr
 * @property bool        $sadaqa
 * @property int         $nombre_adulte
 * @property int         $nombre_enfant
 * @property string      $adresse
 * @property string|null $code_postal
 * @property string|null $ville_texte
 * @property int|null    $id_quartier
 * @property bool        $se_deplace
 * @property bool        $est_hotel
 * @property string|null $circonstances
 * @property string|null $ressentit
 * @property string|null $specificites
 * @property int         $criticite
 * @property string      $langue
 * @property string      $etat_dossier
 * @property string|null $commentaire_dossier
 * @property string|null $probleme_traitement
 * @property string|null $type_hebergement    organisation | proche | non
 * @property string|null $hosted_by
 * @property string|null $type_piece_identite nationalite | titre_sejour | demande_asile | autre
 * @property string|null $type_activite       temps_plein | temps_partiel | non
 * @property int|null    $work_days
 * @property string|null $secteur_activite_autre
 * @property string|null $organisme_aide_autre
 * @property string|null $google_resource_name
 */
class Famille extends Model
{
    use HasFactory;

    /**
     * Sans cette déclaration explicite, Eloquent ferait hériter à ce modèle
     * la connexion de Quartier ('commun') dès qu'il est chargé via la
     * relation Quartier::familles() (voir app/Models/Quartier.php) —
     * Illuminate\Database\Eloquent\Concerns\HasRelationships::
     * newRelatedInstance() copie la connexion du modèle PARENT sur le
     * modèle lié si ce dernier n'en déclare pas une à lui. familles vit
     * dans amana_familles (connexion par défaut), pas dans amana_commun.
     */
    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = [
        'nom', 'prenom', 'email', 'telephone', 'telephone_bis',
        'zakat_el_fitr', 'sadaqa',
        'nombre_adulte', 'nombre_enfant',
        'adresse', 'code_postal', 'ville_texte', 'id_quartier', 'se_deplace', 'est_hotel',
        'circonstances', 'ressentit', 'specificites', 'criticite', 'langue',
        'etat_dossier', 'commentaire_dossier', 'probleme_traitement',
        'type_hebergement', 'hosted_by',
        'type_piece_identite',
        'type_activite', 'work_days', 'secteur_activite_autre',
        'organisme_aide_autre',
        'google_resource_name',
    ];

    protected $casts = [
        'zakat_el_fitr' => 'boolean',
        'sadaqa' => 'boolean',
        'se_deplace' => 'boolean',
        'est_hotel' => 'boolean',
        'nombre_adulte' => 'integer',
        'nombre_enfant' => 'integer',
        'criticite' => 'integer',
        'work_days' => 'integer',
    ];

    public const ETATS = ['Recu', 'En cours', 'En attente', 'Validé', 'Rejeté', 'Archivé'];
    // 'Recu' est exclusif à la soumission du formulaire public (voir
    // IntakeController::store) — jamais sélectionnable manuellement par le
    // staff, qui travaille depuis "Nouvelles demandes" plutôt que d'y
    // revenir. Utilisé pour la validation de FamillesController::update()
    // et le <select> de statut de DetailPanel.vue — demande du 09/08/2026.
    public const ETATS_MODIFIABLES = ['En cours', 'En attente', 'Validé', 'Rejeté', 'Archivé'];
    public const LANGUES = ['fr' => 'Français', 'ar' => 'العربية', 'en' => 'English'];
    public const TYPES_HEBERGEMENT = ['organisation', 'proche', 'non'];
    public const TYPES_PIECE_IDENTITE = ['nationalite', 'titre_sejour', 'demande_asile', 'autre'];
    public const TYPES_ACTIVITE = ['temps_plein', 'temps_partiel', 'non'];

    // ── Relations ─────────────────────────────────────────────────────────

    public function quartier(): BelongsTo
    {
        return $this->belongsTo(Quartier::class, 'id_quartier');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FamilleDocument::class, 'id_famille');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(FamilleVerification::class, 'id_famille');
    }

    public function secteursActivite(): BelongsToMany
    {
        return $this->belongsToMany(SecteurActivite::class, 'famille_secteur_activite', 'id_famille', 'id_secteur_activite');
    }

    public function organismesAide(): BelongsToMany
    {
        return $this->belongsToMany(OrganismeAide::class, 'famille_organisme_aide', 'id_famille', 'id_organisme_aide');
    }

    // ── Accesseurs ────────────────────────────────────────────────────────

    public function getNombreFoyerAttribute(): int
    {
        return $this->nombre_adulte + $this->nombre_enfant;
    }

    /**
     * Formate le téléphone principal au format français d'affichage
     * "+33 X XX XX XX XX", quel que soit le format de saisie d'origine
     * (0X XX XX XX XX, +33X XX XX XX XX, avec ou sans espaces/points/tirets).
     * Retourne la valeur brute telle quelle si elle ne ressemble pas à un
     * numéro français valide (ex : numéro étranger), pour ne rien masquer.
     */
    public function getTelephoneFormateAttribute(): ?string
    {
        return self::formaterTelephoneFr($this->telephone);
    }

    public function getTelephoneBisFormateAttribute(): ?string
    {
        return $this->telephone_bis ? self::formaterTelephoneFr($this->telephone_bis) : null;
    }

    public static function formaterTelephoneFr(?string $telephone): ?string
    {
        if (!$telephone) {
            return null;
        }

        $chiffres = preg_replace('/\D/', '', $telephone);

        // Normalise vers 9 chiffres significatifs (sans le 0 ou le 33 initial).
        if (str_starts_with($chiffres, '33') && strlen($chiffres) === 11) {
            $chiffres = substr($chiffres, 2);
        } elseif (str_starts_with($chiffres, '0') && strlen($chiffres) === 10) {
            $chiffres = substr($chiffres, 1);
        }

        if (strlen($chiffres) !== 9) {
            return $telephone; // Format non reconnu (ex : numéro étranger) — affiché tel quel.
        }

        $groupes = [substr($chiffres, 0, 1)];
        for ($i = 1; $i < 9; $i += 2) {
            $groupes[] = substr($chiffres, $i, 2);
        }

        return '+33 ' . implode(' ', $groupes);
    }

    public function getDocumentsIdentiteManquantsAttribute(): bool
    {
        return !$this->documents()->where('type', 'identity')->exists();
    }

    /**
     * Type de document (caf|ame) attendu à l'étape "Situation administrative"
     * du formulaire d'intake, déterminé par type_piece_identite — reprend le
     * branchement du Google Form historique : Nationalité / Titre de séjour /
     * Demande d'asile → CAF, Autre → AME.
     */
    public function getTypeDocumentAideAttribute(): ?string
    {
        if (!$this->type_piece_identite) {
            return null;
        }

        return $this->type_piece_identite === 'autre' ? 'ame' : 'caf';
    }

    // ── Scopes de filtre (vue principale — section 8.2) ──────────────────

    public function scopeRecherche($query, ?string $terme)
    {
        if (!$terme) {
            return $query;
        }

        return $query->where(function ($q) use ($terme) {
            $q->where('nom', 'like', "%{$terme}%")
                ->orWhere('prenom', 'like', "%{$terme}%")
                ->orWhere('telephone', 'like', "%{$terme}%")
                ->orWhere('telephone_bis', 'like', "%{$terme}%");
        });
    }

    public function scopeDocumentsIdentiteManquants($query)
    {
        return $query->whereDoesntHave('documents', fn($q) => $q->where('type', 'identity'));
    }
}
