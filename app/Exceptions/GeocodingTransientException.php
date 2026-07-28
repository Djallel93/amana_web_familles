<?php
// app/Exceptions/GeocodingTransientException.php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Échec transitoire lors d'un appel à Google Maps Geocoding — un nouvel
 * essai a une chance raisonnable de réussir (échec HTTP, quota
 * temporairement dépassé, incident ponctuel côté Google, ou statut non
 * documenté par l'API).
 *
 * Levée par GoogleGeocodingService::geocoder(), attrapée par
 * ResoudreAdresseFamille::handle() pour déclencher $this->fail() (retry).
 */
class GeocodingTransientException extends \RuntimeException
{
}
