<?php
// app/Jobs/ResoudreAdresseFamille.php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\GeocodingConfigException;
use App\Exceptions\GeocodingTransientException;
use App\Models\Famille;
use App\Services\GoogleGeocodingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job asynchrone : résout id_quartier pour une famille à partir de son
 * adresse brute (adresse, code_postal, ville_texte).
 *
 * Remplace geocodingService.js (amana_geo) — voir section 5 du prompt de
 * migration. Deux étapes :
 *
 *   1. Géocodage adresse → lat/lng via l'API Google Maps Geocoding
 *      (GoogleGeocodingService) — intégration directe qui remplace le
 *      webhook Make.com dédié utilisé jusqu'au 17/07/2026 (décision 6.5 /
 *      section 5, révisée le 17/07/2026 : le coût de facturation GCP est
 *      accepté vu le faible volume).
 *
 *   2. Résolution point-in-polygon en SQL : ST_Contains(villes.boundary,
 *      point) → ville, puis ST_Contains(quartiers.boundary, point) parmi
 *      les quartiers de cette ville → quartier. Départage par ST_Area()
 *      croissant en cas de chevauchement — équivalent direct de
 *      _findInPolygons()/polygonArea() (Shoelace) de l'ancien système,
 *      mais en SQL plutôt qu'en boucle JS (et sans le bug de parsing du
 *      premier-anneau-seulement, cf. vrais MULTIPOLYGON avec trous).
 *
 * Pattern repris de EnvoyerWebhookMake (amana_web_planning) : 3 tentatives,
 * backoff 60s, log + audit_logs en cas de succès, $this->fail() pour
 * déclencher un retry en cas d'échec transitoire (HTTP, ou statut Google
 * transitoire — voir GeocodingTransientException). Deux issues ne
 * déclenchent PAS de retry, seulement un log + audit_logs :
 *
 *   - "pas de coordonnées trouvées" (ZERO_RESULTS) — résultat métier, pas
 *     une panne (inchangé depuis le webhook Make.com).
 *   - erreur de configuration (REQUEST_DENIED / INVALID_REQUEST, ou clé
 *     API absente — GeocodingConfigException) — retenter ne changera rien
 *     tant qu'une intervention manuelle n'a pas eu lieu.
 */
