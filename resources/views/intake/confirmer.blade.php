{{-- resources/views/intake/confirmer.blade.php --}}
{{--
    Page publique, standalone — accessible via le lien reçu par email
    (IntakeConfirmationNotification). $etat ∈ a_confirmer | confirmee |
    expiree | introuvable. Contrairement à verification/show.blade.php
    (FR uniquement), cette page est multilingue : la famille peut être
    arabophone ou anglophone, et $demande->langue (ou 'fr' par défaut si
    la ligne est introuvable) pilote à la fois le texte et le sens RTL/LTR.
--}}
@php
    $t = [
        'fr' => [
            'title' => 'Confirmation de vos informations — AMANA Familles',
            'a_confirmer_emoji' => '📋',
            'a_confirmer_title' => 'Confirmer votre demande',
            'a_confirmer_text' => "Merci de confirmer votre demande d'aide en cliquant sur le bouton ci-dessous. Votre dossier ne sera transmis à notre équipe qu'après cette confirmation.",
            'button' => '✅ Confirmer ma demande',
            'confirmee_emoji' => '✅',
            'confirmee_title' => 'Merci !',
            'confirmee_text' => 'Votre demande a bien été confirmée et transmise à notre équipe. Nous reviendrons vers vous dans les plus brefs délais.',
            'expiree_emoji' => '⏰',
            'expiree_title' => 'Ce lien a expiré',
            'expiree_text' => "Ce lien de confirmation n'est plus valable (48 heures écoulées). Merci de soumettre une nouvelle demande.",
            'expiree_cta' => 'Soumettre une nouvelle demande',
            'introuvable_emoji' => '❓',
            'introuvable_title' => 'Lien invalide',
            'introuvable_text' => 'Ce lien de confirmation est introuvable ou incorrect.',
        ],
        'ar' => [
            'title' => 'تأكيد معلوماتكم — AMANA Familles',
            'a_confirmer_emoji' => '📋',
            'a_confirmer_title' => 'تأكيد طلبكم',
            'a_confirmer_text' => 'يرجى تأكيد طلب المساعدة الخاص بكم بالضغط على الزر أدناه. لن يتم إرسال ملفكم إلى فريقنا إلا بعد هذا التأكيد.',
            'button' => '✅ تأكيد طلبي',
            'confirmee_emoji' => '✅',
            'confirmee_title' => 'شكرًا لكم!',
            'confirmee_text' => 'تم تأكيد طلبكم بنجاح وإرساله إلى فريقنا. سنعود إليكم في أقرب وقت ممكن.',
            'expiree_emoji' => '⏰',
            'expiree_title' => 'انتهت صلاحية هذا الرابط',
            'expiree_text' => 'رابط التأكيد هذا لم يعد صالحًا (مرت 48 ساعة). يرجى تقديم طلب جديد.',
            'expiree_cta' => 'تقديم طلب جديد',
            'introuvable_emoji' => '❓',
            'introuvable_title' => 'رابط غير صالح',
            'introuvable_text' => 'رابط التأكيد هذا غير موجود أو غير صحيح.',
        ],
        'en' => [
            'title' => 'Confirm your information — AMANA Familles',
            'a_confirmer_emoji' => '📋',
            'a_confirmer_title' => 'Confirm your request',
            'a_confirmer_text' => 'Please confirm your request for help by clicking the button below. Your file will only be sent to our team after this confirmation.',
            'button' => '✅ Confirm my request',
            'confirmee_emoji' => '✅',
            'confirmee_title' => 'Thank you!',
            'confirmee_text' => 'Your request has been confirmed and sent to our team. We will get back to you as soon as possible.',
            'expiree_emoji' => '⏰',
            'expiree_title' => 'This link has expired',
            'expiree_text' => 'This confirmation link is no longer valid (48 hours have passed). Please submit a new request.',
            'expiree_cta' => 'Submit a new request',
            'introuvable_emoji' => '❓',
            'introuvable_title' => 'Invalid link',
            'introuvable_text' => 'This confirmation link could not be found or is incorrect.',
        ],
    ][in_array($langue, ['fr', 'ar', 'en'], true) ? $langue : 'fr'];
@endphp
<!DOCTYPE html>
<html lang="{{ $langue }}" dir="{{ $langue === 'ar' ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $t['title'] }}</title>
    @vite(['resources/css/app.css'])
</head>

<body class="bg-surface-2 font-body text-ink antialiased min-h-screen flex items-center justify-center px-4">

    <div class="max-w-md w-full bg-surface rounded-xl border border-surface-border shadow-sm p-8 text-center">

        <img src="{{ asset('images/amana-logo.png') }}" alt="AMANA" class="w-14 h-14 rounded-full object-cover mx-auto mb-5">

        @if($etat === 'a_confirmer')
            <div class="text-4xl mb-4">{{ $t['a_confirmer_emoji'] }}</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">{{ $t['a_confirmer_title'] }}</h1>
            <p class="text-ink-muted text-[14px] mb-6 leading-relaxed">{{ $t['a_confirmer_text'] }}</p>
            <form action="{{ route('intake.confirmer', $demande->token) }}" method="POST">
                @csrf
                <button type="submit"
                    class="w-full min-h-[48px] px-6 py-3 bg-accent hover:bg-accent-dark text-white font-bold text-[14px] rounded-lg
                            shadow-[0_3px_14px_rgba(180,83,9,0.3)] transition-all cursor-pointer">
                    {{ $t['button'] }}
                </button>
            </form>

        @elseif($etat === 'confirmee')
            <div class="text-4xl mb-4">{{ $t['confirmee_emoji'] }}</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">{{ $t['confirmee_title'] }}</h1>
            <p class="text-ink-muted text-[14px]">{{ $t['confirmee_text'] }}</p>

        @elseif($etat === 'expiree')
            <div class="text-4xl mb-4">{{ $t['expiree_emoji'] }}</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">{{ $t['expiree_title'] }}</h1>
            <p class="text-ink-muted text-[14px] mb-6">{{ $t['expiree_text'] }}</p>
            <a href="{{ route('intake.show', ['langue' => $langue]) }}"
                class="block text-center text-[13px] text-accent hover:text-accent-dark transition-colors no-underline font-semibold">
                📝 {{ $t['expiree_cta'] }}
            </a>

        @else
            <div class="text-4xl mb-4">{{ $t['introuvable_emoji'] }}</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">{{ $t['introuvable_title'] }}</h1>
            <p class="text-ink-muted text-[14px]">{{ $t['introuvable_text'] }}</p>
        @endif

    </div>

</body>
</html>
