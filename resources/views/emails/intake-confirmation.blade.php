{{-- resources/views/emails/intake-confirmation.blade.php --}}
@php
    $t = [
        'fr' => [
            'greeting' => 'Bonjour',
            'badge' => '📝 Confirmation',
            'intro' => "Merci d'avoir rempli notre formulaire de demande d'aide. Pour que votre dossier soit transmis à notre équipe, merci de confirmer votre demande en cliquant sur le bouton ci-dessous.",
            'expiry' => 'Ce lien est valable 48 heures. Passé ce délai, il faudra soumettre une nouvelle demande.',
            'notYou' => "Si vous n'êtes pas à l'origine de cette demande, vous pouvez ignorer cet email — aucun dossier ne sera créé sans confirmation.",
            'button' => '✅ Confirmer ma demande',
        ],
        'ar' => [
            'greeting' => 'مرحبا',
            'badge' => '📝 تأكيد',
            'intro' => 'شكرًا لتعبئة نموذج طلب المساعدة الخاص بنا. لكي يتم إرسال ملفكم إلى فريقنا، يرجى تأكيد طلبكم بالضغط على الزر أدناه.',
            'expiry' => 'هذا الرابط صالح لمدة 48 ساعة. بعد هذه المدة، سيتعين عليكم تقديم طلب جديد.',
            'notYou' => 'إذا لم تكونوا أنتم من قدّم هذا الطلب، يمكنكم تجاهل هذه الرسالة — لن يتم إنشاء أي ملف بدون تأكيد.',
            'button' => '✅ تأكيد طلبي',
        ],
        'en' => [
            'greeting' => 'Hello',
            'badge' => '📝 Confirmation',
            'intro' => 'Thank you for filling out our request for help form. For your file to be sent to our team, please confirm your request by clicking the button below.',
            'expiry' => 'This link is valid for 48 hours. After that, you will need to submit a new request.',
            'notYou' => "If you didn't submit this request, you can ignore this email — no file will be created without confirmation.",
            'button' => '✅ Confirm my request',
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