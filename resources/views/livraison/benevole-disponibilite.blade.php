{{-- resources/views/livraison/benevole-disponibilite.blade.php --}}
@extends('layouts.app')

@section('title', 'Suivi des bénévoles — AMANA Familles')

@section('content')
    <div class="max-w-4xl mx-auto py-8">
        <a href="{{ route('livraison.campagnes.show', $campagne) }}"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
            ← Retour à la campagne
        </a>

        <div id="vue-livraison-benevole-disponibilite"
            data-campagne="{{ $campagne->toJson() }}"
            data-queue-url="{{ route('livraison.campagnes.benevoles.queue', $campagne) }}"
            data-mettre-a-jour-url-template="{{ route('livraison.campagnes.benevoles.mettre-a-jour', [$campagne, '__ID__']) }}"
            data-notifier-benevoles-url="{{ route('livraison.campagnes.notifier-benevoles', $campagne) }}"
            {{-- "Modifier informations" (07/09/2026, prompt §5.1) : lien
                 direct vers la fiche personne (véhicule/couverture réels
                 vivent sur BenevoleProfil, édités là-bas — voir
                 resources/views/personnes/form.blade.php — pas dupliqués
                 ici). --}}
            data-personne-edit-url-template="{{ route('admin.personnes.edit', '__ID__') }}">
        </div>
    </div>
@endsection
