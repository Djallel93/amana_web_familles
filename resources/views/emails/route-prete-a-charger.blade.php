{{-- resources/views/emails/route-prete-a-charger.blade.php --}}
{{--
    Ajoutée le 09/09/2026 (prompt de cette date §2.4) : jusque-là
    RoutePretePourChargementNotification utilisait un MailMessage
    générique (->line()/->action()) au lieu du gabarit stylé amana_shared
    (voir campagne-disponibilite.blade.php pour la convention reprise
    ici). Contrairement à ce dernier, ce gabarit est UNIQUEMENT en
    français (destiné au chauffeur, pas au grand public/donateurs — pas de
    tableau $t par langue) et n'a PAS de bouton d'action : le chauffeur n'a
    pas accès à l'écran chargement (can:equipeChargement), voir le prompt
    "they currently see a button to access the loading view and they
    won't have access to it... remove it in all cases".
--}}
<!DOCTYPE html>
<html lang="fr" dir="ltr">

<head>
    <title>📦 Colis prêts à charger</title>
    @include('amana-shared::emails.partials._head')
</head>

<body>
    <div class="shell">
        <div class="wrapper">

            @include('amana-shared::emails.partials._header', [
                'badge' => '📦 Colis prêts',
                'title' => 'Bonjour' . ($prenom ? ', ' . e($prenom) : ''),
                'titleSub' => 'AMANA Livraison',
            ])

            <div class="stripe"></div>

            <div class="body">

                <p class="body-text">
                    Tous les colis de votre tournée #{{ $numeroTournee }} sont conditionnés et prêts à être chargés.
                </p>

                <p class="body-text">Voici ce qu'il vous reste à faire :</p>

                <p class="body-text">
                    1. Rendez-vous sur le lieu de chargement.<br>
                    2. Présentez-vous auprès du responsable chargement et montrez-lui votre tournée.<br>
                    3. Garez votre véhicule, coffre ouvert, à l'emplacement qui vous sera indiqué.<br>
                    4. Le responsable chargement place les colis dans votre véhicule et vérifie avec vous que tout y est.
                </p>

                <p class="body-text" style="text-align:center; font-size:12px; opacity:0.65;">
                    Vous recevrez un nouveau message dès que le chargement de votre tournée sera terminé.
                </p>

                @include('amana-shared::emails.partials._closing')

            </div>

            @include('amana-shared::emails.partials._footer')

        </div>
    </div>
</body>

</html>
