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
    badges, via familles.partials.tableau. Panneau de filtres partagé
    (Section A3, même jour) : mêmes groupes Localisation/Organisation/
    Criticité/Caractéristiques que Dossier Familles, sans Statut (toujours
    etat_dossier='Recu' ici) ni autocomplétion Nom/Téléphone (simple champ
    recherche, comme les écrans livraison) — voir FamilleFilterPanel.vue.
    Seul le tri par défaut (le plus vieux d'abord) reste propre à cette vue.
--}}
@extends('layouts.app')

@section('title', 'Nouvelles demandes — AMANA Familles')

@section('content')

    @php
        $aFiltresActifs = request()->anyFilled([
            'recherche', 'id_ville', 'id_secteur', 'id_quartier', 'criticite',
            'se_deplace', 'est_hotel', 'etudiant', 'zakat_el_fitr', 'sadaqa',
            'id_organisation_origine', 'id_organisation_rattachee',
        ]);
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Nouvelles demandes</h1>
            <p class="text-[13px] text-ink-muted mt-1">
                {{ $familles->total() }} demande{{ $familles->total() !== 1 ? 's' : '' }} pas encore ouverte{{ $familles->total() !== 1 ? 's' : '' }},
                triées de la plus ancienne à la plus récente
            </p>
        </div>
    </div>

    @include('familles.partials.filtres', [
        'villes' => $villes,
        'secteurs' => $secteurs,
        'quartiers' => $quartiers,
        'organisations' => $organisations,
        'valeursFiltres' => $valeursFiltres,
        'avecStatut' => false,
        'avecAutocompletion' => false,
        'ouvertParDefaut' => false,
        'routeIndex' => 'familles.nouvelles',
    ])

    @include('familles.partials.tableau', [
        'familles' => $familles,
        'triActuel' => request('tri'),
        'directionActuelle' => request('direction') === 'desc' ? 'desc' : 'asc',
        'routeTri' => 'familles.nouvelles',
        'videIcone' => '📭',
        'videTitre' => 'Aucune nouvelle demande',
        'aFiltresActifs' => $aFiltresActifs,
        'videMessageBase' => "Tout est à jour — aucune soumission en attente d'ouverture.",
        'videMessageFiltre' => 'Aucun résultat pour ces filtres.',
        'videLienReinitialisation' => null,
    ])

    {{-- Même panneau de détail/édition que familles/index.blade.php — voir
         resources/js/components/familles/DetailPanel.vue --}}
    @include('familles.partials.vue-famille-detail', ['secteursActivite' => $secteursActivite, 'organismesAide' => $organismesAide])

@endsection
