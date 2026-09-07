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
 * Type natif ('float'/'integer', pas 'string') pour les 5 réglages
 * numériques ci-dessous (07/09/2026) — le ticket séparé évoqué le
 * 05/09/2026 lors du passage de route_hq_latitude/route_hq_longitude en
 * 'float' natif (voir plus bas). route_quartier_preference et
 * route_allow_cross_quartier restent en 'boolean', déjà natif depuis
 * l'origine. Choix float vs integer : float pour les distances/ratios
 * (route_distance_proximite_km, route_max_cluster_diameter_km,
 * route_min_compactness_ratio), integer pour les comptes/entiers
 * (route_same_building_threshold_m en mètres, route_max_livraisons_par_route)
 * — voir App\Support\RouteOptimizationConfig, dont les casts (float)/(int)
 * manuels sont supprimés en même temps que ce changement, désormais
 * redondants avec Setting::cast().
 *
 * Bloc UPDATE en fin de up() : cette migration a déjà tourné en dev (les
 * 7 réglages existent avec type='string'), donc l'insert idempotent
 * ci-dessous (if (!$existe)) ne les retype pas tout seul — seul un
 * migrate:fresh le referait. Le complément UPDATE couvre aussi le cas
 * d'une DB de dev déjà migrée sans repasser par migrate:fresh. Ne touche
 * que la colonne 'type', jamais 'valeur' (réglage possiblement modifié
 * depuis l'écran Paramètres, pas à écraser).
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
                'type' => 'float',
                'libelle' => 'Distance de proximité pour le clustering (km)',
                'description' => 'Distance en-dessous de laquelle deux livraisons/groupes sont considérés comme géographiquement proches lors du clustering.',
            ],
            [
                'cle' => 'route_max_cluster_diameter_km',
                'valeur' => '5',
                'type' => 'float',
                'libelle' => 'Diamètre maximum d\'un cluster (km)',
                'description' => 'Empêche les clusters trop étalés/inefficaces à parcourir.',
            ],
            [
                'cle' => 'route_same_building_threshold_m',
                'valeur' => '50',
                'type' => 'integer',
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
                'type' => 'float',
                'libelle' => 'Ratio de compacité minimum (0-1)',
                'description' => 'Ratio distance_moyenne/distance_max au centre du cluster — plus proche de 1 = plus compact. En-dessous, le cluster est jugé trop allongé.',
            ],
            [
                'cle' => 'route_max_livraisons_par_route',
                'valeur' => '15',
                'type' => 'integer',
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

        // Retype les 5 réglages d'algorithme si cette migration a déjà
        // tourné avec l'ancien type 'string' (voir commentaire en tête de
        // fichier) — ne touche que 'type', jamais 'valeur'.
        $typesCibles = [
            'route_distance_proximite_km' => 'float',
            'route_max_cluster_diameter_km' => 'float',
            'route_same_building_threshold_m' => 'integer',
            'route_min_compactness_ratio' => 'float',
            'route_max_livraisons_par_route' => 'integer',
        ];

        foreach ($typesCibles as $cle => $type) {
            $commun->table('ref_settings')
                ->where('id_application', $famillesId)
                ->where('cle', $cle)
                ->where('type', '!=', $type)
                ->update(['type' => $type]);
        }
    }

    public function down(): void
    {
    }
};
