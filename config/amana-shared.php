<?php
// config/amana-shared.php
//
// Publié depuis amana/shared (php artisan vendor:publish --tag=amana-shared-config)
// puis adapté aux besoins de amana_web_familles.

declare(strict_types=1);

return [

    'app_code' => env('AMANA_APP_CODE', 'familles'),

    'connection' => env('AMANA_COMMUN_CONNECTION', 'commun'),

    'home_route' => env('AMANA_HOME_ROUTE', 'familles.index'),

    'branding' => [
        'app_name' => 'AMANA Familles',
        'tagline' => 'Gestion des dossiers familles bénéficiaires',
        'tagline_short' => 'Familles',
        'features' => [
            ['🗂️', 'Suivi centralisé des dossiers familles'],
            ['📍', 'Cartographie secteurs / quartiers'],
            ['📨', 'Formulaire de demande public multilingue'],
            ['✅', 'Vérifications périodiques automatisées'],
        ],
        // Staff-only : pas d'inscription publique côté Familles (contrairement
        // à Planning) — les comptes staff sont créés par un admin.
        'signup_route_name' => null,
        'signup_label' => null,
    ],

    'audit' => [
        'modules' => [
            'familles',
            'documents',
            'imports',
            'verifications',
            'google-contacts',
            'personnes',
            'auth',
        ],
        'actions' => ['create', 'update', 'delete', 'generate', 'login', 'logout', 'webhook'],
    ],

    'nav' => [
        ['section' => 'Dossiers'],
        ['route' => 'familles.index', 'label' => 'Familles', 'icon' => '🗂️', 'route_pattern' => 'familles.index'],

        ['section' => 'Gestion'],
        ['route' => 'familles.statistiques.index', 'label' => 'Statistiques', 'icon' => '📊', 'role' => 'gestionnaire', 'route_pattern' => 'familles.statistiques.*'],

        ['section' => 'Administration'],
        ['route' => 'settings.index', 'label' => 'Paramètres', 'icon' => '⚙️', 'role' => 'gestionnaire', 'route_pattern' => 'settings.*'],
        ['route' => 'admin.personnes.index', 'label' => 'Personnes', 'icon' => '👥', 'role' => 'admin', 'route_pattern' => 'admin.personnes.*'],
        ['route' => 'admin.imports.index', 'label' => 'Imports', 'icon' => '📥', 'role' => 'admin', 'route_pattern' => 'admin.imports.*'],
        ['route' => 'admin.verifications.index', 'label' => 'Vérifications', 'icon' => '✅', 'role' => 'admin', 'route_pattern' => 'admin.verifications.*'],
        ['route' => 'admin.activite.index', 'label' => "Statistiques d'activité", 'icon' => '📈', 'role' => 'admin', 'route_pattern' => 'admin.activite.*'],
        ['route' => 'admin.journal.index', 'label' => "Journal d'audit", 'icon' => '📜', 'role' => 'admin', 'route_pattern' => 'admin.journal.*'],
    ],
];
