<?php
// app/Exceptions/GeocodingConfigException.php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Erreur de configuration lors d'un appel à Google Maps Geocoding —
 * retenter ne changera rien tant qu'une intervention manuelle n'a pas eu
 * lieu (clé API absente/invalide/restreinte : REQUEST_DENIED, ou requête
 * malformée : INVALID_REQUEST).
 *
 * Levée par GoogleGeocodingService::geocoder(), attrapée par
 * ResoudreAdresseFamille::handle() pour logger + auditer sans retry
 * (contrairement à GeocodingTransientException).
 */
class GeocodingConfigException extends \RuntimeException
{
}
