{{-- resources/views/app.blade.php --}}
{{--
    Racine Inertia — Section E4 du refactor (12/09/2026). NE remplace PAS
    layouts/app.blade.php : ce dernier reste inchangé et continue de
    servir toutes les vues pas encore migrées (@extends('layouts.app')),
    ce fichier-ci ne sert QUE les routes dont le contrôleur renvoie
    Inertia::render(...) (voir HandleInertiaRequests::$rootView = 'app').

    Reprend le même shell que layouts/app.blade.php (sidebar, chrome Vue
    partagé) pour un rendu visuellement identique — voir
    vendor/amana-shared/resources/views/layouts/app.blade.php. Le <head>
    ci-dessous DUPLIQUE le contenu de
    amana-shared::layouts.partials.head plutôt que de l'@include tel
    quel : ce partial vendor n'a aucun point d'injection pour
    @inertiaHead (obligatoire à l'intérieur de <head> pour le <title>/
    meta par page via <Head> côté Vue), et modifier le paquet amana/shared
    dépasse le périmètre de ce refactor (paquet versionné séparément,
    partagé avec amana_web_planning). Si amana/shared publie un jour un
    point d'injection dédié, remplacer cette duplication par un
    @include + ce point d'injection.

    @yield('title', ...) n'existe plus ici (pas de @section par page en
    Inertia) : le titre par page passe désormais par <Head title="..."/>
    dans chaque composant page Vue (voir resources/js/pages/Familles/
    Index.vue) plutôt que par une section Blade.
--}}
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('amana-shared.branding.app_name') }}</title>

    {{-- Favicons / icônes PWA — identiques à amana-shared::layouts.partials.head,
         voir le commentaire en tête de ce fichier. --}}
    <link rel="icon" type="image/png" href="{{ asset('favicon-96x96.png') }}" sizes="96x96">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    <meta name="apple-mobile-web-app-title" content="{{ config('amana-shared.branding.tagline_short', config('amana-shared.branding.app_name')) }}">

    <script>
        (function () {
            var stored = localStorage.getItem('amana-theme');
            var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (stored === 'dark' || (!stored && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.ts'])

    @inertiaHead
</head>

<body class="bg-surface-2 font-body text-ink antialiased flex min-h-screen">

    @include('amana-shared::layouts.partials.sidebar')

    <div id="mainWrapper"
        class="flex-1 flex flex-col min-w-0 ml-sidebar transition-all duration-300 max-sm:ml-0 max-sm:pt-topbar">
        <main class="flex-1 p-8 max-w-screen-xl w-full mx-auto max-lg:p-7 max-sm:px-4 max-sm:py-5">

            {{--
                Pas de amana-shared::layouts.partials.flash ici (Section E4,
                12/09/2026) : ce partial lit session('success'/'error'/...)
                en Blade pur, qui ne se réévalue qu'au premier chargement
                serveur — jamais lors d'une visite Inertia ultérieure
                (partial reload sans rechargement complet). HandleInertiaRequests
                partage déjà 'flash' comme prop Inertia pour ce cas, mais
                aucune page migrée dans ce chunk ne redirige avec un
                message flash (vérifié : aucun redirect()->route('familles.index'|
                'familles.nouvelles') dans app/) — pas de composant Vue de
                rendu flash construit ici sans consommateur réel pour le
                justifier. À ajouter quand une page migrée en dépendra.
            --}}

            @inertia

        </main>
    </div>

    <div id="vue-mobile-sidebar"></div>

    @stack('scripts')

    <div id="vue-toast"></div>
    <div id="vue-confirm-dialog"></div>
    <div id="vue-offline-banner"></div>
    <div id="vue-urgent-alert-bar"></div>
    <div id="vue-notification-bell"></div>

</body>

</html>
