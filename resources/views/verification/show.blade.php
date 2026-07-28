{{-- resources/views/verification/show.blade.php --}}
{{--
    Page publique, standalone — accessible via le lien reçu par email
    (FamilleVerificationNotification). $etat ∈ a_confirmer | confirmee |
    deja_confirmee | expiree | introuvable.
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

        @if($etat === 'a_confirmer')
            <div class="text-4xl mb-4">🔎</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">Vos informations sont-elles à jour ?</h1>
            <p class="text-ink-muted text-[14px] mb-6 leading-relaxed">
                Bonjour {{ $verification->famille->prenom }}, merci de confirmer que les informations que vous nous avez transmises sont toujours exactes.
            </p>
            <div class="text-left bg-surface-2 rounded-lg p-4 mb-6 text-[13px] space-y-1">
                <p><strong>Nom :</strong> {{ $verification->famille->prenom }} {{ $verification->famille->nom }}</p>
                <p><strong>Téléphone :</strong> {{ $verification->famille->telephone }}</p>
                <p><strong>Adresse :</strong> {{ $verification->famille->adresse }}</p>
                <p><strong>Foyer :</strong> {{ $verification->famille->nombre_adulte }} adulte(s), {{ $verification->famille->nombre_enfant }} enfant(s)</p>
            </div>
            <form action="{{ route('verification.confirmer', $verification->token) }}" method="POST" class="mb-3">
                @csrf
                <button type="submit"
                    class="w-full min-h-[48px] px-6 py-3 bg-accent hover:bg-accent-dark text-white font-bold text-[14px] rounded-lg
                            shadow-[0_3px_14px_rgba(180,83,9,0.3)] transition-all cursor-pointer">
                    ✅ Oui, tout est à jour
                </button>
            </form>
            <a href="{{ route('intake.show') }}"
                class="block text-center text-[13px] text-ink-muted hover:text-accent transition-colors no-underline">
                📝 Mes informations ont changé
            </a>

        @elseif($etat === 'confirmee')
            <div class="text-4xl mb-4">✅</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">Merci !</h1>
            <p class="text-ink-muted text-[14px]">Vos informations ont bien été confirmées comme étant à jour.</p>

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
