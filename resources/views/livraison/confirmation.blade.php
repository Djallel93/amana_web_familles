{{-- resources/views/livraison/confirmation.blade.php --}}
{{--
    Page publique, standalone — accessible via le lien reçu par email
    (App\Notifications\LivraisonConfirmationNotification). $etat ∈
    formulaire | confirmee | deja_confirmee | expiree | introuvable —
    même famille d'états que verification/show.blade.php, avec un état
    'formulaire' supplémentaire : contrairement à la vérification (un
    clic suffit), cette confirmation demande 3 champs (adresse, membres du
    foyer, créneaux) avant de pouvoir valider.
--}}
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Confirmation de disponibilité — AMANA Familles</title>
    @vite(['resources/css/app.css'])
</head>

<body class="bg-surface-2 font-body text-ink antialiased min-h-screen flex items-center justify-center px-4 py-10">

    <div class="max-w-md w-full bg-surface rounded-xl border border-surface-border shadow-sm p-8">

        <img src="{{ asset('images/amana-logo.png') }}" alt="AMANA" class="w-14 h-14 rounded-full object-cover mx-auto mb-5">

        @if($etat === 'formulaire')
            <h1 class="font-heading text-xl font-semibold text-ink mb-2 text-center">Confirmer ma disponibilité</h1>
            <p class="text-ink-muted text-[14px] mb-6 text-center">
                Merci de vérifier les informations ci-dessous avant de confirmer.
            </p>

            @if($errors->any())
                <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-[13px] text-rose-700">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach($errors->all() as $erreur)
                            <li>{{ $erreur }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('livraison.confirmation.store', $token) }}" class="space-y-4">
                @csrf

                <div>
                    <label for="adresse_confirmee" class="block text-[13px] font-medium text-ink mb-1">Adresse</label>
                    <input type="text" name="adresse_confirmee" id="adresse_confirmee"
                        value="{{ old('adresse_confirmee', $famille->adresse) }}"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px]" required maxlength="500">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="code_postal_confirme" class="block text-[13px] font-medium text-ink mb-1">Code postal</label>
                        <input type="text" name="code_postal_confirme" id="code_postal_confirme"
                            value="{{ old('code_postal_confirme', $famille->code_postal) }}"
                            class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px]" maxlength="10">
                    </div>
                    <div>
                        <label for="ville_confirmee" class="block text-[13px] font-medium text-ink mb-1">Ville</label>
                        <input type="text" name="ville_confirmee" id="ville_confirmee"
                            value="{{ old('ville_confirmee', $famille->ville_texte) }}"
                            class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px]" maxlength="150">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="nombre_adulte_confirme" class="block text-[13px] font-medium text-ink mb-1">Nombre d'adultes</label>
                        <input type="number" name="nombre_adulte_confirme" id="nombre_adulte_confirme" min="1" max="30"
                            value="{{ old('nombre_adulte_confirme', $famille->nombre_adulte) }}"
                            class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px]" required>
                    </div>
                    <div>
                        <label for="nombre_enfant_confirme" class="block text-[13px] font-medium text-ink mb-1">Nombre d'enfants</label>
                        <input type="number" name="nombre_enfant_confirme" id="nombre_enfant_confirme" min="0" max="30"
                            value="{{ old('nombre_enfant_confirme', $famille->nombre_enfant) }}"
                            class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px]" required>
                    </div>
                </div>

                <div>
                    <span class="block text-[13px] font-medium text-ink mb-1">Créneaux disponibles</span>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach($creneaux as $valeur => $libelle)
                            <label class="flex items-center gap-2 text-[13px] text-ink-muted">
                                <input type="checkbox" name="creneaux[]" value="{{ $valeur }}"
                                    @checked(in_array($valeur, old('creneaux', [])))>
                                {{ $libelle }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <button type="submit"
                    class="w-full rounded-lg bg-accent text-white text-[14px] font-medium py-2.5 mt-2 hover:opacity-90 transition-opacity">
                    Confirmer
                </button>
            </form>

        @elseif($etat === 'confirmee')
            <div class="text-4xl mb-4 text-center">✅</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2 text-center">Merci !</h1>
            <p class="text-ink-muted text-[14px] text-center">Votre disponibilité a bien été confirmée.</p>

        @elseif($etat === 'deja_confirmee')
            <div class="text-4xl mb-4 text-center">✅</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2 text-center">Déjà confirmé</h1>
            <p class="text-ink-muted text-[14px] text-center">Vous avez déjà confirmé votre disponibilité pour cette livraison.</p>

        @elseif($etat === 'expiree')
            <div class="text-4xl mb-4 text-center">⏰</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2 text-center">Ce lien a expiré</h1>
            <p class="text-ink-muted text-[14px] text-center">Contactez-nous si vous souhaitez mettre à jour votre disponibilité.</p>

        @else
            <div class="text-4xl mb-4 text-center">❓</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2 text-center">Lien invalide</h1>
            <p class="text-ink-muted text-[14px] text-center">Ce lien de confirmation est introuvable ou incorrect.</p>
        @endif

    </div>

</body>

</html>
