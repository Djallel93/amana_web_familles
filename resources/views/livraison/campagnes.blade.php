{{-- resources/views/livraison/campagnes.blade.php --}}
@extends('layouts.app')

@section('title', 'Campagnes — AMANA Familles')

@section('content')
    <div class="max-w-3xl mx-auto py-8">
        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Campagnes</h1>

        {{-- Reconstruit en Vue le 03/09/2026 (voir CampagnesIndex.vue) —
             la Blade ne fait plus que passer la liste déjà chargée
             (évite un aller-retour JSON en plus du rendu serveur pour une
             liste que le contrôleur a de toute façon déjà récupérée) et
             l'URL de création, comme le reste des écrans de l'app (voir
             DetailPanel.vue, ImportManualGrid.vue). --}}
        <div id="vue-livraison-campagnes-index"
            data-campagnes="{{ $campagnes->toJson() }}"
            data-store-url="{{ route('livraison.campagnes.store') }}"
            data-livraisons-max-par-tournee-defaut="{{ $livraisonsMaxParTourneeDefaut }}"
            data-resume-suppression-url-template="{{ route('livraison.campagnes.resume-suppression', ['campagne' => '__CAMPAGNE__']) }}"
            data-destroy-url-template="{{ route('livraison.campagnes.destroy', ['campagne' => '__CAMPAGNE__']) }}">
        </div>
    </div>
@endsection
