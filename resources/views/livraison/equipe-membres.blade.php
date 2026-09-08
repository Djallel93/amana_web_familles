{{-- resources/views/livraison/equipe-membres.blade.php --}}
{{--
    Voir le prompt du 07/09/2026 (§1) et App\Http\Controllers\Admin\
    Livraison\EquipeMembresController — même architecture que
    benevole-disponibilite.blade.php (écran dédié lié depuis
    CampagneDetail.vue, pas un onglet).
--}}
@extends('layouts.app')

@section('title', 'Équipes — AMANA Familles')

@section('content')
    <div class="max-w-4xl mx-auto py-8">
        <a href="{{ route('livraison.campagnes.show', $campagne) }}"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
            ← Retour à la campagne
        </a>

        <div id="vue-livraison-equipe-membres"
            data-campagne="{{ $campagne->toJson() }}"
            data-liste-url="{{ route('livraison.campagnes.equipes.liste', $campagne) }}"
            data-ajouter-url="{{ route('livraison.campagnes.equipes.ajouter', $campagne) }}"
            data-retirer-url-template="{{ route('livraison.campagnes.equipes.retirer', [$campagne, '__ID__', '__ROLE__']) }}">
        </div>
    </div>
@endsection
