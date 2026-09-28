{{-- resources/views/livraison/partials/chargement-route.blade.php --}}
{{--
    Carte d'UNE tournée sur l'écran chargement. Extraite de chargement.blade.php
    (polling, Scénario 1) pour être rendue à l'identique par la page initiale ET
    par ChargementController::liste() (endpoint de polling) — une seule source
    de vérité pour le balisage, voir ChargementController::lignes().
    Attend : $route (RouteLivraison avec ->urgence déjà calculée).
--}}
{{--
    Urgence + statut affichés en permanence (08/09/2026,
    prompt de cette date §7.1/§7.2/§7.3) : la ligne ne
    disparaît plus au chargement confirmé (voir
    ChargementController::index(), qui n'exclut plus
    'charge'/'packaging_annule' — RENOMMÉ le 09/09/2026
    depuis 'en_cours', voir le docblock de la migration
    routes), et les tournées urgentes
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
    {{ $route->statut === 'charge' ? 'opacity-60' : '' }}"
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
                    'charge' => 'bg-emerald-100 text-emerald-700',
                    'packaging_annule' => 'bg-rose-100 text-rose-700',
                    default => 'bg-stone-100 text-ink-muted',
                } }}">
                {{ match($route->statut) {
                    'charge' => 'Chargée',
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
