<?php
// database/migrations/2026_08_31_000300_seed_route_optimization_settings.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre les réglages du clustering/assignation/TSP dans ref_settings
 * (amana_commun) — même mécanisme idempotent que
 * 2026_08_27_000000_register_familles_application.php (réglages
 * "inscription ouverte").
 *
 * Valeurs par défaut portées telles quelles depuis
 * CONFIG_ROUTE_OPTIMIZATION (amana_livraison, Google_Sheets/Config/
 * 2_configRouteOptimization.js), réglables au runtime via
 * PropertiesService dans l'ancien système — même esprit ici, réglables
 * via l'écran Paramètres existant plutôt que codées en dur, voir
 * App\Support\RouteOptimizationConfig.
 *
 * Type 'string' (pas de type décimal dans Setting::cast(), voir
 * amana/shared) : converties en float/bool côté PHP dans
 * RouteOptimizationConfig, pas ici. Concerne les 7 réglages d'algorithme
 * ci-dessous — PAS route_hq_latitude/route_hq_longitude (voir plus bas),
 * passées en type 'float' natif le 05/09/2026 en même temps que la
 * refonte de leur UI dans l'écran Paramètres (recherche d'adresse Google
 * Places + saisie manuelle, voir HqCoordinatesAutocomplete.vue) —
 * volontairement pas rétroporté sur les 7 réglages d'algorithme dans ce
 * changement, à faire dans un futur ticket séparé.
 */
return new class extends Migration {
    public function up(): void
    {
        $commun = DB::connection(config('amana-shared.connection', 'commun'));

        $famillesId = $commun->table('ref_applications')->where('code', 'familles')->value('id');

        $reglages = [
            [
                'cle' => 'route_distance_proximite_km',
                'valeur' => '2.5',
                'type' => 'string',
                'libelle' => 'Distance de proximité pour le clustering (km)',
                'description' => 'Distance en-dessous de laquelle deux livraisons/groupes sont considérés comme géographiquement proches lors du clustering.',
            ],
            [
                'cle' => 'route_max_cluster_diameter_km',
                'valeur' => '5',
                'type' => 'string',
                'libelle' => 'Diamètre maximum d\'un cluster (km)',
                'description' => 'Empêche les clusters trop étalés/inefficaces à parcourir.',
            ],
            [
                'cle' => 'route_same_building_threshold_m',
                'valeur' => '50',
                'type' => 'string',
                'libelle' => 'Seuil "même bâtiment" (mètres)',
                'description' => 'En-dessous de ce seuil, deux adresses sont considérées identiques et regroupées avant le clustering.',
            ],
            [
                'cle' => 'route_quartier_preference',
                'valeur' => '1',
                'type' => 'boolean',
                'libelle' => 'Préférer le même quartier lors du clustering',
                'description' => 'Favorise le regroupement de livraisons du même quartier (bonus de score), sans les exclure des autres quartiers.',
            ],
            [
                'cle' => 'route_allow_cross_quartier',
                'valeur' => '1',
                'type' => 'boolean',
                'libelle' => 'Autoriser le regroupement inter-quartiers',
                'description' => 'Si désactivé, deux livraisons de quartiers différents ne peuvent jamais être dans le même cluster.',
            ],
            [
                'cle' => 'route_min_compactness_ratio',
                'valeur' => '0.4',
                'type' => 'string',
                'libelle' => 'Ratio de compacité minimum (0-1)',
                'description' => 'Ratio distance_moyenne/distance_max au centre du cluster — plus proche de 1 = plus compact. En-dessous, le cluster est jugé trop allongé.',
            ],
            [
                'cle' => 'route_max_livraisons_par_route',
                'valeur' => '15',
                'type' => 'string',
                'libelle' => 'Nombre maximum de livraisons par tournée',
                'description' => 'Plafond du nombre d\'arrêts sur une même tournée, indépendamment du poids/nombre de parts.',
            ],
        ];

        foreach ($reglages as $reglage) {
            $existe = $commun->table('ref_settings')
                ->where('id_application', $famillesId)
                ->where('cle', $reglage['cle'])
                ->exists();

            if (!$existe) {
                $commun->table('ref_settings')->insert([
                    'id_application' => $famillesId,
                    'cle' => $reglage['cle'],
                    'valeur' => $reglage['valeur'],
                    'type' => $reglage['type'],
                    'libelle' => $reglage['libelle'],
                    'description' => $reglage['description'],
                ]);
            }
        }

        // Coordonnées du QG — AUCUNE valeur par défaut sûre n'existe (contrairement
        // aux réglages d'algorithme ci-dessus) : propres à AMANA, à renseigner par
        // l'admin avant tout premier clustering. Volontairement laissées vides
        // (chaîne vide, pas de ligne omise) pour qu'elles apparaissent dans l'écran
        // Paramètres même non configurées — voir
        // App\Support\RouteOptimizationConfig::coordonneesHq(), qui renvoie null
        // tant qu'elles ne sont pas renseignées (Setting::cast() renvoie null pour
        // une valeur vide de type 'float'/'integer', pas 0.0 — voir amana/shared),
        // et RouteGenerationService::genererPourCampagne(), qui refuse de lancer un
        // clustering sans elles plutôt que de calculer des distances aberrantes
        // depuis (0, 0).
        //
        // Renommées "HQ par défaut" (label + libelle, décision du 05/09/2026,
        // en prévision d'une future fonctionnalité multi-QG) et passées en
        // type 'float' natif : contrairement aux réglages d'algorithme
        // ci-dessus, ces deux clés sont désormais éditées via un widget dédié
        // (recherche d'adresse Google Places → coordonnées, avec repli sur
        // une saisie manuelle) plutôt que par la boucle générique de l'écran
        // Paramètres — voir resources/views/settings/index.blade.php et
        // HqCoordinatesAutocomplete.vue.
        $reglagesHq = [
            [
                'cle' => 'route_hq_latitude',
                'valeur' => '',
                'type' => 'float',
                'libelle' => 'HQ par défaut — Latitude',
                'description' => "Coordonnée du point de départ des tournées (local de l'association). Requise avant tout clustering.",
            ],
            [
                'cle' => 'route_hq_longitude',
                'valeur' => '',
                'type' => 'float',
                'libelle' => 'HQ par défaut — Longitude',
                'description' => "Coordonnée du point de départ des tournées (local de l'association). Requise avant tout clustering.",
            ],
        ];

        foreach ($reglagesHq as $reglage) {
            $existe = $commun->table('ref_settings')
                ->where('id_application', $famillesId)
                ->where('cle', $reglage['cle'])
                ->exists();

            if (!$existe) {
                $commun->table('ref_settings')->insert([
                    'id_application' => $famillesId,
                    'cle' => $reglage['cle'],
                    'valeur' => $reglage['valeur'],
                    'type' => $reglage['type'],
                    'libelle' => $reglage['libelle'],
                    'description' => $reglage['description'],
                ]);
            }
        }
    }

    public function down(): void
    {
    }
};
