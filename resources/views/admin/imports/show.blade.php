{{-- resources/views/admin/imports/show.blade.php --}}
@extends('layouts.app')

@section('title', 'Détail import — AMANA Familles')

@section('content')

    <div class="mb-7">
        <a href="{{ route('admin.imports.index') }}" class="text-[13px] text-ink-muted hover:text-accent transition-colors no-underline">← Retour aux imports</a>
        <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight mt-2">
            Import du {{ $import->created_at?->format('d/m/Y à H:i') }}
        </h1>
        <p class="text-[13px] text-ink-muted mt-1">
            Source : {{ $import->source === 'csv' ? 'Fichier CSV' : 'Saisie manuelle' }} ·
            {{ $import->rows->count() }} ligne{{ $import->rows->count() !== 1 ? 's' : '' }}
        </p>
    </div>

    {{-- Résumé --}}
    <div class="grid grid-cols-3 gap-4 mb-6">
        @php
            $success = $import->rows->where('status', 'success')->count();
            $errors = $import->rows->where('status', 'error')->count();
            $skipped = $import->rows->where('status', 'skipped')->count();
        @endphp
        <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 text-center">
            <div class="text-2xl font-bold text-emerald-600">{{ $success }}</div>
            <div class="text-[11.5px] text-ink-muted uppercase tracking-wide font-semibold mt-1">Réussies</div>
        </div>
        <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 text-center">
            <div class="text-2xl font-bold {{ $errors > 0 ? 'text-rose-600' : 'text-ink-faint' }}">{{ $errors }}</div>
            <div class="text-[11.5px] text-ink-muted uppercase tracking-wide font-semibold mt-1">Erreurs</div>
        </div>
        <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 text-center">
            <div class="text-2xl font-bold text-ink-faint">{{ $skipped }}</div>
            <div class="text-[11.5px] text-ink-muted uppercase tracking-wide font-semibold mt-1">Ignorées</div>
        </div>
    </div>

    {{-- Détail ligne par ligne --}}
    <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-[12.5px]">
                <thead>
                    <tr>
                        @foreach(['#', 'Statut', 'Nom', 'Téléphone', 'Détail'] as $col)
                            <th class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                                {{ $col }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @php
                        $statusStyle = [
                            'success' => ['bg-emerald-50 text-emerald-700 border-emerald-200', '✅ Réussi'],
                            'error' => ['bg-rose-50 text-rose-700 border-rose-200', '❌ Erreur'],
                            'skipped' => ['bg-gray-100 text-gray-500 border-gray-300', '⏭️ Ignoré'],
                            'pending' => ['bg-amber-50 text-amber-700 border-amber-200', '⏳ En attente'],
                        ];
                    @endphp
                    @foreach($import->rows->sortBy('row_number') as $row)
                        <tr class="border-b border-surface-3 last:border-0">
                            <td class="px-4 py-2.5 text-ink-muted">{{ $row->row_number }}</td>
                            <td class="px-4 py-2.5">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $statusStyle[$row->status][0] ?? '' }}">
                                    {{ $statusStyle[$row->status][1] ?? $row->status }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-ink">{{ $row->payload['prenom'] ?? '' }} {{ $row->payload['nom'] ?? '' }}</td>
                            <td class="px-4 py-2.5 text-ink-muted">{{ $row->payload['telephone'] ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-rose-600">{{ $row->error_message }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

@endsection
