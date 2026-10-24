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
        @elseif($statutAffiche === 'prete')
            <div class="text-5xl mb-4">📦</div>
            <h1 id="titre-scan" class="font-heading text-xl font-semibold text-ink mb-2">Colis prêt à remettre</h1>
        @else
            <div class="text-5xl mb-4">⏳</div>
            <h1 class="font-heading text-xl font-semibold text-ink mb-2">Colis pas encore prêt</h1>
        @endif

        <p class="text-ink-muted text-[14px] mb-1">
            {{ $livraison->famille->prenom }} {{ $livraison->famille->nom }}
        </p>
        <p class="text-ink-muted text-[12px] mb-6">Dossier #{{ $livraison->famille->id }}</p>

        {{--
            Confirmation explicite (23/10/2026) : le scan seul ne marque plus la famille livrée —
            un scan par erreur était irréversible. Le bouton appelle la même route `livre` que
            l'écran Retrait QG.
        --}}
        @if($statutAffiche === 'prete')
            <form id="csrf-holder">@csrf</form>
            <button type="button" id="bouton-confirmer-remise" onclick="confirmerRemise()"
                class="text-[14px] px-5 py-2.5 rounded-lg bg-accent text-white mb-6">Confirmer la remise du colis</button>
            <script>
                async function confirmerRemise() {
                    const ok = await window.amanaConfirm({
                        title: 'Confirmer la remise',
                        message: 'Confirmer que le colis a bien été remis à cette famille ?',
                        confirmLabel: 'Oui, livré',
                        cancelLabel: 'Annuler',
                    });
                    if (!ok) return;
                    const reponse = await fetch(@json(route('livraison.retrait-hq.livre', $livraison)), {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('#csrf-holder input[name="_token"]').value,
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                        },
                        body: '{}',
                    });
                    const resultat = await reponse.json().catch(() => ({}));
                    if (!resultat.success) { window.amanaToast(resultat.message || 'Échec de la confirmation.', 'error'); return; }
                    window.location.reload();
                }
            </script>
        @endif

        @if($statutAffiche === 'non_delivre')
            <p class="text-[12px] text-ink-muted mb-6">La famille est finalement présente ? Utilisez l'écran Retrait QG pour la marquer livrée.</p>
        @endif

        <a href="{{ route('livraison.retrait-hq.index', $livraison->campagne) }}" class="text-[13px] text-accent underline">
            Retour à l'écran Retrait QG
        </a>
    </div>
@endsection
