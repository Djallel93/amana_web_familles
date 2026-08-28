<?php
// app/Models/Organisation.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * Organisation partenaire pouvant enregistrer des familles dans cette app
 * — voir migration create_organisations_table pour le raisonnement
 * complet (notamment la distinction avec OrganismeAide, qui répond à une
 * question totalement différente du formulaire d'intake).
 *
 * Local à amana_web_familles par décision du 28/08/2026 — pas de
 * pendant dans amana_shared, contrairement à Application/Role.
 *
 * @property int    $id
 * @property string $code
 * @property string $nom
 * @property bool   $actif
 * @property bool   $est_principale
 */
class Organisation extends Model
{
    protected $fillable = ['code', 'nom', 'actif'];
    // 'est_principale' volontairement absent du fillable : ce flag ne se
    // pose qu'à la création de la ligne AMANA (voir migration), jamais
    // via un formulaire — Admin\OrganisationsController ne l'expose pas.

    protected $casts = [
        'actif' => 'boolean',
        'est_principale' => 'boolean',
    ];

    // ── Relations ─────────────────────────────────────────────────────────

    public function familles(): BelongsToMany
    {
        return $this->belongsToMany(Famille::class, 'famille_organisation', 'id_organisation', 'id_famille')
            ->withPivot('rattachee_le');
    }

    public function demandesRattachement(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FamilleOrganisationDemande::class, 'id_organisation');
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeActifs($query)
    {
        return $query->where('actif', true);
    }

    // ── Accès (Personne ↔ Organisation, cross-DB par convention) ──────────

    /**
     * L'organisation AMANA elle-même — utilisée comme valeur par défaut
     * pour les dossiers/imports créés en interne (pas de gestionnaire_externe
     * impliqué). Voir migration create_organisations_table.
     */
    public static function principale(): ?self
    {
        return static::where('est_principale', true)->first();
    }

    /**
     * IDs des organisations auxquelles une Personne (ref_personnes,
     * amana_commun) est rattachée — table personne_organisation, locale à
     * cette base. Requête directe sur la table plutôt qu'une relation
     * Eloquent sur Personne (package amana_shared) : Organisation reste
     * volontairement local à cette app (voir migration), une relation
     * cross-connexion sur le modèle partagé romprait cette séparation.
     *
     * @return int[]
     */
    public static function idsPourPersonne(int $idPersonne): array
    {
        return DB::table('personne_organisation')
            ->where('id_personne', $idPersonne)
            ->pluck('id_organisation')
            ->all();
    }

    public static function syncPersonne(int $idPersonne, array $idsOrganisation): void
    {
        DB::table('personne_organisation')->where('id_personne', $idPersonne)->delete();

        $maintenant = now()->toDateString();
        $lignes = collect($idsOrganisation)->unique()->map(fn($idOrganisation) => [
            'id_personne' => $idPersonne,
            'id_organisation' => $idOrganisation,
            'date_attribution' => $maintenant,
        ])->all();

        if ($lignes) {
            DB::table('personne_organisation')->insert($lignes);
        }
    }
}
