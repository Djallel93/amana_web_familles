{{-- resources/views/livraison/choisir-poste.blade.php --}}
{{--
    Vue le prompt du 05/09/2026 §4.1 : equipe_reception/pesee/packaging/
    chargement n'avaient aucune entrée de menu vers leur propre écran
    (config/amana-shared.php ne listait que les écrans gestionnaire), et
    les routes existantes exigent un {campagne} que ces rôles n'avaient
    aucun moyen de choisir. Cette page — commune aux 4 rôles, paramétrée
    par $titre/$routeIndex/$avecJournee — est la cible du nouveau lien de
    sidebar pour chacun (voir config/amana-shared.php).
--}}
@extends('layouts.app')

@section('title', $titre . ' — AMANA Familles')

@section('content')
    <div class="max-w-md mx-auto py-10">
        <h1 class="font-heading text-xl font-semibold text-ink mb-6 text-center">{{ $titre }}</h1>

        @if($campagnes->isEmpty())
            <p class="text-[14px] text-ink-muted text-center">Aucune campagne en préparation ou en cours actuellement.</p>
        @else
            <div class="space-y-3">
                @foreach($campagnes as $campagne)
                    <div class="bg-surface border border-surface-border rounded-xl p-4">
                        <p class="text-[14px] font-medium text-ink">
                            Campagne {{ ucfirst($campagne->type) }} — {{ $campagne->date_livraison->format('d/m/Y') }}
                        </p>

                        @if($avecJournee && $campagne->journees->count() > 1)
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach($campagne->journees as $journee)
                                    <a href="{{ route($routeIndex, $campagne) }}?id_campagne_journee={{ $journee->id }}"
                                        class="text-[12px] px-3 py-1.5 rounded-lg border border-surface-border text-ink hover:bg-surface-hover">
                                        {{ $journee->label ?? $journee->date->format('d/m') }}
                                    </a>
                                @endforeach
                            </div>
                        @else
                            <a href="{{ route($routeIndex, $campagne) }}"
                                class="mt-3 inline-block text-[13px] px-4 py-2 rounded-lg bg-accent text-white hover:opacity-90 transition-opacity">
                                Ouvrir →
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection
