<?php
// app/Services/GeocodingResultat.php

declare(strict_types=1);

namespace App\Services;

/**
 * Résultat d'un appel GoogleGeocodingService::geocoder() pour les deux
 * issues "métier" (ni échec HTTP, ni erreur de configuration) :
 *
 *   - trouve = true  → statut Google "OK", latitude/longitude/
 *     formattedAddress renseignés.
 *   - trouve = false → statut Google "ZERO_RESULTS", adresse non
 *     géocodable — traité par l'appelant comme l'ancien cas Make.com
 *     { error: ... }, pas comme une panne.
 *
 * statutBrut est conservé pour les logs/audit_logs (traçabilité du statut
 * Google exact reçu, même quand il correspond à une issue "métier").
 */
final class GeocodingResultat
{
    public function __construct(
        public readonly bool $trouve,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?string $formattedAddress = null,
        public readonly ?string $statutBrut = null,
    ) {
    }
}
