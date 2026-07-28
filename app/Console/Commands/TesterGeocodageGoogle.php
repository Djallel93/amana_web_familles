<?php
// app/Console/Commands/TesterGeocodageGoogle.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\GeocodingConfigException;
use App\Exceptions\GeocodingTransientException;
use App\Jobs\ResoudreAdresseFamille;
use App\Models\Famille;
use App\Services\GoogleGeocodingService;
use Illuminate\Console\Command;

/**
 * Commande de test manuel pour l'intégration directe Google Maps Geocoding
 * (voir GoogleGeocodingService, ResoudreAdresseFamille) — utile pour
 * vérifier la clé API / restrictions GCP sans attendre qu'un vrai import ou
 * une vraie création de famille déclenche le job en file d'attente.
 *
 * Deux modes :
 *
 *   1. Par défaut (lecture seule, aucune écriture en base) : appelle
 *      GoogleGeocodingService::geocoder() directement et affiche le résultat
 *      brut (coordonnées, formatted_address, ou raison de l'échec). Adresse
 *      soit fournie explicitement (--adresse/--code-postal/--ville), soit
 *      lue depuis une famille existante (--famille=ID).
 *
 *   2. --sync : exécute réellement ResoudreAdresseFamille::dispatchSync()
 *      pour la famille indiquée — job complet, y compris la résolution
 *      ST_Contains() du quartier et l'écriture id_quartier + audit_logs.
 *      Nécessite --famille=ID (le job lit l'adresse depuis la famille en
 *      base, --adresse/--code-postal/--ville sont ignorés dans ce mode).
 *
 * Exemples :
 *   php artisan familles:tester-geocodage --adresse="1 rue de la Paix" --code-postal=44000 --ville=Nantes
 *   php artisan familles:tester-geocodage --famille=42
 *   php artisan familles:tester-geocodage --famille=42 --sync
 */
class TesterGeocodageGoogle extends Command
{
    protected $signature = 'familles:tester-geocodage
        {--famille= : ID d\'une famille existante — utilise son adresse/code_postal/ville_texte}
        {--adresse= : Adresse libre à tester (ignoré si --famille est fourni)}
        {--code-postal= : Code postal (utilisé avec --adresse)}
        {--ville= : Ville (utilisée avec --adresse)}
        {--sync : Exécute réellement ResoudreAdresseFamille (persiste id_quartier + audit_logs) — nécessite --famille. Sans ce flag, appel direct de l\'API en lecture seule, aucune écriture}';

    protected $description = "Teste l'intégration Google Maps Geocoding (clé API, résolution d'adresse), avec ou sans exécution complète du job de résolution";

    public function handle(GoogleGeocodingService $geocodingService): int
    {
        if (!$geocodingService->isConfigured()) {
            $this->error('Clé API Google Maps Geocoding non configurée (GOOGLE_MAPS_GEOCODING_API_KEY manquante en .env).');
            return self::FAILURE;
        }

        $idFamille = $this->option('famille');

        if ($this->option('sync')) {
            if (!$idFamille) {
                $this->error('--sync nécessite --famille=ID (le job lit l\'adresse depuis la base, pas depuis --adresse).');
                return self::FAILURE;
            }

            if ($this->option('adresse') || $this->option('code-postal') || $this->option('ville')) {
                $this->warn('--adresse/--code-postal/--ville sont ignorés avec --sync : le job relit l\'adresse de la famille en base.');
            }

            return $this->executerJobComplet((int) $idFamille);
        }

        [$adresse, $codePostal, $ville] = $this->resoudreAdresseATester($idFamille);

        if ($adresse === null) {
            return self::FAILURE;
        }

        return $this->appelDirectApi($geocodingService, $adresse, $codePostal, $ville);
    }

    /**
     * Détermine l'adresse à tester : depuis --famille si fourni (les
     * options --adresse/--code-postal/--ville, si présentes en plus,
     * surchargent alors les champs correspondants — pratique pour tester
     * une variante d'une adresse existante sans la modifier en base), sinon
     * depuis --adresse/--code-postal/--ville directement.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function resoudreAdresseATester(?string $idFamille): array
    {
        if ($idFamille) {
            $famille = Famille::find($idFamille);

            if (!$famille) {
                $this->error("Famille #{$idFamille} introuvable.");
                return [null, null, null];
            }

            return [
                $this->option('adresse') ?? $famille->adresse,
                $this->option('code-postal') ?? $famille->code_postal,
                $this->option('ville') ?? $famille->ville_texte,
            ];
        }

        $adresse = $this->option('adresse');
        $codePostal = $this->option('code-postal');
        $ville = $this->option('ville');

        if (!$adresse || !$codePostal || !$ville) {
            $this->error('Fournir soit --famille=ID, soit --adresse ET --code-postal ET --ville.');
            return [null, null, null];
        }

        return [$adresse, $codePostal, $ville];
    }

    /**
     * Mode lecture seule : appelle le service directement, sans job ni
     * écriture en base — idéal pour valider la clé API / les restrictions
     * GCP indépendamment de la logique métier (résolution de quartier).
     */
    private function appelDirectApi(
        GoogleGeocodingService $geocodingService,
        string $adresse,
        string $codePostal,
        string $ville
    ): int {
        $this->line("Adresse testée : {$adresse}, {$codePostal} {$ville}");

        try {
            $resultat = $geocodingService->geocoder($adresse, $codePostal, $ville);
        } catch (GeocodingConfigException $e) {
            $this->error("Erreur de configuration (pas de retry en conditions réelles) : {$e->getMessage()}");
            return self::FAILURE;
        } catch (GeocodingTransientException $e) {
            $this->error("Échec transitoire (déclencherait un retry en conditions réelles) : {$e->getMessage()}");
            return self::FAILURE;
        }

        if (!$resultat->trouve) {
            $this->warn("Adresse non géocodable (statut Google : {$resultat->statutBrut}).");
            return self::SUCCESS;
        }

        $this->info('Géocodage réussi.');
        $this->table(
            ['Champ', 'Valeur'],
            [
                ['Statut Google', $resultat->statutBrut],
                ['Latitude', $resultat->latitude],
                ['Longitude', $resultat->longitude],
                ['Adresse formatée', $resultat->formattedAddress],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Mode --sync : exécute le job complet en synchrone (pas de file
     * d'attente), y compris la résolution ST_Contains() du quartier et la
     * persistance id_quartier + audit_logs — pour valider le pipeline de
     * bout en bout sur une vraie famille.
     */
    private function executerJobComplet(int $idFamille): int
    {
        $famille = Famille::find($idFamille);

        if (!$famille) {
            $this->error("Famille #{$idFamille} introuvable.");
            return self::FAILURE;
        }

        $quartierAvant = $famille->id_quartier;

        $this->line("Exécution synchrone de ResoudreAdresseFamille pour la famille #{$idFamille}...");
        $this->line("Adresse en base : {$famille->adresse}, {$famille->code_postal} {$famille->ville_texte}");

        ResoudreAdresseFamille::dispatchSync($idFamille);

        $famille->refresh();

        $this->info('Job terminé.');
        $this->table(
            ['Champ', 'Valeur'],
            [
                ['id_quartier avant', $quartierAvant ?? '(null)'],
                ['id_quartier après', $famille->id_quartier ?? '(null)'],
            ]
        );
        $this->line('Détail (statut Google, coordonnées, etc.) : voir les logs applicatifs et la table audit_logs (module familles_geocodage).');

        return self::SUCCESS;
    }
}
