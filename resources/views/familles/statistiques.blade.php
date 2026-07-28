{{-- resources/views/familles/statistiques.blade.php --}}
@extends('layouts.app')

@section('title', 'Statistiques — AMANA Familles')

@section('content')

<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div>
        <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Statistiques</h1>
        <p class="text-[13px] text-ink-muted mt-1">
            Vue d'ensemble des dossiers familles — répartition, éligibilité, criticité, géographie
        </p>
    </div>
</div>

{{--
    Point de montage FamillesStatistiques.vue — cartes + graphiques
    (Chart.js). Données via GET /familles/statistiques/data
    (Admin\StatistiquesFamillesController::data).
--}}
<div id="vue-familles-statistiques"></div>

@endsection

@push('scripts')
<script>
    window.FamillesStatistiquesConfig = {
        csrf: document.querySelector('meta[name="csrf-token"]').content,
        routes: {
            data: '{{ route('familles.statistiques.data') }}',
        },
    };
</script>
@endpush
