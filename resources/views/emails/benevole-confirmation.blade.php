{{-- resources/views/emails/benevole-confirmation.blade.php --}}
@php
    $t = [
        'fr' => [
            'greeting' => 'Bonjour',
            'badge' => '🤝 Confirmation',
            'intro' => "Merci pour votre candidature bénévole ! Pour que votre candidature soit transmise à notre équipe, merci de la confirmer en cliquant sur le bouton ci-dessous.",
            'expiry' => 'Ce lien est valable 48 heures. Passé ce délai, il faudra soumettre une nouvelle candidature.',
            'notYou' => "Si vous n'êtes pas à l'origine de cette candidature, vous pouvez ignorer cet email — aucun profil ne sera créé sans confirmation.",
            'button' => '✅ Confirmer ma candidature',
        ],
        'ar' => [
            'greeting' => 'مرحبا',
            'badge' => '🤝 تأكيد',
            'intro' => 'شكرًا لترشحكم للتطوع! لكي يتم إرسال ترشحكم إلى فريقنا، يرجى تأكيده بالضغط على الزر أدناه.',
            'expiry' => 'هذا الرابط صالح لمدة 48 ساعة. بعد هذه المدة، سيتعين عليكم تقديم ترشح جديد.',
            'notYou' => 'إذا لم تكونوا أنتم من قدّم هذا الترشح، يمكنكم تجاهل هذه الرسالة — لن يتم إنشاء أي ملف بدون تأكيد.',
            'button' => '✅ تأكيد ترشحي',
        ],
        'en' => [
            'greeting' => 'Hello',
            'badge' => '🤝 Confirmation',
            'intro' => 'Thank you for applying to volunteer! For your application to be sent to our team, please confirm it by clicking the button below.',
            'expiry' => 'This link is valid for 48 hours. After that, you will need to submit a new application.',
            'notYou' => "If you didn't submit this application, you can ignore this email — no profile will be created without confirmation.",
            'button' => '✅ Confirm my application',
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