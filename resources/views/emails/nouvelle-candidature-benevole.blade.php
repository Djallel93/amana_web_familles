{{-- resources/views/emails/nouvelle-candidature-benevole.blade.php --}}
<!DOCTYPE html>
<html lang="fr" dir="ltr">

<head>
    <title>Nouvelle candidature bénévole</title>
    @include('amana-shared::emails.partials._head')
</head>

<body>
    <div class="shell">
        <div class="wrapper">

            @include('amana-shared::emails.partials._header', [
                'badge' => 'Administration',
                'title' => 'Nouvelle candidature bénévole',
                'titleSub' => 'En attente de validation',
            ])

            <div class="stripe"></div>

            <div class="body">

                <p class="greeting">Bonjour <em>{{ $adminPrenom }}</em>,</p>
                <p class="body-text">
                    Une nouvelle candidature bénévole vient d'être confirmée sur <strong>AMANA
                        Familles</strong> et attend votre validation.
                </p>

                <table class="info-box" role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td class="info-icon">👤</td>
                        <td class="info-content">
                            <div class="info-title">{{ $candidat->prenom }} {{ strtoupper($candidat->nom) }}</div>
                            <div class="info-text">
                                {{ $candidat->email }}
                                @if($candidat->telephone)
                                    <br>{{ $candidat->telephone }}
                                @endif
                                <br>Véhicule : {{ $profil->vehiculeType?->type }}
                                @if($profil->permis)
                                    · permis
                                @endif
                            </div>
                        </td>
                    </tr>
                </table>

                <div class="cta-wrap">
                    <a href="{{ $urlValidation }}" class="cta-button">📥 &nbsp; Voir les candidatures en attente</a>
                </div>

                @include('amana-shared::emails.partials._closing')

            </div>

            @include('amana-shared::emails.partials._footer')

        </div>
    </div>
</body>

</html>