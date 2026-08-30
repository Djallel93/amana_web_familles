<?php
// app/Models/HotelAddress.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Référentiel des adresses d'hébergement d'urgence (hôtels/appart-hôtels)
 * connues du staff — voir migration create_hotel_addresses_table pour le
 * raisonnement complet. Consommé par FamilleUpsertService::upsert() pour
 * forcer `familles.est_hotel` à true, et par Admin\HotelAddressesController
 * (section "Adresses hôtel" de l'écran Paramètres) pour la gestion CRUD.
 *
 * @property int    $id
 * @property string $adresse
 * @property string $adresse_normalisee
 */
class HotelAddress extends Model
{
    protected $fillable = ['adresse', 'adresse_normalisee'];

    /**
     * `adresse_normalisee` est toujours dérivée de `adresse`, jamais saisie
     * directement — recalculée automatiquement à chaque set/save plutôt que
     * laissée à la charge de chaque appelant (store()/update() du
     * contrôleur, seeder), pour ne jamais risquer une désynchronisation
     * entre les deux colonnes.
     */
    protected static function booted(): void
    {
        static::saving(function (self $hotelAddress) {
            $hotelAddress->adresse_normalisee = self::normaliser($hotelAddress->adresse);
        });
    }

    /**
     * Normalisation utilisée à la fois ici (colonne `adresse_normalisee`)
     * et côté FamilleUpsertService::upsert() pour comparer l'adresse d'une
     * famille à ce référentiel : minuscules, accents retirés (Str::ascii,
     * ex. "Herblain" reste identique mais "Théâtre" → "Theatre"),
     * ponctuation/séparateurs (virgules, apostrophes, tirets, slashes...)
     * réduits à des espaces, espaces multiples réduits à un seul, bords
     * coupés. Volontairement permissive plutôt que stricte : le but est de
     * tolérer les petites variations de saisie ("St Herblain" / "Saint-
     * Herblain", virgule ou non...), pas de faire une correspondance
     * sémantique complète — voir la comparaison en containment (pas
     * seulement en égalité) dans FamilleUpsertService.
     */
    public static function normaliser(?string $valeur): string
    {
        $ascii = Str::ascii((string) $valeur);
        $sansPonctuation = preg_replace('/[^a-z0-9]+/i', ' ', $ascii) ?? '';

        return trim(preg_replace('/\s+/', ' ', strtolower($sansPonctuation)) ?? '');
    }
}
