{{-- resources/views/livraison/retrait-hq-scan.blade.php --}}
{{--
    Page atterrie après scan du QR envoyé par RetraitHqNotification — voir
    RetraitHqController::scan() (24/09/2026, prompt de cette date §2).
    Même principe que scan-confirme.blade.php (MaRouteController) : la
    confirmation a déjà eu lieu côté serveur avant l'affichage (si la
    ligne était "Prête"), cet écran ne fait que le confirmer visuellement
    à l'équipe chargement.
--}}
@extends('layouts.app')

@section('title', 'Retrait QG — AMANA Familles')

@section('content')
    <div class="max-w-sm mx-auto py-16 text-center">
        @if($statutAffiche === 'delivre')
            <div class="text-5xl mb-4">✅</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">Colis remis</h1>
        @elseif($statutAffiche === 'non_delivre')
            <div class="text-5xl mb-4">⚠️</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">Déjà marquée "Non livrée"</h1>
        @else
            <div class="text-5xl mb-4">⏳</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">Colis pas encore prêt</h1>
        @endif

        <p class="text-ink-muted text-[14px] mb-1">
            {{ $livraison->famille->prenom }} {{ $livraison->famille->nom }}
        </p>
        <p class="text-ink-muted text-[12px] mb-6">Dossier #{{ $livraison->famille->id }}</p>

        @if($statutAffiche === 'non_delivre')
            <p class="text-[12px] text-ink-muted mb-6">La famille est finalement présente ? Utilisez l'écran Retrait QG pour la marquer livrée.</p>
        @endif

        <a href="{{ route('livraison.retrait-hq.index', $livraison->campagne) }}" class="text-[13px] text-accent underline">
            Retour à l'écran Retrait QG
        </a>
    </div>
@endsection
