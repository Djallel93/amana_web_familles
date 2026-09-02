{{-- resources/views/livraison/chargement.blade.php --}}
@extends('layouts.app')

@section('title', 'Chargement — AMANA Familles')

@section('content')
    <div class="max-w-3xl mx-auto py-8">
        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Chargement — tournées prêtes</h1>
        <form id="csrf-holder">@csrf</form>

        <div class="space-y-3">
            @forelse($routes as $route)
                <div class="bg-surface border border-surface-border rounded-xl p-4" id="route-{{ $route->id }}">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-[14px] font-medium text-ink">
                            {{ $route->benevole->prenom ?? '' }} {{ $route->benevole->nom ?? '' }}
                            — {{ $route->etapes->count() }} arrêt(s)
                        </span>
                        <span class="text-[12px] text-ink-muted">{{ $route->creneau ? \App\Support\Creneau::libelle($route->creneau) : 'Imposée' }}</span>
                    </div>

                    <ul class="text-[12px] text-ink-muted space-y-1 mb-3">
                        @foreach($route->etapes as $etape)
                            <li>
                                {{ $etape->livraison->famille->prenom }} {{ $etape->livraison->famille->nom }}
                                @if($etape->livraison->famille->etudiant)<span class="text-sky-600">· étudiant</span>@endif
                                @if($etape->livraison->famille->est_hotel)<span class="text-amber-600">· hôtel</span>@endif
                                @if($etape->livraison->famille->nombre_enfant > 0)<span class="text-violet-600">· {{ $etape->livraison->famille->nombre_enfant }} enfant(s)</span>@endif
                            </li>
                        @endforeach
                    </ul>

                    <div class="flex gap-2">
                        <button type="button" onclick="confirmerChargement({{ $route->id }})"
                            class="text-[12px] px-3 py-1.5 rounded-lg bg-accent text-white">Chargement confirmé</button>
                        <button type="button" onclick="signalerAbsent({{ $route->id }})"
                            class="text-[12px] px-3 py-1.5 rounded-lg border border-rose-200 text-rose-600">Bénévole absent</button>
                        <button type="button" onclick="signalerCapacite({{ $route->id }})"
                            class="text-[12px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted">Problème de capacité</button>
                    </div>
                </div>
            @empty
                <p class="text-[14px] text-ink-muted">Aucune tournée prête à charger pour le moment.</p>
            @endforelse
        </div>
    </div>

    <script>
        const csrf = document.querySelector('#csrf-holder input[name="_token"]').value;

        async function poster(url, body = {}) {
            const reponse = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body),
            });
            return reponse.json();
        }

        async function confirmerChargement(id) {
            const r = await poster(`/livraison/chargement/routes/${id}/confirmer`);
            if (r.success) document.getElementById(`route-${id}`).remove();
        }

        async function signalerAbsent(id) {
            const notes = prompt('Détails (optionnel) :') || '';
            const r = await poster(`/livraison/chargement/routes/${id}/benevole-absent`, { notes });
            if (r.success) {
                alert('Incident signalé — les livraisons non chargées repassent en attente.');
                document.getElementById(`route-${id}`).remove();
            }
        }

        async function signalerCapacite(id) {
            const notes = prompt('Détails :') || '';
            const r = await poster(`/livraison/chargement/routes/${id}/capacite`, { notes });
            if (r.success) alert('Incident signalé à l\'admin.');
        }
    </script>
@endsection
