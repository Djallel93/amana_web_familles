{{-- resources/views/admin/rattachements/index.blade.php --}}
@extends('layouts.app')

@section('title', 'Rattachements d\'organisation — AMANA Familles')

@section('content')

    <div class="flex flex-wrap items-center justify-between gap-4 mb-7">
        <div>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Rattachements d'organisation</h1>
            <p class="text-[13px] text-ink-muted mt-1">
                Une organisation partenaire a soumis/importé une famille déjà rattachée à une autre organisation —
                validez pour lui donner accès au dossier commun, ou rejetez si ce n'est pas la même famille.
            </p>
        </div>
    </div>

    <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden">
        @if($demandes->isEmpty())
            <div class="text-center py-16 px-8">
                <div class="text-5xl mb-3 opacity-40">📨</div>
                <h3 class="font-heading text-base font-semibold text-ink mb-1.5">Aucune demande en attente</h3>
                <p class="text-ink-muted text-[13.5px]">Aucun rattachement d'organisation ne nécessite de revue pour le moment.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            @foreach(['Dossier', 'Déjà rattaché à', 'Organisation demandeuse', 'Source', 'Soumis le', 'Actions'] as $col)
                                <th class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                                    {{ $col }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($demandes as $demande)
                            <tr class="border-b border-surface-3 last:border-0 hover:bg-surface-2 transition-colors">
                                <td class="px-4 py-2.5 text-ink align-top">
                                    <a href="{{ route('familles.show', $demande->id_famille) }}" class="text-accent hover:underline font-semibold" target="_blank">
                                        {{ $demande->famille?->prenom }} {{ $demande->famille?->nom }}
                                    </a>
                                    <div class="text-ink-muted text-[11.5px]">{{ $demande->famille?->telephone_formate }}</div>
                                </td>
                                <td class="px-4 py-2.5 text-ink-muted align-top">
                                    {{ $demande->famille?->organisations->pluck('nom')->join(', ') }}
                                </td>
                                <td class="px-4 py-2.5 text-ink font-semibold align-top">{{ $demande->organisation?->nom }}</td>
                                <td class="px-4 py-2.5 text-ink-muted align-top capitalize">{{ $demande->source }}</td>
                                <td class="px-4 py-2.5 text-ink-muted align-top">{{ $demande->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-2.5 align-top">
                                    <div class="flex items-center gap-2">
                                        <form action="{{ route('rattachements.valider', $demande->id) }}" method="POST">
                                            @csrf
                                            <button type="submit"
                                                class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-semibold rounded-md transition-colors cursor-pointer">
                                                ✅ Valider
                                            </button>
                                        </form>
                                        <form action="{{ route('rattachements.rejeter', $demande->id) }}" method="POST"
                                            onsubmit="return confirm('Rejeter cette demande de rattachement ?');">
                                            @csrf
                                            <button type="submit"
                                                class="px-3 py-1.5 border border-surface-border bg-surface hover:bg-surface-2 text-ink text-[12px] font-semibold rounded-md transition-colors cursor-pointer">
                                                ❌ Rejeter
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-surface-3">
                {{ $demandes->links() }}
            </div>
        @endif
    </div>

@endsection
