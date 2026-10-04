<?php
// app/Models/PersonneDesactivee.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Compte désactivé POUR AMANA FAMILLES (03/10/2026) — voir la migration
 * create_livraison_equipe_tables.php (table personnes_desactivees) pour le
 * pourquoi : accès coupé et exclusion des futures campagnes, historique
 * et rôles conservés, sans toucher à ref_personnes (base commun partagée
 * avec les autres apps AMANA).
 *
 * Les personnes vivent dans la connexion 'commun', cette table dans la
 * connexion locale : pas de jointure SQL possible, d'où les helpers
 * ci-dessous (liste d'ids à passer en whereNotIn() côté appelant — le
 * staff est de l'ordre de quelques dizaines de personnes).
 *
 * @property int         $id
 * @property int         $id_personne
 * @property int|null    $desactivee_par
 * @property \Illuminate\Support\Carbon $desactivee_at
 */
class PersonneDesactivee extends Model
{
    protected $table = 'personnes_desactivees';

    public $timestamps = false;

    protected $fillable = ['id_personne', 'desactivee_par', 'desactivee_at'];

    protected $casts = [
        'desactivee_at' => 'datetime',
    ];

    /**
     * @return int[]
     */
    public static function ids(): array
    {
        return static::query()->pluck('id_personne')->map(fn ($id) => (int) $id)->all();
    }

    public static function estDesactivee(int $idPersonne): bool
    {
        return static::query()->where('id_personne', $idPersonne)->exists();
    }
}
