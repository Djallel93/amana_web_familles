{{-- resources/views/emails/campagne-disponibilite.blade.php --}}
@php
    $t = [
        'fr' => [
            'greeting' => 'Bonjour',
            'badge' => '📅 Nouvelle campagne',
            'intro' => "Une nouvelle campagne de livraison démarre bientôt. Merci de confirmer votre véhicule, votre zone de couverture et vos créneaux de disponibilité.",
            'button' => '🚚 Confirmer ma disponibilité',
            'edit' => 'Vous pourrez modifier vos réponses à tout moment depuis votre compte.',
        ],
        'ar' => [
            'greeting' => 'مرحبا',
            'badge' => '📅 حملة جديدة',
            'intro' => 'ستنطلق قريبًا حملة توصيل جديدة. يرجى تأكيد مركبتكم ومنطقة تغطيتكم وأوقات توفركم.',
            'button' => '🚚 تأكيد توفري',
            'edit' => 'يمكنكم تعديل إجاباتكم في أي وقت من حسابكم.',
        ],
        'en' => [
            'greeting' => 'Hello',
            'badge' => '📅 New campaign',
            'intro' => 'A new delivery campaign is starting soon. Please confirm your vehicle, coverage area, and availability time slots.',
            'button' => '🚚 Confirm my availability',
            'edit' => 'You can update your answers at any time from your account.',
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
                    <a href="{{ $disponibiliteUrl }}" class="cta-button">{{ $t['button'] }}</a>
                </div>

                <p class="body-text" style="text-align:center; font-size:12px; opacity:0.65;">{{ $t['edit'] }}</p>

                @include('amana-shared::emails.partials._closing')

            </div>

            @include('amana-shared::emails.partials._footer')

        </div>
    </div>
</body>

</html>
