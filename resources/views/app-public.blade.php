{{-- resources/views/app-public.blade.php --}}
{{--
    Racine Inertia pour les pages publiques (sans auth/sidebar) — ajoutée
    le 16/09/2026 (Section E4 du refactor, chunk bénévole). app.blade.php
    (racine par défaut, HandleInertiaRequests::$rootView) inclut
    inconditionnellement amana-shared::layouts.partials.sidebar : correct
    pour tous les écrans convertis jusqu'ici (staff authentifié), mais
    inadapté à un formulaire public — intake/show.blade.php et
    benevole/show.blade.php étaient déjà des documents standalone sans
    ce chrome, et le seraient tout autant en pages Inertia. Cette racine
    reprend leur shell commun (en-tête logo + sélecteur de langue,
    <main>, pied de page, <div id="vue-toast">) tel quel plutôt que d'en
    forger un nouveau, pour un rendu visuellement identique à avant.

    Utilisée via Inertia::render(...)->withViewData([...])->rootView(
    'app-public') plutôt que le rootView par défaut — voir
    BenevoleIntakeController::showForm() (ce chunk) et
    IntakeController::showForm() (chunk suivant, même mécanisme).
    withViewData() est nécessaire ici car $langue/$titre/$tagline/
    $langueSwitchRoute ne sont PAS des props de la page Vue : ce sont des
    données pour cette racine Blade elle-même (attributs <html>,
    en-tête, <title>), qui n'a normalement accès qu'à $page (le composant
    + ses props sérialisés pour @inertia), pas aux variables passées à
    Inertia::render().

    $langue reste lu côté serveur uniquement pour ce shell (lang/dir de
    <html>, sélecteur de langue) — la page Vue elle-même reçoit $langue
    comme prop normale si elle en a besoin pour son propre contenu (voir
    BenevoleForm.vue/IntakeForm.vue), c'est une donnée distincte bien que
    de même valeur.

    Sélecteur de langue resté un <a href> classique (rechargement complet,
    comme avant) plutôt qu'un <Link> Inertia : lang/dir sur <html> ne sont
    jamais réappliqués par une visite Inertia partielle (seul le contenu
    de #app est remplacé), donc un <Link> laisserait ces deux attributs
    périmés après un changement de langue — un rechargement complet reste
    le choix sûr pour un formulaire rempli une seule fois, sans working
    autour de cette limite pour un gain marginal.
--}}
<!DOCTYPE html>
<html lang="{{ $langue }}" dir="{{ $langue === 'ar' ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ $titre }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>

<body class="bg-surface-2 font-body text-ink antialiased min-h-screen">

    {{-- En-tête (repris de intake/show.blade.php et benevole/show.blade.php,
         seuls $tagline et $langueSwitchRoute varient d'une page à l'autre) --}}
    <header class="bg-sidebar py-6 px-4">
        <div class="max-w-2xl mx-auto flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <img src="{{ asset('images/amana-logo.png') }}" alt="AMANA"
                    class="w-11 h-11 rounded-full object-cover flex-shrink-0">
                <div>
                    <div class="font-heading text-white font-semibold text-[15px] leading-tight">AMANA</div>
                    <div class="text-white/45 text-[11.5px]">{{ $tagline }}</div>
                </div>
            </div>
            <div class="flex items-center gap-1.5 flex-shrink-0">
                @foreach(['fr' => ['🇫🇷', 'FR'], 'ar' => ['🇸🇦', 'ع'], 'en' => ['🇬🇧', 'EN']] as $code => $flagLabel)
                    <a href="{{ route($langueSwitchRoute, ['langue' => $code]) }}"
                        class="flex items-center gap-1 px-2.5 py-1.5 rounded-md text-[12px] font-semibold no-underline transition-colors
                                    {{ $langue === $code ? 'bg-accent text-white' : 'bg-white/[0.08] text-white/60 hover:bg-white/[0.14]' }}">
                        <span aria-hidden="true" style="font-family: 'Twemoji Mozilla','Segoe UI Emoji','Noto Color Emoji',sans-serif; font-size: 15px;">{{ $flagLabel[0] }}</span>{{ $flagLabel[1] }}
                    </a>
                @endforeach
            </div>
        </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-8">
        @inertia
    </main>

    <footer class="text-center text-[11.5px] text-ink-faint py-8">
        AMANA — Association Musulmane de l'Agglomération Nantaise et ses Alentours
    </footer>

    <div id="vue-toast"></div>

</body>

</html>
