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
        ['route' => 'familles.statistiques.index', 'label' => 'Statistiques', 'icon' => '📊', 'role' => 'gestionnaire', 'route_pattern' => 'familles.statistiques.*'],
        ['route' => 'admin.verifications.index', 'label' => 'Vérifications', 'icon' => '✅', 'role' => 'admin', 'route_pattern' => 'admin.verifications.*'],
        // Déplacé depuis "Administration" le 30/08/2026 : l'import en masse
        // manipule des dossiers familles, sa place naturelle est avec le
        // reste des écrans Dossiers plutôt que sous Administration.
        ['route' => 'admin.imports.index', 'label' => 'Imports', 'icon' => '📥', 'role' => 'admin', 'route_pattern' => 'admin.imports.*'],

        ['section' => 'Bénévoles'],
        ['route' => 'admin.benevoles.index', 'label' => 'Candidatures bénévoles', 'icon' => '🤝', 'role' => 'admin', 'route_pattern' => 'admin.benevoles.*'],
        ['route' => 'admin.personnes.index', 'label' => 'Personnes', 'icon' => '👥', 'role' => 'admin', 'route_pattern' => 'admin.personnes.*'],

        ['section' => 'Administration'],
        ['route' => 'settings.index', 'label' => 'Paramètres', 'icon' => '⚙️', 'role' => 'gestionnaire', 'route_pattern' => 'settings.*'],
        ['route' => 'admin.activite.index', 'label' => "Statistiques d'activité", 'icon' => '📈', 'role' => 'admin', 'route_pattern' => 'admin.activite.*'],
        ['route' => 'admin.journal.index', 'label' => "Journal d'audit", 'icon' => '📜', 'role' => 'admin', 'route_pattern' => 'admin.journal.*'],

        // ── Livraison (ajouté le 03/09/2026, migration frontend des
        //    écrans admin/gestionnaire du domaine livraison) — section à
        //    part plutôt que rattachée à "Dossiers" ou "Administration" :
        //    audience différente (gestionnaire pour l'essentiel, lecture
        //    seule bénévole sur Statistiques) qui ne correspond au
        //    gabarit de rôle d'aucune des deux sections existantes.
        //    Entrées ajoutées au fil des patches suivants au fur et à
        //    mesure que chaque écran est reconstruit en Vue — seule
        //    Campagnes est livrée à ce stade, Contacts/Tableau de bord/
        //    Statistiques suivront pour éviter un lien vers un écran pas
        //    encore reconstruit. ─────────────────────────────────────
        ['section' => 'Livraison'],
        ['route' => 'livraison.campagnes.index', 'label' => 'Campagnes', 'icon' => '🎁', 'role' => 'gestionnaire', 'route_pattern' => 'livraison.campagnes.*'],
        ['route' => 'livraison.contacts.index', 'label' => 'Suivi des contacts', 'icon' => '📞', 'role' => 'gestionnaire', 'route_pattern' => 'livraison.contacts.*'],
        ['route' => 'livraison.tableau-de-bord.index', 'label' => 'Tableau de bord', 'icon' => '🗺️', 'role' => 'gestionnaire', 'route_pattern' => 'livraison.tableau-de-bord.*'],
        ['route' => 'livraison.statistiques.index', 'label' => 'Statistiques', 'icon' => '📊', 'role' => null, 'route_pattern' => 'livraison.statistiques.*'],
        // 'role' => null (comme Statistiques ci-dessus) plutôt que
        // 'gestionnaire' : Ma tournée s'adresse aux bénévoles chauffeurs,
        // qui n'ont typiquement pas le rôle gestionnaire. Pas un souci de
        // sécurité de le rendre visible à tous — l'écran lui-même
        // (MaRouteController::show()) ne montre que la tournée d'
        // auth()->id() et reste vide/neutre pour quiconque n'en a pas.
        // Ajouté le 03/09/2026 : jusque-là accessible seulement en tapant
        // l'URL /livraison/benevole/ma-route à la main, aucun lien nulle
        // part n'y menait.
        ['route' => 'livraison.benevole.ma-route.show', 'label' => 'Ma tournée', 'icon' => '🚚', 'role' => null, 'route_pattern' => 'livraison.benevole.*'],
        // Ajoutés le 05/09/2026 (prompt §4.1) : equipe_reception/pesee/
        // packaging/chargement n'avaient jusqu'ici AUCUN lien de sidebar
        // vers leur propre écran — les routes exigent un {campagne} que
        // ces rôles n'ont pas de moyen de choisir sans repasser par un
        // lien direct, d'où l'impression que l'écran "n'existait pas"
        // (particulièrement pour la réception, voir le prompt).
        // 'role' ici n'est PAS un code de la hiérarchie interne
        // (admin/gestionnaire/membre/benevole) : voir le fallback générique
        // Personne::hasRole() ajouté dans amana_shared::layouts.partials.sidebar
        // pour ce cas précis, plutôt que d'apprendre ces rôles au paquet
        // partagé. Chaque lien mène au point d'entrée "choisir une
        // campagne" (voir *Controller::choisir()), pas directement à un
        // écran qui exigerait un {campagne} déjà connu.
        ['route' => 'livraison.reception.choisir', 'label' => 'Réception', 'icon' => '🧾', 'role' => 'equipe_reception', 'route_pattern' => 'livraison.reception.*'],
        ['route' => 'livraison.pesee.choisir', 'label' => 'Pesée', 'icon' => '⚖️', 'role' => 'equipe_pesee', 'route_pattern' => 'livraison.pesee.*'],
        ['route' => 'livraison.packaging.choisir', 'label' => 'Packaging', 'icon' => '📦', 'role' => 'equipe_packaging', 'route_pattern' => 'livraison.packaging.*'],
        ['route' => 'livraison.chargement.choisir', 'label' => 'Chargement', 'icon' => '🚛', 'role' => 'equipe_chargement', 'route_pattern' => 'livraison.chargement.*'],
    ],
];
