{{-- resources/views/emails/retrait-hq.blade.php --}}
@php
    $t = [
        'fr' => [
            'greeting' => 'Bonjour',
            'badge' => '🏠 Retrait au QG',
            'intro' => "Votre colis alimentaire est à retirer sur place, à l'adresse et l'heure indiquées ci-dessous.",
            'when' => 'Créneau',
            'where' => 'Adresse',
            'qrIntro' => "Présentez ce QR code à votre arrivée pour être identifié rapidement — vous pouvez aussi indiquer votre nom si vous ne pouvez pas le présenter.",
            'notYou' => "Si vous n'êtes pas concerné par ce message, vous pouvez l'ignorer.",
        ],
        'ar' => [
            'greeting' => 'مرحبا',
            'badge' => '🏠 الاستلام من المقر',
            'intro' => 'طردكم الغذائي جاهز للاستلام في المكان والوقت المحددين أدناه.',
            'when' => 'الموعد',
            'where' => 'العنوان',
            'qrIntro' => 'يرجى تقديم رمز QR هذا عند وصولكم للتعرف عليكم بسرعة — يمكنكم أيضًا ذكر اسمكم إذا تعذر تقديمه.',
            'notYou' => 'إذا كانت هذه الرسالة لا تخصكم، يمكنكم تجاهلها.',
        ],
        'en' => [
            'greeting' => 'Hello',
            'badge' => '🏠 Pickup at our office',
            'intro' => 'Your food package is ready for pickup at the address and time below.',
            'when' => 'Time slot',
            'where' => 'Address',
            'qrIntro' => "Please show this QR code when you arrive so our team can find you quickly — you can also give your name if you can't show it.",
            'notYou' => "If this message isn't intended for you, you can ignore it.",
        ],
    ][$langue];
@endphp
<!DOCTYPE html>
<html lang="{{ $langue }}" dir="{{ $langue === 'ar' ? 'rtl' : 'ltr' }}">

<head>
    <title>{{ $t['badge'] }}</title>
    @include('amana-shared::emails.partials._head')
</head>

<body>
    <div class="shell">
        <div class="wrapper">

            @include('amana-shared::emails.partials._header', [
                'badge' => $t['badge'],
                'title' => $t['greeting'] . ($prenom ? ', ' . e($prenom) : ''),
                'titleSub' => 'AMANA — Pôle social',
            ])

            <div class="stripe"></div>

            <div class="body">

                <p class="body-text">{{ $t['intro'] }}</p>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                    style="margin: 16px 0; border: 1px solid #e5e7eb; border-radius: 10px;">
                    <tr>
                        <td style="padding: 14px 18px;">
                            <p class="body-text" style="margin: 0 0 4px;"><strong>{{ $t['when'] }}</strong> —
                                {{ $heureArrivee?->translatedFormat('l j F Y, H:i') }}</p>
                            @if($hqAdresse)
                                <p class="body-text" style="margin: 0;"><strong>{{ $t['where'] }}</strong> —
                                    {{ $hqAdresse }}</p>
                            @endif
                        </td>
                    </tr>
                </table>

                <p class="body-text" style="text-align:center;">{{ $t['qrIntro'] }}</p>

                <div class="cta-wrap">
                    <img src="{{ $qrCid }}" width="180" height="180" alt="QR code" style="display:inline-block;">
                </div>

                <p class="body-text" style="text-align:center; font-size:12px; opacity:0.65;">{{ $t['notYou'] }}</p>

                @include('amana-shared::emails.partials._closing')

            </div>

            @include('amana-shared::emails.partials._footer')

        </div>
    </div>
</body>

</html>
