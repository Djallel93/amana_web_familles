{{-- resources/views/emails/invitation-familles.blade.php --}}
<!DOCTYPE html>
<html lang="fr" dir="ltr">

<head>
    <title>Bienvenue sur AMANA Familles</title>
    @include('amana-shared::emails.partials._head')
</head>

<body>
    <div class="shell">
        <div class="wrapper">

            @include('amana-shared::emails.partials._header', [
                'badge' => 'Accès accordé',
                'title' => 'Bienvenue sur l\'équipe&nbsp;!',
                'titleSub' => 'AMANA Familles',
            ])

            <div class="stripe"></div>

            <div class="body">

                <p class="greeting">Cher <em>{{ $prenom }}</em>,</p>

                <p class="body-text">
                    Un administrateur vous a donné accès à <strong>AMANA Familles</strong>, l'outil de
                    gestion des dossiers familles bénéficiaires de l'association. Bienvenue&nbsp;!
                </p>
                <p class="body-text">
                    Pour accéder à l'application, vous devez d'abord <strong>créer votre mot de
                    passe</strong> en cliquant sur le bouton ci-dessous.
                </p>

                <div class="cta-wrap">
                    <a href="{{ $resetUrl }}" class="cta-button">🔐 &nbsp; Créer mon mot de passe</a>
                    <p class="cta-note">Ce lien est valable 60 minutes.</p>
                </div>

                <table class="warn-box" role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td class="warn-icon">⚠️</td>
                        <td class="warn-text">
                            Si le lien a expiré, rendez-vous sur la page de connexion et utilisez
                            <strong>« Mot de passe oublié »</strong> pour en obtenir un nouveau,
                            ou contactez un administrateur.
                        </td>
                    </tr>
                </table>

                @include('emails.partials._features-card', [
                    'featuresLabel' => 'Une fois connecté, vous pourrez',
                ])

                @include('amana-shared::emails.partials._hadith')

                @include('amana-shared::emails.partials._closing')

            </div>

            @include('amana-shared::emails.partials._footer')

        </div>
    </div>
</body>
</html>
