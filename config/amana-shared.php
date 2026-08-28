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

    // Palette email alignée sur l'identité teal de l'app (voir
    // public/css/custom.css, --color-accent* : #0f766e/#0d9488/#14b8a6) —
    // corrigé le 27/08/2026, ces valeurs étaient restées sur l'ancienne
    // palette ambre/terracotta ("panna cotta") alors que l'UI de l'app
    // était déjà passée au teal depuis le 13/08/2026. Même défaut de
    // synchronisation que celui documenté dans public/css/custom.css pour
    // FamillesStatistiques.vue — un reste de palette ambre que personne
    // n'avait remarqué, cette fois côté emails plutôt que composant Vue.
    'email_theme' => [
        'header_bg' => '#042f2e',
        'accent' => '#0f766e',
        'accent_dark' => '#0d9488',
        'accent_light' => '#14b8a6',
        'accent_rgb' => '15, 118, 110',
        'accent_light_rgb' => '20, 184, 166',
        'accent_light_text' => '#99f6e4',
        'accent_pale_text' => '#ccfbf1',
        'accent_darker' => '#134e4a',
        'hadith_french_text' => '#115e59',
        'accent_pale_bg' => '#f0fdfa',
        'accent_pale_border' => '#ccfbf1',
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
        ['route' => 'admin.benevoles.index', 'label' => 'Candidatures bénévoles', 'icon' => '🤝', 'role' => 'admin', 'route_pattern' => 'admin.benevoles.*'],
        ['route' => 'admin.personnes.index', 'label' => 'Personnes', 'icon' => '👥', 'role' => 'admin', 'route_pattern' => 'admin.personnes.*'],
        ['route' => 'admin.imports.index', 'label' => 'Imports', 'icon' => '📥', 'role' => 'admin', 'route_pattern' => 'admin.imports.*'],
        ['route' => 'admin.verifications.index', 'label' => 'Vérifications', 'icon' => '✅', 'role' => 'admin', 'route_pattern' => 'admin.verifications.*'],
        ['route' => 'admin.activite.index', 'label' => "Statistiques d'activité", 'icon' => '📈', 'role' => 'admin', 'route_pattern' => 'admin.activite.*'],
        ['route' => 'admin.journal.index', 'label' => "Journal d'audit", 'icon' => '📜', 'role' => 'admin', 'route_pattern' => 'admin.journal.*'],
    ],
];
