{{-- resources/views/familles/nouvelles.blade.php --}}
{{--
    File d'attente des dossiers pas encore ouverts par le staff
    (etat_dossier = 'Recu', réservé aux soumissions du formulaire public
    d'intake — voir Famille::ETATS_MODIFIABLES et
    FamillesController::nouvelles()). Tri par ancienneté par défaut (le
    plus vieux d'abord) plutôt que par criticité comme la liste générale,
    pour qu'aucune demande ne reste oubliée — voir
    FamillesController::appliquerTri(colonneDefaut: 'created_at').
    Réutilise le même panneau de détail/édition que familles/index.blade.php
    (DetailPanel.vue) — ouvrir un dossier ici fonctionne exactement pareil.

    Tableau/carte mobile identiques à familles/index.blade.php depuis le
    10/09/2026 (Section A2 du refactor, décision du 10/09/2026 : "same
    everywhere") — mêmes colonnes, sélecteur de colonnes, avatar, criticité,
    badges, via familles.partials.tableau. Seuls le formulaire de recherche
    ci-dessous et le tri par défaut restent propres à cette vue.
--}}
@extends('layouts.app')

@section('title', 'Nouvelles demandes — AMANA Familles')

@section('content')

    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Nouvelles demandes</h1>
            <p class="text-[13px] text-ink-muted mt-1">
                {{ $familles->total() }} demande{{ $familles->total() !== 1 ? 's' : '' }} pas encore ouverte{{ $familles->total() !== 1 ? 's' : '' }},
                triées de la plus ancienne à la plus récente
            </p>
        </div>
    </div>

    <form method="GET" action="{{ route('familles.nouvelles') }}"
        class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 mb-5">
        {{-- Voir index.blade.php pour le même correctif du 12/08/2026. --}}
        <input type="hidden" name="per_page" value="{{ request('per_page', \App\Models\Famille::PAGINATION_PAR_PAGE_DEFAUT) }}">
        <div class="flex gap-3">
            <input type="text" name="recherche" value="{{ request('recherche') }}" placeholder="Nom, prénom, téléphone…"
                class="flex-1 px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface-2 outline-none
                        focus:border-accent focus:bg-surface focus:shadow-[0_0_0_3px_rgba(180,83,9,0.15)]">
            <button type="submit"
                class="px-4 py-2 bg-accent hover:bg-accent-dark text-white text-[12.5px] font-semibold rounded-md transition-colors min-h-[38px]">
                Filtrer
            </button>
        </div>
    </form>

    @include('familles.partials.tableau', [
        'familles' => $familles,
        'triActuel' => request('tri'),
        'directionActuelle' => request('direction') === 'desc' ? 'desc' : 'asc',
        'routeTri' => 'familles.nouvelles',
        'videIcone' => '📭',
        'videTitre' => 'Aucune nouvelle demande',
        'aFiltresActifs' => request()->filled('recherche'),
        'videMessageBase' => "Tout est à jour — aucune soumission en attente d'ouverture.",
        'videMessageFiltre' => 'Aucun résultat pour cette recherche.',
        'videLienReinitialisation' => null,
    ])

    {{-- Même panneau de détail/édition que familles/index.blade.php — voir
         resources/js/components/familles/DetailPanel.vue --}}
    @include('familles.partials.vue-famille-detail', ['secteursActivite' => $secteursActivite, 'organismesAide' => $organismesAide])

@endsection
