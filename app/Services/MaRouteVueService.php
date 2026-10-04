<?php
// app/Services/MaRouteVueService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\RouteLivraison;
use App\Support\RouteOptimizationConfig;

/**
 * Données d'affichage de l'écran chauffeur (ma-route / vue-chauffeur) —
 * 30/09/2026. Sortie du contrôleur pour rester testable sans passer par
 * la vue : ordre des arrêts, distance au QG, colis, cartes de stats et
 * signature de polling.
 */
class MaRouteVueService
{
    /**
     * Rang d'affichage par statut d'arrêt : à faire en haut, ignorés au
     * milieu, livrés en bas (ordre de tournée optimisé conservé à
     * l'intérieur de chaque groupe).
     */
    private const RANG_STATUT = ['en_cours' => 0, 'en_attente' => 0, 'ignoree' => 1, 'livree' => 2];

    public function __construct(private readonly GeoCalculationService $geo) {}

    /**
     * @return array{
     *     etapes: list<array<string, mixed>>,
     *     stats: array<string, int|float>,
     *     lien_maps: string|null,
     *     peut_demarrer: bool,
     *     tout_traite: bool,
     *     signature: string,
     * }
     */
    public function preparer(RouteLivraison $route): array
    {
        $route->loadMissing('campagne');
        $etapes = $route->etapes()
            ->whereNotNull('id_livraison')
            ->with(['livraison' => fn($q) => $q->withCount('colis'), 'livraison.famille:id,nom,prenom,adresse,telephone,telephone_bis,latitude,longitude'])
            ->orderBy('ordre')
            ->get();

        $hq = $route->campagne ? RouteOptimizationConfig::coordonneesHqPourCampagne($route->campagne) : null;

        $lignes = $etapes->map(function ($etape) use ($hq) {
            $famille = $etape->livraison->famille;
            $distance = null;
            if ($hq !== null && $famille->latitude !== null && $famille->longitude !== null) {
                $distance = round($this->geo->distanceHaversine(
                    $hq['lat'], $hq['lng'], (float) $famille->latitude, (float) $famille->longitude,
                ), 1);
            }

            return [
                'id' => $etape->id,
                'ordre' => $etape->ordre,
                'statut' => $etape->statut,
                'id_famille' => $famille->id,
                'nom' => $famille->nom,
                'adresse' => $famille->adresse,
                'lien_adresse' => $this->lienAdresse($famille->latitude, $famille->longitude, $famille->adresse),
                'telephone' => $famille->telephone,
                'telephone_bis' => $famille->telephone_bis,
                'distance_km' => $distance,
                'nb_colis' => (int) $etape->livraison->colis_count,
                'poids_kg' => (float) $etape->livraison->poids_kg,
            ];
        })->sortBy([
            fn($a, $b) => (self::RANG_STATUT[$a['statut']] ?? 0) <=> (self::RANG_STATUT[$b['statut']] ?? 0),
            fn($a, $b) => $a['ordre'] <=> $b['ordre'],
        ])->values()->all();

        $ouvertes = fn(array $l) => in_array($l['statut'], ['en_attente', 'en_cours'], true);
        $ouvert = array_filter($lignes, $ouvertes);
        $livrees = array_filter($lignes, fn(array $l) => $l['statut'] === 'livree');
        $ignorees = array_filter($lignes, fn(array $l) => $l['statut'] === 'ignoree');
        $poids = fn(array $set) => round(array_sum(array_column($set, 'poids_kg')), 1);
        $colis = fn(array $set) => (int) array_sum(array_column($set, 'nb_colis'));

        $total = count($lignes);
        $stats = [
            'colis_total' => $colis($lignes),
            'colis_restants' => $colis($ouvert),
            'colis_livres' => $colis($livrees),
            'poids_total_kg' => $poids($lignes),
            'poids_restant_kg' => $poids($ouvert),
            'arrets_total' => $total,
            'arrets_restants' => count($ouvert),
            'arrets_livres' => count($livrees),
            'arrets_ignores' => count($ignorees),
            'distance_km' => (float) ($route->distance_totale_km ?? 0),
            'avancement_pct' => $total > 0 ? (int) round((count($livrees) + count($ignorees)) / $total * 100) : 0,
        ];

        return [
            'etapes' => $lignes,
            'stats' => $stats,
            'lien_maps' => $route->lien_maps ?: null,
            'peut_demarrer' => $route->statut === 'charge',
            'tout_traite' => $total > 0 && count($ouvert) === 0,
            'signature' => $this->signature($route, $lignes),
        ];
    }

    /**
     * Empreinte servant au polling de la page : statut de la tournée +
     * statut de chaque arrêt. Un changement (démarrage, arrêt livré/ignoré/
     * remis en cours par l'admin) fait recharger la page côté chauffeur.
     *
     * @param list<array<string, mixed>> $lignes
     */
    public function signature(RouteLivraison $route, array $lignes): string
    {
        $parts = array_map(fn(array $l) => $l['id'] . ':' . $l['statut'], $lignes);
        sort($parts);

        return md5($route->statut . '|' . implode(',', $parts));
    }

    private function lienAdresse(mixed $lat, mixed $lng, ?string $adresse): ?string
    {
        if ($lat !== null && $lng !== null) {
            return 'https://www.google.com/maps/dir/?api=1&destination=' . $lat . ',' . $lng;
        }

        return $adresse ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($adresse) : null;
    }
}
