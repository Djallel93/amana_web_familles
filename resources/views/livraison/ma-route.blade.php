{{-- resources/views/livraison/ma-route.blade.php --}}
@extends('layouts.app')

@section('title', 'Ma tournée — AMANA Familles')

@section('content')
    <div class="max-w-xl mx-auto py-8">
        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Ma tournée</h1>
        <form id="csrf-holder">@csrf</form>

        @forelse($routes as $route)
            <div class="bg-surface border border-surface-border rounded-xl p-5 mb-5">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[13px] font-medium text-ink">
                        {{ $route->creneau ? \App\Support\Creneau::libelle($route->creneau) : 'Livraisons imposées' }}
                    </span>
                    <span class="text-[12px] text-ink-muted">{{ $route->statut }}</span>
                </div>

                @if($route->lien_maps)
                    <a href="{{ $route->lien_maps }}" target="_blank"
                        class="inline-block mb-4 text-[13px] text-accent underline">Ouvrir dans Google Maps</a>
                @endif

                <ol class="space-y-3">
                    @foreach($route->etapes as $etape)
                        <li class="flex items-start justify-between gap-3 border-t border-surface-border pt-3 first:border-t-0 first:pt-0">
                            <div>
                                <p class="text-[14px] font-medium text-ink">
                                    {{ $etape->ordre }}. {{ $etape->livraison->famille->prenom }} {{ $etape->livraison->famille->nom }}
                                </p>
                                <p class="text-[12px] text-ink-muted">{{ $etape->livraison->famille->adresse }}</p>
                                <p class="text-[12px] text-ink-muted">{{ $etape->livraison->famille->telephone }}</p>
                                <span class="inline-block mt-1 text-[11px] px-2 py-0.5 rounded-full
                                    @class([
                                        'bg-emerald-100 text-emerald-700' => $etape->statut === 'livree',
                                        'bg-rose-100 text-rose-700' => $etape->statut === 'ignoree',
                                        'bg-stone-100 text-stone-600' => $etape->statut === 'en_attente',
                                    ])">{{ $etape->statut }}</span>
                            </div>

                            @if($etape->statut === 'en_attente')
                                <div class="flex flex-col gap-1 shrink-0">
                                    <button type="button" onclick="confirmerEtape({{ $etape->id }})"
                                        class="text-[12px] px-3 py-1.5 rounded-lg bg-accent text-white">Livré</button>
                                    <button type="button" onclick="signalerIgnoree({{ $etape->id }})"
                                        class="text-[12px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted">Ignorer</button>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        @empty
            <p class="text-[14px] text-ink-muted">Aucune tournée active pour le moment.</p>
        @endforelse
    </div>

    <script>
        const csrf = document.querySelector('#csrf-holder input[name="_token"]').value;

        async function confirmerEtape(id) {
            await fetch(`/livraison/benevole/etapes/${id}/confirmer`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            });
            window.location.reload();
        }

        async function signalerIgnoree(id) {
            const notes = prompt('Raison (optionnel) :') || '';
            await fetch(`/livraison/benevole/etapes/${id}/ignoree`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ notes }),
            });
            window.location.reload();
        }
    </script>
@endsection
