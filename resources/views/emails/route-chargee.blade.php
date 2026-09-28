{{-- resources/views/emails/route-chargee.blade.php --}}
{{--
    Ajoutée le 09/09/2026 (prompt de cette date §2.5) — même convention
    que route-prete-a-charger.blade.php (gabarit stylé amana_shared,
    français uniquement, chauffeur seul destinataire).
--}}
<!DOCTYPE html>
<html lang="fr" dir="ltr">

<head>
    <title>🚚 Tournée chargée</title>
    @include('amana-shared::emails.partials._head')
</head>

<body>
    <div class="shell">
        <div class="wrapper">

            @include('amana-shared::emails.partials._header', [
                'badge' => '🚚 Tournée chargée',
                'title' => 'Bonjour' . ($prenom ? ', ' . e($prenom) : ''),
                'titleSub' => 'AMANA Livraison',
            ])

            <div class="stripe"></div>

            <div class="body">

                <p class="body-text">
                    Votre véhicule est chargé pour la tournée #{{ $numeroTournee }} — vous pouvez prendre la route.
                </p>

                <div class="cta-wrap">
                    <a href="{{ $maRouteUrl }}" class="cta-button">🗺️ Voir ma tournée</a>
                </div>

                <p class="body-text" style="text-align:center; font-size:12px; opacity:0.65;">
                    Retrouvez-y l'ordre des livraisons et les coordonnées de chaque famille.
                </p>

                @include('amana-shared::emails.partials._closing')

            </div>

            @include('amana-shared::emails.partials._footer')

        </div>
    </div>
</body>

</html>
