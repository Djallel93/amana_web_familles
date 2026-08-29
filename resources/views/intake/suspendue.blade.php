{{-- resources/views/intake/suspendue.blade.php --}}
{{--
    Page publique, standalone — affichée à la place du formulaire
    d'intake (familles ou bénévoles) quand le réglage correspondant
    (inscription_familles_ouverte / inscription_benevoles_ouverte, voir
    Paramètres) est désactivé. Même gabarit que verification/show.blade.php.
    $formulaire ∈ 'familles' | 'benevoles'.
--}}
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription temporairement suspendue — AMANA</title>
    @vite(['resources/css/app.css'])
</head>

<body class="bg-surface-2 font-body text-ink antialiased min-h-screen flex items-center justify-center px-4">

    <div class="max-w-md w-full bg-surface rounded-xl border border-surface-border shadow-sm p-8 text-center">

        <img src="{{ asset('images/amana-logo.png') }}" alt="AMANA" class="w-14 h-14 rounded-full object-cover mx-auto mb-5">

        <div class="text-4xl mb-4">⏸️</div>
        <h1 class="font-heading text-xl font-semibold text-ink mb-2">Inscription temporairement suspendue</h1>
        <p class="text-ink-muted text-[14px] leading-relaxed">
            @if($formulaire === 'benevoles')
                Le formulaire de candidature bénévole n'accepte pas de nouvelles demandes pour le moment.
                Merci de revenir un peu plus tard.
            @else
                Le formulaire de demande d'aide n'accepte pas de nouvelles demandes pour le moment.
                Merci de revenir un peu plus tard.
            @endif
        </p>

    </div>

</body>
</html>
