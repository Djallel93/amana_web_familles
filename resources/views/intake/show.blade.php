{{-- resources/views/intake/show.blade.php --}}
{{--
Page publique, standalone (pas de sidebar/auth — layouts.app est
réservé au staff connecté). Le sélecteur de langue est un simple lien
qui recharge la page sur /demande/{langue} — pas de i18n réactif côté
Vue, plus simple et suffisant pour un formulaire à remplir une fois.
--}}
<!DOCTYPE html>
<html lang="{{ $langue }}" dir="{{ $langue === 'ar' ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AMANA Familles — Demande d'aide</title>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>

<body class="bg-surface-2 font-body text-ink antialiased min-h-screen">

    {{-- En-tête --}}
    <header class="bg-sidebar py-6 px-4">
        <div class="max-w-2xl mx-auto flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <img src="{{ asset('images/amana-logo.png') }}" alt="AMANA"
                    class="w-11 h-11 rounded-full object-cover flex-shrink-0">
                <div>
                    <div class="font-heading text-white font-semibold text-[15px] leading-tight">AMANA</div>
                    <div class="text-white/45 text-[11.5px]">Formulaire d'inscription</div>
                </div>
            </div>
            <div class="flex items-center gap-1.5 flex-shrink-0">
                @foreach(['fr' => ['🇫🇷', 'FR'], 'ar' => ['🇸🇦', 'ع'], 'en' => ['🇬🇧', 'EN']] as $code => $flagLabel)
                    <a href="{{ route('intake.show', ['langue' => $code]) }}"
                        class="flex items-center gap-1 px-2.5 py-1.5 rounded-md text-[12px] font-semibold no-underline transition-colors
                                    {{ $langue === $code ? 'bg-accent text-white' : 'bg-white/[0.08] text-white/60 hover:bg-white/[0.14]' }}">
                        <span aria-hidden="true" style="font-family: 'Twemoji Mozilla','Segoe UI Emoji','Noto Color Emoji',sans-serif; font-size: 15px;">{{ $flagLabel[0] }}</span>{{ $flagLabel[1] }}
                    </a>
                @endforeach
            </div>
        </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-8">
        <div id="vue-intake-form" data-langue="{{ $langue }}" data-store-url="{{ route('intake.store') }}"
            data-refus-url="{{ route('intake.refus-consentement') }}"
            data-secteurs-activite="{{ $secteursActivite->toJson() }}"
            data-organismes-aide="{{ $organismesAide->toJson() }}"
            data-organisations="{{ $organisations->toJson() }}" data-google-places-key="{{ $googlePlacesApiKey }}">
        </div>
    </main>

    <footer class="text-center text-[11.5px] text-ink-faint py-8">
        AMANA — Association Musulmane de l'Agglomération Nantaise et ses Alentours
    </footer>

    <div id="vue-toast"></div>

</body>

</html>