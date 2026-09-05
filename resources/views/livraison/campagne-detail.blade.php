{{-- resources/views/livraison/campagne-detail.blade.php --}}
@extends('layouts.app')

@section('title', 'Campagne — AMANA Familles')

@section('content')
    <div class="max-w-4xl mx-auto py-8">
        <a href="{{ route('livraison.campagnes.index') }}" class="text-[12px] text-ink-muted mb-2 inline-block">&larr; Campagnes</a>

        {{-- Reconstruit en Vue le 03/09/2026 (voir CampagneDetail.vue) —
             la Blade ne passe plus que ce qu'elle est seule à connaître
             sans aller-retour JSON (l'enregistrement Campagne déjà chargé,
             les référentiels quartiers/organisations pour les selects du
             filtre — voir CampagnesController::show()) ; tout le reste
             (éligibles/génération/notification/routes/non-couvertes) est
             chargé et mis à jour côté Vue via l'API JSON existante. --}}
        <div id="vue-livraison-campagne-detail"
            data-campagne="{{ $campagne->toJson() }}"
            data-quartiers="{{ $quartiers->toJson() }}"
            data-organisations="{{ $organisations->toJson() }}"
            data-eligibles-url="{{ route('livraison.campagnes.eligibles', $campagne) }}"
            data-generer-livraisons-url="{{ route('livraison.campagnes.generer-livraisons', $campagne) }}"
            data-notifier-benevoles-url="{{ route('livraison.campagnes.notifier-benevoles', $campagne) }}"
            data-generer-routes-url="{{ route('livraison.campagnes.generer-routes', $campagne) }}"
            data-non-couvertes-url="{{ route('livraison.campagnes.non-couvertes', $campagne) }}"
            data-avancement-url="{{ route('livraison.campagnes.avancement', $campagne) }}"
            data-contacts-url="{{ route('livraison.contacts.index', ['id_campagne' => $campagne->id]) }}"
            data-pesee-url="{{ route('livraison.pesee.show', $campagne) }}"
            data-packaging-url="{{ route('livraison.packaging.index', $campagne) }}"
            data-chargement-url="{{ route('livraison.chargement.index', $campagne) }}"
            data-tableau-de-bord-url="{{ route('livraison.tableau-de-bord.index') }}">
        </div>
    </div>
@endsection
