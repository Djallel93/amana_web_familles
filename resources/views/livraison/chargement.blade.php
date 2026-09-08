{{-- resources/views/livraison/chargement.blade.php --}}
@extends('layouts.app')

@section('title', 'Chargement — AMANA Familles')

@section('content')
    <div class="max-w-3xl mx-auto py-8">
        {{--
            Retour visible (07/09/2026, prompt §4.2) — même bouton plein
            bg-ink que Pesee/Réception/Packaging/Suivi livraison.
        --}}
        <a href="{{ $urlRetour }}"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
            ← Retour à la campagne
        </a>

        <div class="flex items-center justify-between gap-3 mb-6">
            <h1 class="font-heading text-xl font-semibold text-ink">Chargement — tournées prêtes</h1>
            {{--
                Planche d'étiquettes pour toute la campagne (07/09/2026,
                prompt §4.1) — voir ChargementController::etiquettesCampagne().
            --}}
            <a href="{{ route('livraison.chargement.etiquettes', $campagne) }}" target="_blank"
                class="text-[12.5px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted shrink-0 bg-white hover:bg-stone-50">
                🖨️ Étiquettes — toute la campagne
            </a>
        </div>
        <form id="csrf-holder">@csrf</form>

        <div class="space-y-3" id="liste-routes">
            @forelse($routes as $route)
                {{--
                    Urgence + statut affichés en permanence (08/09/2026,
                    prompt de cette date §7.1/§7.2/§7.3) : la ligne ne
                    disparaît plus au chargement confirmé (voir
                    ChargementController::index(), qui n'exclut plus
                    'en_cours'/'packaging_annule'), et les tournées urgentes
                    (créneau en cours = seul créneau possible pour une
                    famille, ou à défaut pour le chauffeur — voir
                    calculerUrgence()) remontent en tête avec une bordure
                    colorée plutôt qu'un badge discret, pour être
                    repérables au premier coup d'œil sur cet écran souvent
                    consulté en vitesse.
                --}}
                <div class="bg-surface border rounded-xl p-4
                    {{ match($route->urgence) {
                        'famille' => 'border-rose-400 border-2 bg-rose-50',
                        'benevole' => 'border-amber-400 border-2 bg-amber-50',
                        default => 'border-surface-border',
                    } }}
                    {{ $route->statut === 'en_cours' ? 'opacity-60' : '' }}"
                    id="route-{{ $route->id }}" data-statut="{{ $route->statut }}">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-[14px] font-medium text-ink">
                            {{ $route->benevole->prenom ?? '' }} {{ $route->benevole->nom ?? '' }}
                            — {{ $route->etapes->count() }} arrêt(s)
                        </span>
                        <div class="flex items-center gap-1.5">
                            @if($route->urgence === 'famille')
                                <span class="text-[11px] font-medium px-2 py-0.5 rounded-full bg-rose-600 text-white">🔴 Urgent — famille</span>
                            @elseif($route->urgence === 'benevole')
                                <span class="text-[11px] font-medium px-2 py-0.5 rounded-full bg-amber-600 text-white">🟠 Urgent — chauffeur</span>
                            @endif
                            <span class="statut-route text-[11px] font-medium px-2 py-0.5 rounded-full
                                {{ match($route->statut) {
                                    'en_cours' => 'bg-emerald-100 text-emerald-700',
                                    'packaging_annule' => 'bg-rose-100 text-rose-700',
                                    default => 'bg-stone-100 text-ink-muted',
                                } }}">
                                {{ match($route->statut) {
                                    'en_cours' => 'Chargée',
                                    'packaging_annule' => 'Packaging annulé',
                                    default => 'Prête à charger',
                                } }}
                            </span>
                            <span class="text-[12px] text-ink-muted">{{ $route->creneau ? \App\Support\Creneau::libelle($route->creneau) : 'Imposée' }}</span>
                        </div>
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

                    <div class="flex gap-2" @if($route->statut !== 'chargement') style="display:none" @endif>
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
            if (!r.success) return;

            // Ne retire plus la ligne du DOM (08/09/2026, prompt de cette
            // date §7.1/§7.2) : reste visible avec son statut "Chargée",
            // déplacée en bas de liste plutôt que supprimée — voir
            // ChargementController::index() côté serveur pour le même tri
            // au prochain chargement de page.
            const ligne = document.getElementById(`route-${id}`);
            if (!ligne) return;

            ligne.dataset.statut = 'en_cours';
            ligne.classList.add('opacity-60');
            ligne.querySelector('.statut-route').textContent = 'Chargée';
            ligne.querySelector('.statut-route').className = 'statut-route text-[11px] font-medium px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700';
            ligne.querySelector('.flex.gap-2').style.display = 'none';
            ligne.parentElement.appendChild(ligne);
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
