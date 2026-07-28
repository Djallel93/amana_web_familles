<?php
// app/Services/GoogleGeocodingService.php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GeocodingConfigException;
use App\Exceptions\GeocodingTransientException;
use Illuminate\Support\Facades\Http;

/**
 * Intégration directe Google Maps Geocoding API — remplace l'ancien webhook
 * Make.com de géocodage (décision du 17/07/2026, cf. ResoudreAdresseFamille).
 *
 * Contrairement à GoogleContactsService (People API, gratuite, OAuth 2.0 lié
 * à un compte Google précis), la Geocoding API est un endpoint REST au
 * niveau du projet GCP : authentification par simple clé API en query
 * string (pas de refresh token, pas de ref_settings) — mais nécessite un
 * compte de facturation activé sur le projet (pas de palier gratuit),
 * contrairement à People API. Voir décisions du 17/07/2026.
 *
 * Appel HTTP via la façade Http (pas de SDK google/apiclient — inutile ici,
 * c'est un simple GET avec clé en query string), dans la continuité du
 * pattern webhook déjà utilisé (EnvoyerWebhookMake, amana_web_planning)
 * pour la résilience/retry.
 */
class GoogleGeocodingService
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /**
     * Statuts Google traités comme des échecs transitoires — un nouvel
     * essai a une chance raisonnable de réussir (quota qui se libère,
     * incident ponctuel côté Google).
     */
    private const STATUTS_TRANSITOIRES = ['OVER_QUERY_LIMIT', 'UNKNOWN_ERROR'];

    /**
     * Statuts Google traités comme des erreurs de configuration — retenter
     * ne changera rien tant qu'une intervention manuelle n'a pas eu lieu
     * (clé API absente/invalide/restreinte, requête malformée).
     */
    private const STATUTS_CONFIGURATION = ['REQUEST_DENIED', 'INVALID_REQUEST'];

    /**
     * true si une clé API Google Maps Geocoding est configurée. Le job de
     * résolution s'appuie dessus pour échouer proprement (log + skip, pas
     * de retry inutile) si la clé n'a pas encore été renseignée en .env.
     */
    public function isConfigured(): bool
    {
        return (bool) config('services.google.maps.geocoding_api_key');
    }

    /**
     * Géocode une adresse brute (adresse, code_postal, ville_texte) via
     * l'API Google Maps Geocoding.
     *
     * @throws GeocodingTransientException en cas d'échec HTTP, de statut
     *         Google transitoire (OVER_QUERY_LIMIT, UNKNOWN_ERROR), ou de
     *         statut non documenté par l'API — à traiter comme une raison
     *         de retry par l'appelant.
     * @throws GeocodingConfigException en cas de clé API non configurée ou
     *         de statut Google de configuration (REQUEST_DENIED,
     *         INVALID_REQUEST) — inutile de retenter tant que la cause
     *         n'est pas corrigée manuellement.
     */
    public function geocoder(string $adresse, string $codePostal, string $ville): GeocodingResultat
    {
        $apiKey = config('services.google.maps.geocoding_api_key');

        if (!$apiKey) {
            throw new GeocodingConfigException('Clé API Google Maps Geocoding non configurée.');
        }

        // Un seul paramètre address concaténé (plutôt que components=), qui
        // biaise sans exclure — un components=country:FR filtrerait
        // purement et simplement toute adresse dont le format ne matcherait
        // pas, plus risqué sur du texte libre saisi par les familles.
        $adresseComplete = trim("{$adresse}, {$codePostal} {$ville}");

        $reponse = Http::timeout(15)->get(self::ENDPOINT, [
            'address' => $adresseComplete,
            'region' => 'fr',
            'language' => 'fr',
            'key' => $apiKey,
        ]);

        if ($reponse->failed()) {
            throw new GeocodingTransientException(
                'Échec HTTP Google Maps Geocoding : ' . $reponse->status()
            );
        }

        $data = $reponse->json();
        $statut = $data['status'] ?? 'UNKNOWN_ERROR';

        if ($statut === 'OK') {
            $resultat = $data['results'][0] ?? null;

            if (!$resultat) {
                // Défensif : Google ne devrait jamais renvoyer OK sans
                // results[0], mais on ne fait pas confiance aveuglément à
                // un tiers pour la cohérence de sa propre réponse.
                throw new GeocodingTransientException(
                    'Statut OK reçu de Google Maps Geocoding sans results[0].'
                );
            }

            return new GeocodingResultat(
                trouve: true,
                latitude: (float) $resultat['geometry']['location']['lat'],
                longitude: (float) $resultat['geometry']['location']['lng'],
                formattedAddress: $resultat['formatted_address'] ?? null,
                statutBrut: $statut,
            );
        }

        if ($statut === 'ZERO_RESULTS') {
            return new GeocodingResultat(trouve: false, statutBrut: $statut);
        }

        if (in_array($statut, self::STATUTS_CONFIGURATION, true)) {
            throw new GeocodingConfigException(
                "Statut de configuration Google Maps Geocoding : {$statut}"
            );
        }

        if (in_array($statut, self::STATUTS_TRANSITOIRES, true)) {
            throw new GeocodingTransientException(
                "Statut transitoire Google Maps Geocoding : {$statut}"
            );
        }

        // Statut non documenté par l'API à ce jour — traité par prudence
        // comme transitoire (mieux vaut un retry inutile qu'un échec
        // silencieux sur un statut Google encore inconnu).
        throw new GeocodingTransientException(
            "Statut Google Maps Geocoding non documenté : {$statut}"
        );
    }
}
