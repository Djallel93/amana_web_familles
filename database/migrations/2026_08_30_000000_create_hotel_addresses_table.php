<?php
// database/migrations/2026_08_30_000000_create_hotel_addresses_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table hotel_addresses.
 *
 * Ajoutée le 30/08/2026 — référentiel des adresses d'hébergement d'urgence
 * (hôtels/appart-hôtels) connues du staff. Sert à forcer automatiquement
 * `familles.est_hotel` à true quand l'adresse saisie par une famille (ou
 * importée) correspond à une entrée de cette liste, même si la case
 * "hôtel" n'a pas été cochée dans le formulaire — voir
 * FamilleUpsertService::upsert().
 *
 * `adresse` : libellé tel que fourni par le staff — peut être une adresse
 * brute ("12 Rue de la Johardière, 44800 Saint-Herblain") ou une adresse
 * préfixée du nom de l'établissement telle que renvoyée par l'autocomplétion
 * Google Places pour un lieu (POI) ("Appart Hôtel - Residhome Nantes Berges
 * de la Loire, 44000 Nantes") — les deux formes sont volontairement
 * autorisées en parallèle : selon le canal de saisie (autocomplétion
 * Google vs saisie manuelle), l'adresse d'une famille peut prendre l'une
 * ou l'autre forme. Un même établissement peut aussi avoir plusieurs
 * lignes (plusieurs adresses réelles pour un même hôtel).
 *
 * `adresse_normalisee` : forme normalisée de `adresse` (minuscules, accents
 * retirés, ponctuation réduite à des espaces, espaces multiples réduits) —
 * calculée à l'écriture (voir HotelAddress::normaliser()), stockée plutôt
 * que recalculée à chaque comparaison pour permettre un index et une
 * recherche SQL directe depuis FamilleUpsertService::upsert().
 *
 * Unique (depuis le 05/09/2026, décision : empêcher la saisie deux fois de
 * la même adresse hôtel) plutôt qu'un simple index — la comparaison se
 * fait volontairement sur la forme NORMALISÉE, pas sur `adresse` brute :
 * deux adresses réellement différentes pour un même établissement
 * (plusieurs bâtiments, ou forme "rue" vs forme "POI Google", voir plus
 * haut) restent toutes les deux acceptées, seule une resaisie strictement
 * identique une fois normalisée est bloquée. Voir
 * Admin\HotelAddressesController::store()/update() pour le message
 * d'erreur convivial rendu avant que cette contrainte ne soit atteinte.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hotel_addresses', function (Blueprint $table) {
            $table->id();
            $table->string('adresse', 255)
                ->comment('Libellé affiché au staff — brut ou préfixé du nom de l\'établissement (autocomplétion Google Places)');
            $table->string('adresse_normalisee', 255)
                ->comment('Forme normalisée de `adresse` (minuscules, sans accents/ponctuation) — voir HotelAddress::normaliser()');
            $table->unique('adresse_normalisee');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_addresses');
    }
};
