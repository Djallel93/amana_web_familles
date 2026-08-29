{{-- resources/views/emails/verification-famille.blade.php --}}
@php
    // Reprend getEmailTranslations() de emailVerificationService.js
    // (amana_familles), traduit vers nos 3 codes langue (fr/ar/en).
    $t = [
        'fr' => [
            'greeting' => 'Bonjour',
            'intro' => 'Nous espérons que vous allez bien. Dans le cadre de notre suivi, nous souhaitons vérifier que vos informations sont toujours à jour.',
            'currentInfo' => 'Vos informations actuelles :',
            'name' => 'Nom complet',
            'phone' => 'Téléphone',
            'address' => 'Adresse',
            'adults' => "Nombre d'adultes",
            'children' => "Nombre d'enfants",
            'question' => 'Vos informations sont-elles toujours correctes ?',
            'buttonUpToDate' => '✅ Tout est à jour',
            'buttonChanged' => '📝 Mes informations ont changé',
        ],
        'ar' => [
            'greeting' => 'مرحبا',
            'intro' => 'نأمل أن تكون بخير. كجزء من متابعتنا، نود التحقق من أن معلوماتك لا تزال محدثة.',
            'currentInfo' => 'معلوماتك الحالية:',
            'name' => 'الاسم الكامل',
            'phone' => 'الهاتف',
            'address' => 'العنوان',
            'adults' => 'عدد البالغين',
            'children' => 'عدد الأطفال',
            'question' => 'هل معلوماتك لا تزال صحيحة؟',
            'buttonUpToDate' => '✅ كل شيء محدث',
            'buttonChanged' => '📝 تغيرت معلوماتي',
        ],
        'en' => [
            'greeting' => 'Hello',
            'intro' => 'We hope you are doing well. As part of our follow-up, we would like to verify that your information is still up to date.',
            'currentInfo' => 'Your current information:',
            'name' => 'Full name',
            'phone' => 'Phone',
            'address' => 'Address',
            'adults' => 'Number of adults',
            'children' => 'Number of children',
            'question' => 'Is your information still correct?',
            'buttonUpToDate' => '✅ Everything is up to date',
            'buttonChanged' => '📝 My information has changed',
        ],
    ][$langue];
@endphp
<!DOCTYPE html>
<html lang="{{ $langue }}" dir="{{ $langue === 'ar' ? 'rtl' : 'ltr' }}">

<head>
    <title>{{ $t['currentInfo'] }}</title>
    @include('amana-shared::emails.partials._head')
</head>

<body>
    <div class="shell">
        <div class="wrapper">

            @include('amana-shared::emails.partials._header', [
                'badge' => '🔎 Vérification',
                'title' => $t['greeting'] . ', ' . e($famille->prenom),
                'titleSub' => 'AMANA — Pôle social',
            ])

            <div class="stripe"></div>

            <div class="body">

                <p class="body-text">{{ $t['intro'] }}</p>

                <table class="info-box" role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td class="info-content">
                            <div class="info-title">{{ $t['currentInfo'] }}</div>
                            <div class="info-text">
                                <strong>{{ $t['name'] }} :</strong> {{ $famille->prenom }} {{ $famille->nom }}<br>
                                <strong>{{ $t['phone'] }} :</strong> {{ $famille->telephone }}<br>
                                <strong>{{ $t['address'] }} :</strong> {{ $famille->adresse }}
                                @if($famille->code_postal || $famille->ville_texte)
                                    , {{ $famille->code_postal }} {{ $famille->ville_texte }}
                                @endif
                                <br>
                                <strong>{{ $t['adults'] }} :</strong> {{ $famille->nombre_adulte }} —
                                <strong>{{ $t['children'] }} :</strong> {{ $famille->nombre_enfant }}
                            </div>
                        </td>
                    </tr>
                </table>

                <p class="body-text" style="text-align:center; font-weight:600;">{{ $t['question'] }}</p>

                <div class="cta-wrap">
                    <a href="{{ $confirmUrl }}" class="cta-button">{{ $t['buttonUpToDate'] }}</a>
                    <p class="cta-note">
                        <a href="{{ $updateUrl }}"
                            style="color:{{ config('amana-shared.email_theme')['accent'] }};">{{ $t['buttonChanged'] }}</a>
                    </p>
                </div>

                @include('amana-shared::emails.partials._closing')

            </div>

            @include('amana-shared::emails.partials._footer')

        </div>
    </div>
</body>

</html>