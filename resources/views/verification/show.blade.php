{{-- resources/views/verification/show.blade.php --}}
{{--
    Page publique, standalone — accessible via le lien reçu par email
    (FamilleVerificationNotification). $etat ∈ confirmee | deja_confirmee |
    expiree | introuvable — plus d'état 'a_confirmer' intermédiaire depuis
    le 29/08/2026 (confirmation en un clic, voir VerificationController::show()).
--}}
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Vérification de vos informations — AMANA Familles</title>
    @vite(['resources/css/app.css'])
</head>

<body class="bg-surface-2 font-body text-ink antialiased min-h-screen flex items-center justify-center px-4">

    <div class="max-w-md w-full bg-surface rounded-xl border border-surface-border shadow-sm p-8 text-center">

        <img src="{{ asset('images/amana-logo.png') }}" alt="AMANA" class="w-14 h-14 rounded-full object-cover mx-auto mb-5">

        @if($etat === 'confirmee')
            <div class="text-4xl mb-4">✅</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">Merci !</h1>
            <p class="text-ink-muted text-[14px] mb-6">Vos informations ont bien été confirmées comme étant à jour.</p>
            {{-- Échappatoire conservée de l'ancien écran "a_confirmer" : la
                 confirmation est désormais automatique, mais la personne peut
                 toujours signaler un changement après coup. --}}
            <a href="{{ route('intake.show') }}"
                class="block text-center text-[13px] text-ink-muted hover:text-accent transition-colors no-underline">
                📝 Mes informations ont changé
            </a>

        @elseif($etat === 'deja_confirmee')
            <div class="text-4xl mb-4">✅</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">Déjà confirmé</h1>
            <p class="text-ink-muted text-[14px]">Vous avez déjà confirmé ces informations le {{ $verification->confirmed_at?->format('d/m/Y') }}.</p>

        @elseif($etat === 'expiree')
            <div class="text-4xl mb-4">⏰</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">Ce lien a expiré</h1>
            <p class="text-ink-muted text-[14px]">Ce lien de vérification n'est plus valable. Contactez-nous si vous souhaitez mettre à jour vos informations.</p>

        @else
            <div class="text-4xl mb-4">❓</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">Lien invalide</h1>
            <p class="text-ink-muted text-[14px]">Ce lien de vérification est introuvable ou incorrect.</p>
        @endif

    </div>

</body>
</html>
