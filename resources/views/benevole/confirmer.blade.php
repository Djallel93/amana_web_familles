{{-- resources/views/benevole/confirmer.blade.php --}}
{{--
    Page publique, standalone — accessible via le lien reçu par email
    (BenevoleIntakeConfirmationNotification). Miroir exact de
    intake/confirmer.blade.php (familles), voir ce fichier pour le
    raisonnement. $etat ∈ confirmee | expiree | introuvable.

    Confirmation en un clic (30/08/2026) : le lien de l'email confirme
    directement, plus d'écran "a_confirmer" avec un second bouton.
--}}
@php
    $t = [
        'fr' => [
            'title' => 'Confirmation de votre candidature — AMANA Familles',
            'confirmee_emoji' => '✅',
            'confirmee_title' => 'Merci !',
            'confirmee_text' => 'Votre candidature a bien été confirmée et transmise à notre équipe. Nous reviendrons vers vous dans les plus brefs délais.',
            'expiree_emoji' => '⏰',
            'expiree_title' => 'Ce lien a expiré',
            'expiree_text' => "Ce lien de confirmation n'est plus valable (48 heures écoulées). Merci de soumettre une nouvelle candidature.",
            'expiree_cta' => 'Soumettre une nouvelle candidature',
            'introuvable_emoji' => '❓',
            'introuvable_title' => 'Lien invalide',
            'introuvable_text' => 'Ce lien de confirmation est introuvable ou incorrect.',
        ],
        'ar' => [
            'title' => 'تأكيد ترشحكم — AMANA Familles',
            'confirmee_emoji' => '✅',
            'confirmee_title' => 'شكرًا لكم!',
            'confirmee_text' => 'تم تأكيد ترشحكم بنجاح وإرساله إلى فريقنا. سنعود إليكم في أقرب وقت ممكن.',
            'expiree_emoji' => '⏰',
            'expiree_title' => 'انتهت صلاحية هذا الرابط',
            'expiree_text' => 'رابط التأكيد هذا لم يعد صالحًا (مرت 48 ساعة). يرجى تقديم ترشح جديد.',
            'expiree_cta' => 'تقديم ترشح جديد',
            'introuvable_emoji' => '❓',
            'introuvable_title' => 'رابط غير صالح',
            'introuvable_text' => 'رابط التأكيد هذا غير موجود أو غير صحيح.',
        ],
        'en' => [
            'title' => 'Confirm your application — AMANA Familles',
            'confirmee_emoji' => '✅',
            'confirmee_title' => 'Thank you!',
            'confirmee_text' => 'Your application has been confirmed and sent to our team. We will get back to you as soon as possible.',
            'expiree_emoji' => '⏰',
            'expiree_title' => 'This link has expired',
            'expiree_text' => 'This confirmation link is no longer valid (48 hours have passed). Please submit a new application.',
            'expiree_cta' => 'Submit a new application',
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

        @if($etat === 'confirmee')
            <div class="text-4xl mb-4">{{ $t['confirmee_emoji'] }}</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">{{ $t['confirmee_title'] }}</h1>
            <p class="text-ink-muted text-[14px]">{{ $t['confirmee_text'] }}</p>

        @elseif($etat === 'expiree')
            <div class="text-4xl mb-4">{{ $t['expiree_emoji'] }}</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">{{ $t['expiree_title'] }}</h1>
            <p class="text-ink-muted text-[14px] mb-6">{{ $t['expiree_text'] }}</p>
            <a href="{{ route('benevole.show', ['langue' => $langue]) }}"
                class="block text-center text-[13px] text-accent hover:text-accent-dark transition-colors no-underline font-semibold">
                🤝 {{ $t['expiree_cta'] }}
            </a>

        @else
            <div class="text-4xl mb-4">{{ $t['introuvable_emoji'] }}</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">{{ $t['introuvable_title'] }}</h1>
            <p class="text-ink-muted text-[14px]">{{ $t['introuvable_text'] }}</p>
        @endif

    </div>

</body>
</html>
