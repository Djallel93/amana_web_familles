{{-- resources/views/emails/benevole-candidature-validee.blade.php --}}
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
                'titleSub' => 'AMANA Familles',
            ])

            <div class="stripe"></div>

            <div class="body">

                <p class="greeting">Cher <em>{{ $prenom }}</em>,</p>

                <p class="body-text">
                    Votre candidature bénévole a été <strong>validée</strong> par notre équipe.
                    Bienvenue&nbsp;!
                </p>
                <p class="body-text">
                    Pour finaliser votre inscription, vous devez d'abord <strong>créer votre mot de
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

                @include('amana-shared::emails.partials._hadith')

                @include('amana-shared::emails.partials._closing')

            </div>

            @include('amana-shared::emails.partials._footer')

        </div>
    </div>
</body>
</html>
