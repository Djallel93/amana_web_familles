{{-- resources/views/livraison/scan-confirme.blade.php --}}
{{--
    Page atterrie après scan du QR au verso d'une étiquette de colis —
    voir MaRouteController::confirmerScan()/QrCodeService. La confirmation
    a déjà eu lieu côté serveur avant même l'affichage de cette page (voir
    le contrôleur) — cet écran ne fait que confirmer visuellement au
    bénévole que ça a fonctionné.
--}}
@extends('layouts.app')

@section('title', 'Livraison confirmée — AMANA Familles')

@section('content')
    <div class="max-w-sm mx-auto py-16 text-center">
        <div class="text-5xl mb-4">✅</div>
        <h1 class="font-heading text-xl font-semibold text-ink mb-2">Livraison confirmée</h1>
        <p class="text-ink-muted text-[14px] mb-6">
            {{ $etape->livraison->famille->prenom }} {{ $etape->livraison->famille->nom }}
        </p>
        <a href="{{ route('livraison.benevole.ma-route.show') }}" class="text-[13px] text-accent underline">
            Retour à ma tournée
        </a>
    </div>
@endsection
