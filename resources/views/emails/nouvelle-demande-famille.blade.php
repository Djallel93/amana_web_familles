{{-- resources/views/emails/nouvelle-demande-famille.blade.php --}}
<!DOCTYPE html>
<html lang="fr" dir="ltr">

<head>
    <title>Nouvelle demande d'aide</title>
    @include('amana-shared::emails.partials._head')
</head>

<body>
    <div class="shell">
        <div class="wrapper">

            @include('amana-shared::emails.partials._header', [
                'badge' => 'Nouvelle demande',
                'title' => 'Une famille a soumis une demande',
                'titleSub' => 'AMANA — Pôle social',
            ])

            <div class="stripe"></div>

            <div class="body">

                <p class="greeting">Bonjour <em>{{ $destinatairePrenom }}</em>,</p>

                <p class="body-text">
                    <strong>{{ $famille->prenom }} {{ $famille->nom }}</strong> vient de soumettre une
                    demande d'aide via le formulaire public. Le dossier attend une première
                    prise en charge.
                </p>

                <table class="warn-box" role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td class="warn-icon">📇</td>
                        <td class="warn-text">
                            <strong>Téléphone :</strong> {{ $famille->telephone }}<br>
                            @if($famille->email)
                                <strong>Email :</strong> {{ $famille->email }}<br>
                            @endif
                            <strong>Ville :</strong> {{ $famille->ville_texte ?? '—' }}
                        </td>
                    </tr>
                </table>

                <div class="cta-wrap">
                    <a href="{{ $dossierUrl }}" class="cta-button">🗂️ &nbsp; Voir les nouvelles demandes</a>
                </div>

                @include('amana-shared::emails.partials._hadith')

                @include('amana-shared::emails.partials._closing')

            </div>

            @include('amana-shared::emails.partials._footer')

        </div>
    </div>
</body>

</html>