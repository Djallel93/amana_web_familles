{{-- resources/views/emails/livraison-confirmation.blade.php --}}
@php
    $t = [
        'fr' => [
            'greeting' => 'Bonjour',
            'badge' => '🚚 Confirmation',
            'intro' => "Une livraison vous est destinée prochainement. Merci de confirmer votre adresse, le nombre de personnes dans votre foyer, et vos créneaux de disponibilité en cliquant sur le bouton ci-dessous.",
            'expiry' => 'Ce lien est valable 14 jours.',
            'notYou' => "Si vous n'êtes pas concerné par ce message, vous pouvez l'ignorer.",
            'button' => '✅ Confirmer ma disponibilité',
        ],
        'ar' => [
            'greeting' => 'مرحبا',
            'badge' => '🚚 تأكيد',
            'intro' => 'من المقرر أن تصلكم عملية توصيل قريبًا. يرجى تأكيد عنوانكم وعدد أفراد أسرتكم وأوقات توفركم بالضغط على الزر أدناه.',
            'expiry' => 'هذا الرابط صالح لمدة 14 يومًا.',
            'notYou' => 'إذا كانت هذه الرسالة لا تخصكم، يمكنكم تجاهلها.',
            'button' => '✅ تأكيد توفري',
        ],
        'en' => [
            'greeting' => 'Hello',
            'badge' => '🚚 Confirmation',
            'intro' => 'A delivery is planned for you soon. Please confirm your address, household size, and availability time slots by clicking the button below.',
            'expiry' => 'This link is valid for 14 days.',
            'notYou' => "If this message isn't intended for you, you can ignore it.",
            'button' => '✅ Confirm my availability',
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

                <div class="cta-wrap">
                    <a href="{{ $confirmUrl }}" class="cta-button">{{ $t['button'] }}</a>
                </div>

                <p class="body-text" style="text-align:center; font-size:12.5px; opacity:0.8;">{{ $t['expiry'] }}</p>
                <p class="body-text" style="text-align:center; font-size:12px; opacity:0.65;">{{ $t['notYou'] }}</p>

                @include('amana-shared::emails.partials._closing')

            </div>

            @include('amana-shared::emails.partials._footer')

        </div>
    </div>
</body>

</html>