class ResoudreAdresseFamille implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly int $idFamille
    ) {
    }

    public function handle(GoogleGeocodingService $geocodingService): void
    {
        $famille = Famille::find($this->idFamille);

        if (!$famille) {
            Log::warning('[ResoudreAdresseFamille] Famille introuvable', ['id' => $this->idFamille]);
            return;
        }

        if (!$geocodingService->isConfigured()) {
            Log::warning('[ResoudreAdresseFamille] Clé API Google Maps Geocoding non configurée — résolution ignorée', [
                'id_famille' => $famille->id,
            ]);
            return;
        }

        try {
            $resultat = $geocodingService->geocoder(
                (string) $famille->adresse,
                (string) $famille->code_postal,
                (string) $famille->ville_texte,
            );
        } catch (GeocodingConfigException $e) {
            // Erreur de configuration (clé invalide/restreinte, requête
            // malformée) : inutile de retenter, mais visible dans
            // audit_logs comme demandé — pas seulement dans les logs
            // applicatifs, qui ne sont pas consultés au quotidien.
            Log::error('[ResoudreAdresseFamille] Erreur de configuration Google Maps Geocoding', [
                'id_famille' => $famille->id,
                'message' => $e->getMessage(),
            ]);
            audit('webhook', 'familles_geocodage', $famille->id, null, [
                'succes' => false,
                'raison' => 'configuration',
                'message' => $e->getMessage(),
            ]);
            return;
        } catch (GeocodingTransientException $e) {
            Log::error('[ResoudreAdresseFamille] Échec transitoire Google Maps Geocoding', [
                'id_famille' => $famille->id,
                'message' => $e->getMessage(),
            ]);
            $this->fail($e);
            return;
        }

        if (!$resultat->trouve) {
            Log::info('[ResoudreAdresseFamille] Adresse non géocodable', [
                'id_famille' => $famille->id,
                'statut' => $resultat->statutBrut,
            ]);
            audit('webhook', 'familles_geocodage', $famille->id, null, [
                'succes' => false,
                'raison' => 'zero_results',
                'statut' => $resultat->statutBrut,
            ]);
            // Visible dans "Nouvelles demandes"/liste des dossiers (badge
            // rouge) — demande du 09/08/2026 : le staff doit voir qu'une
            // intervention manuelle est nécessaire, pas seulement un log.
            $famille->probleme_traitement = "Adresse non localisée automatiquement par Google (aucun résultat) — vérifier/corriger l'adresse ou renseigner le quartier manuellement.";
            $famille->save();
            return;
        }

        $lat = $resultat->latitude;
        $lng = $resultat->longitude;

        $idQuartier = $this->resoudreQuartier($lat, $lng);

        $avant = $famille->only(['id_quartier', 'latitude', 'longitude']);
        $famille->id_quartier = $idQuartier;
        // Persistées depuis le 12/08/2026 (jusqu'ici calculées puis jetées
        // après resoudreQuartier() ci-dessus, seul id_quartier survivait) —
        // consommées par DetailPanel.vue pour afficher une carte sans avoir
        // à re-géocoder côté client. Voir migration familles pour le choix
        // decimal(10,7).
        $famille->latitude = $lat;
        $famille->longitude = $lng;
        // Résolution réussie (même sans quartier trouvé dans les polygones,
        // décision 6.7 — la ville/coordonnées ont bien été trouvées, le
        // problème n'est plus l'adresse elle-même) : on efface un éventuel
        // signalement antérieur, par exemple après une nouvelle tentative
        // manuelle par le staff.
        $famille->probleme_traitement = null;
        $famille->save();

        Log::info('[ResoudreAdresseFamille] Résolution terminée', [
            'id_famille' => $famille->id,
            'id_quartier' => $idQuartier,
            'lat' => $lat,
            'lng' => $lng,
        ]);

        audit('webhook', 'familles_geocodage', $famille->id, $avant, [
            'succes' => true,
            'id_quartier' => $idQuartier,
            'latitude' => $lat,
            'longitude' => $lng,
            'formatted_address' => $resultat->formattedAddress,
        ]);
    }

    /**
     * Point-in-polygon en SQL — ville d'abord (parmi toutes les villes),
     * puis quartier parmi les quartiers de cette ville uniquement (via
     * secteurs.id_ville). Départage par aire croissante en cas de
     * chevauchement. Retourne null si aucune ville/quartier ne contient
     * le point (ex : tables encore vides, décision 6.7).
     */
    private function resoudreQuartier(float $lat, float $lng): ?int
    {
        $point = "ST_GeomFromText('POINT({$lng} {$lat})', 4326)";

        $commun = DB::connection(config('amana-shared.connection', 'commun'));

        $ville = $commun->selectOne("
            SELECT id, ST_Area(boundary) AS aire
            FROM villes
            WHERE ST_Contains(boundary, {$point})
            ORDER BY aire ASC
            LIMIT 1
        ");

        if (!$ville) {
            return null;
        }

        $quartier = $commun->selectOne("
            SELECT quartiers.id, ST_Area(quartiers.boundary) AS aire
            FROM quartiers
            INNER JOIN secteurs ON secteurs.id = quartiers.id_secteur
            WHERE secteurs.id_ville = ?
              AND ST_Contains(quartiers.boundary, {$point})
            ORDER BY aire ASC
            LIMIT 1
        ", [$ville->id]);

        return $quartier?->id;
    }

    /**
     * Appelé par Laravel une fois les tentatives épuisées (via l'exception
     * levée normalement, ou $this->fail() explicite ci-dessus pour un échec
     * transitoire non-métier). Couvre le "ou autre chose" de la demande du
     * 09/08/2026 : toute panne technique qui a fini par échouer
     * définitivement, pas seulement l'adresse introuvable (déjà géré dans
     * handle() ci-dessus, qui ne lève pas d'exception).
     */
    public function failed(\Throwable $exception): void
    {
        $famille = Famille::find($this->idFamille);

        if (!$famille) {
            return;
        }

        $famille->probleme_traitement = 'La résolution automatique de l\'adresse a échoué après plusieurs tentatives (problème technique) — vérifier/renseigner le quartier manuellement.';
        $famille->save();
    }
}
