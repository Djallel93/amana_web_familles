{{-- resources/views/emails/route-incident.blade.php --}}
{{--
    Ajoutée le 29/09/2026 (prompt de cette date §5.3) : le mail d'incident
    utilisait le gabarit Laravel par défaut (MailMessage->line()->action()).
    Même convention que route-chargee.blade.php (gabarit stylé amana_shared,
    français uniquement) — destinataires : admins/gestionnaires, voir
    RouteIncident::booted().

    Variables : $prenom, $libelle (type d'incident lisible), $numeroTournee,
    $notes (nullable), $suiviUrl, $logoCid.
--}}
<!DOCTYPE html>
<html lang="fr" dir="ltr">

<head>
    <title>⚠️ Incident — {{ $libelle }}</title>
    @include('amana-shared::emails.partials._head')
</head>

<body>
    <div class="shell">
        <div class="wrapper">

            @include('amana-shared::emails.partials._header', [
                'badge' => '⚠️ Incident livraison',
                'title' => 'Bonjour' . ($prenom ? ', ' . e($prenom) : ''),
                'titleSub' => 'AMANA Livraison',
            ])

            <div class="stripe"></div>

            <div class="body">

                <p class="body-text">
                    Un incident « <strong>{{ $libelle }}</strong> » a été signalé sur la tournée #{{ $numeroTournee }}.
                </p>

                @if(!empty($notes))
                    <table class="hint-box" role="presentation" cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td class="hint-icon">💬</td>
                            <td class="hint-text">
                                <strong>Précisions du signalement</strong><br>
                                {{ $notes }}
                            </td>
                        </tr>
                    </table>
                @endif

                <div class="cta-wrap">
                    <a href="{{ $suiviUrl }}" class="cta-button">📋 Voir le suivi livraison</a>
                </div>

                <p class="body-text" style="text-align:center; font-size:12px; opacity:0.65;">
                    Résolvez l'incident depuis le suivi livraison une fois traité.
                </p>

                @include('amana-shared::emails.partials._closing')

            </div>

            @include('amana-shared::emails.partials._footer')

        </div>
    </div>
</body>

</html>
