{{-- resources/views/emails/invitation-familles-deja-inscrit.blade.php --}}
<!DOCTYPE html>
<html lang="fr" dir="ltr">

<head>
    <title>Accès activé — AMANA Familles</title>
    @include('amana-shared::emails.partials._head')
</head>

<body>
    <div class="shell">
        <div class="wrapper">

            @include('amana-shared::emails.partials._header', [
                'badge' => 'Accès accordé',
                'title' => 'Votre accès est activé&nbsp;!',
                'titleSub' => 'AMANA Familles',
            ])

            <div class="stripe"></div>

            <div class="body">

                <p class="greeting">Cher <em>{{ $prenom }}</em>,</p>

                <p class="body-text">
                    Un administrateur vous a donné accès à <strong>AMANA Familles</strong>, l'outil de
                    gestion des dossiers familles bénéficiaires. Bienvenue dans l'équipe&nbsp;!
                </p>

                <table class="info-box" role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td class="info-icon">✅</td>
                        <td class="info-content">
                            <div class="info-title">Vous avez déjà un compte AMANA</div>
                            <div class="info-text">
                                Votre adresse email est déjà associée à un compte AMANA actif.
                                Vous pouvez vous connecter directement à AMANA Familles
                                avec votre mot de passe habituel — aucune action supplémentaire n'est requise.
                            </div>
                        </td>
                    </tr>
                </table>

                <div class="cta-wrap">
                    <a href="{{ $loginUrl }}" class="cta-button">🔐 &nbsp; Se connecter à AMANA Familles</a>
                    <p class="cta-note">Utilisez votre adresse email et votre mot de passe habituel.</p>
                </div>

                <table class="hint-box" role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td class="hint-icon">💡</td>
                        <td class="hint-text">
                            Mot de passe oublié ? Rendez-vous sur la page de connexion et cliquez sur
                            <strong>« Mot de passe oublié »</strong> pour recevoir un lien de réinitialisation.
                        </td>
                    </tr>
                </table>

                @include('emails.partials._features-card', [
                    'featuresLabel' => 'Depuis AMANA Familles, vous pouvez',
                ])

                @include('amana-shared::emails.partials._closing')

            </div>

            @include('amana-shared::emails.partials._footer')

        </div>
    </div>
</body>
</html>
