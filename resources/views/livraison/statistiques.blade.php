{{-- resources/views/livraison/statistiques.blade.php --}}
@extends('layouts.app')

@section('title', 'Statistiques livraison — AMANA Familles')

@section('content')
    <div class="max-w-3xl mx-auto py-8">
        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Statistiques livraison</h1>

        {{-- Reconstruit en Vue le 03/09/2026 (voir LivraisonStatistiques.vue)
             — seule la partie interactive (sélecteur + stats en direct)
             est une île Vue ; le tableau de comparaison historique reste
             rendu par Blade ci-dessous : $historique est déjà chargé côté
             serveur et n'a aucune interactivité, pas de raison d'en faire
             un aller-retour JSON de plus (même logique que la liste
             campagnes déjà chargée sur campagnes.blade.php). --}}
        <div id="vue-livraison-statistiques" data-campagnes="{{ $campagnes->toJson() }}"
            data-donnees-url-template="{{ route('livraison.statistiques.donnees', ['campagne' => '__CAMPAGNE__']) }}"
            data-snapshot-url-template="{{ route('livraison.statistiques.snapshot', ['campagne' => '__CAMPAGNE__']) }}"
            data-peut-snapshotter="{{ auth()->user()->isAdmin() || auth()->user()->isGestionnaire() ? '1' : '0' }}">
        </div>

        <div class="bg-surface border border-surface-border rounded-xl p-5 mt-8">
            <h2 class="text-[14px] font-medium text-ink mb-4">Comparaison historique</h2>
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left text-ink-muted border-b border-surface-border">
                        <th class="py-2">Campagne</th>
                        <th>Ménages</th>
                        <th>Poids collecté</th>
                        <th>Taux livraison</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($historique as $snapshot)
                        <tr class="border-b border-surface-border">
                            <td class="py-2">{{ $snapshot->campagne->date_livraison->format('d/m/Y') }} — {{ $snapshot->campagne->type }}</td>
                            <td>{{ $snapshot->donnees['nombre_menages'] ?? '—' }}</td>
                            <td>{{ $snapshot->donnees['poids_collecte_kg'] ?? '—' }} kg</td>
                            <td>{{ isset($snapshot->donnees['taux_livraison']) ? round($snapshot->donnees['taux_livraison'] * 100) . '%' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-ink-muted">Aucun instantané enregistré pour le moment.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
