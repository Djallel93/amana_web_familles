{{-- resources/views/admin/imports/index.blade.php --}}
@extends('layouts.app')

@section('title', 'Imports — AMANA Familles')

@section('content')

    <div class="flex flex-wrap items-center justify-between gap-4 mb-7">
        <div>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Import / mise à jour en masse</h1>
            <p class="text-[13px] text-ink-muted mt-1">Ajout ou mise à jour de plusieurs dossiers via CSV ou saisie manuelle</p>
        </div>
        <a href="{{ route('admin.imports.create') }}"
            class="inline-flex items-center gap-2 px-4 py-2.5 bg-accent hover:bg-accent-dark text-white text-[13px] font-semibold rounded-lg
                        shadow-[0_3px_12px_rgba(180,83,9,0.3)] hover:-translate-y-px active:translate-y-0 transition-all no-underline min-h-[44px]">
            + Nouvel import
        </a>
    </div>

    <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden">
        @if($imports->isEmpty())
            <div class="text-center py-16 px-8">
                <div class="text-5xl mb-3 opacity-40">📥</div>
                <h3 class="font-heading text-base font-semibold text-ink mb-1.5">Aucun import pour l'instant</h3>
                <p class="text-ink-muted text-[13.5px] mb-6">Importez plusieurs dossiers d'un coup via un fichier CSV ou une saisie manuelle.</p>
                <a href="{{ route('admin.imports.create') }}"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-accent hover:bg-accent-dark text-white text-[13px] font-semibold rounded-lg transition-colors no-underline min-h-[44px]">
                    + Nouvel import
                </a>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            @foreach(['Date', 'Source', 'Lignes', 'Réussies', 'Erreurs', 'Ignorées', ''] as $col)
                                <th class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                                    {{ $col }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($imports as $import)
                            <tr class="border-b border-surface-3 last:border-0 hover:bg-surface-2 transition-colors">
                                <td class="px-4 py-2.5 text-ink">{{ $import->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold border
                                                 {{ $import->source === 'csv' ? 'bg-sky-50 text-sky-700 border-sky-200' : 'bg-stone-100 text-stone-700 border-stone-300' }}">
                                        {{ $import->source === 'csv' ? '📄 CSV' : '✍️ Manuel' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-ink-muted">{{ $import->rows_count }}</td>
                                <td class="px-4 py-2.5 text-emerald-600 font-semibold">{{ $import->rows_success_count }}</td>
                                <td class="px-4 py-2.5 {{ $import->rows_error_count > 0 ? 'text-rose-600 font-semibold' : 'text-ink-faint' }}">{{ $import->rows_error_count }}</td>
                                <td class="px-4 py-2.5 text-ink-faint">{{ $import->rows_skipped_count }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    <a href="{{ route('admin.imports.show', $import->id) }}"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-accent hover:bg-accent-dark text-white text-[12px] font-semibold rounded-lg
                                                    shadow-[0_2px_8px_rgba(180,83,9,0.25)] hover:-translate-y-px active:translate-y-0 transition-all no-underline">
                                        Détails →
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-surface-3">
                {{ $imports->links() }}
            </div>
        @endif
    </div>

@endsection
