{{-- resources/views/emails/retrait-hq-annule.blade.php --}}
@php
    $t = [
        'fr' => [
            'greeting' => 'Bonjour',
            'badge' => '🏠 Retrait au QG annulé',
            'intro' => "Votre rendez-vous de retrait au QG est annulé : vous n'avez plus à vous déplacer.",
            'next' => 'Votre colis sera livré à votre domicile. Nous vous préviendrons si nous avons besoin de vous joindre.',
            'notYou' => "Si vous n'êtes pas concerné par ce message, vous pouvez l'ignorer.",
        ],
        'ar' => [
            'greeting' => 'مرحبا',
            'badge' => '🏠 تم إلغاء الاستلام من المقر',
            'intro' => 'تم إلغاء موعد استلام الطرد من المقر: لا داعي للقدوم.',
            'next' => 'سيتم توصيل طردكم إلى منزلكم. سنتصل بكم إذا احتجنا إلى ذلك.',
            'notYou' => 'إذا كانت هذه الرسالة لا تخصكم، يمكنكم تجاهلها.',
        ],
        'en' => [
            'greeting' => 'Hello',
            'badge' => '🏠 Pickup cancelled',
            'intro' => 'Your pickup appointment at our office is cancelled: you no longer need to come.',
            'next' => 'Your package will be delivered to your home. We will contact you if we need to reach you.',
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
                <p class="body-text">{{ $t['next'] }}</p>
                <p class="body-text" style="text-align:center; font-size:12px; opacity:0.65;">{{ $t['notYou'] }}</p>

                @include('amana-shared::emails.partials._closing')
            </div>

            @include('amana-shared::emails.partials._footer')

        </div>
    </div>
</body>

</html>
