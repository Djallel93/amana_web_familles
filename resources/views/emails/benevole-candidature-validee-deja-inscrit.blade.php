{{-- resources/views/emails/benevole-candidature-validee-deja-inscrit.blade.php --}}
<!DOCTYPE html>
<html lang="fr" dir="ltr">

<head>
    <title>Candidature bénévole validée</title>
    @include('amana-shared::emails.partials._head')
</head>

<body>
    <div class="shell">
        <div class="wrapper">

            @include('amana-shared::emails.partials._header', [
                'badge' => 'Candidature validée',
                'title' => 'Bienvenue parmi nos bénévoles&nbsp;!',
            ])

            <div class="stripe"></div>

            <div class="body">

                <p class="greeting">Cher <em>{{ $prenom }}</em>,</p>

                <p class="body-text">
                    Votre candidature en tant que bénévole a été <strong>validée</strong> par notre équipe.
                    Bienvenue&nbsp;!
                </p>
                <p class="body-text">
                    Vous avez déjà un compte AMANA — utilisez vos identifiants habituels pour vous
                    connecter, vous y retrouverez désormais aussi l'accès bénévole.
                </p>

                <div class="cta-wrap">
                    <a href="{{ $loginUrl }}" class="cta-button">🔐 &nbsp; Me connecter</a>
                </div>

                @include('amana-shared::emails.partials._hadith')

                @include('amana-shared::emails.partials._closing')

            </div>

            @include('amana-shared::emails.partials._footer')

        </div>
    </div>
</body>

</html>