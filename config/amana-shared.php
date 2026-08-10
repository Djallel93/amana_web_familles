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

    'email_theme' => [
        'header_bg' => '#2e1a0f',
        'accent' => '#b45309',
        'accent_dark' => '#c2703a',
        'accent_light' => '#d97706',
        'accent_rgb' => '180, 83, 9',
        'accent_light_rgb' => '217, 119, 6',
        'accent_light_text' => '#fbd9ab',
        'accent_pale_text' => '#fde8c8',
        'accent_darker' => '#78350f',
        'hadith_french_text' => '#7c4a1e',
        'accent_pale_bg' => '#fdf6ec',
        'accent_pale_border' => '#f0dcb8',
    ],

    'branding' => [
        'app_name' => 'AMANA Familles',
        'tagline' => 'Gestion des dossiers familles bénéficiaires',
        'tagline_short' => 'Familles',
        'email_footer_text' => "Vous recevez cet email suite à une action d'un administrateur sur AMANA Familles.",
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
        ['route' => 'familles.nouvelles', 'label' => 'Nouvelles demandes', 'icon' => '📥', 'route_pattern' => 'familles.nouvelles'],
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
