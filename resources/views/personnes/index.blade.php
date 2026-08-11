{{-- resources/views/personnes/index.blade.php --}}
@extends('layouts.app')

@section('title', 'Personnes — AMANA Familles')

@section('content')

    {{-- En-tête --}}
    <div class="flex flex-wrap items-center justify-between gap-4 mb-7">
        <div>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Membre Pole sociale</h1>
            <p class="text-[13px] text-ink-muted mt-1">Administrateurs, gestionnaires, membres et bénévoles ayant accès à cette app</p>
        </div>
        <a href="{{ route('admin.personnes.create') }}"
            class="inline-flex items-center gap-2 px-4 py-2.5 bg-accent hover:bg-accent-dark text-white text-[13px] font-semibold rounded-lg
                        shadow-[0_3px_12px_rgba(180,83,9,0.3)] hover:-translate-y-px active:translate-y-0 transition-all no-underline min-h-[44px]">
            + Ajouter
        </a>
    </div>

    {{-- Card tableau --}}
    <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden">

        {{-- Header card --}}
        <div class="flex items-center gap-2.5 px-5 py-4 border-b border-surface-3">
            <div class="w-7 h-7 bg-amber-50 rounded-md flex items-center justify-center text-sm flex-shrink-0">👥</div>
            <span class="font-heading text-[14px] font-semibold text-ink">
                {{ $personnes->count() }} personne{{ $personnes->count() !== 1 ? 's' : '' }}
            </span>
        </div>

        @if($personnes->isEmpty())
            <div class="text-center py-16 px-8">
                <div class="text-5xl mb-3 opacity-40">👥</div>
                <h3 class="font-heading text-base font-semibold text-ink mb-1.5">Aucune personne enregistrée</h3>
                <p class="text-ink-muted text-[13.5px] mb-6">Ajoutez un membre du staff pour lui donner accès à AMANA Familles.</p>
                <a href="{{ route('admin.personnes.create') }}"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-accent hover:bg-accent-dark text-white text-[13px] font-semibold rounded-lg transition-colors no-underline min-h-[44px]">
                    + Ajouter une personne
                </a>
            </div>

        @else
            @php
                $roleMap = [
                    'admin' => ['label' => 'Admin', 'bg' => 'bg-orange-50', 'text' => 'text-orange-700', 'border' => 'border-orange-200', 'icon' => '🛡️'],
                    'gestionnaire' => ['label' => 'Gestionnaire', 'bg' => 'bg-amber-50', 'text' => 'text-amber-700', 'border' => 'border-amber-200', 'icon' => '⚙️'],
                    'membre' => ['label' => 'Membre', 'bg' => 'bg-stone-100', 'text' => 'text-stone-700', 'border' => 'border-stone-300', 'icon' => '👤'],
                    'benevole' => ['label' => 'Bénévole', 'bg' => 'bg-yellow-50', 'text' => 'text-yellow-700', 'border' => 'border-yellow-200', 'icon' => '🤝'],
                ];
            @endphp

            {{-- Table desktop (≥ md) --}}
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full border-collapse text-[13.5px]">
                    <thead>
                        <tr>
                            @foreach(['Nom', 'Rôle', 'Email', 'Téléphone', 'Actions'] as $col)
                                <th class="text-left px-5 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.7px]
                                            bg-surface-2 border-b border-surface-3 font-body whitespace-nowrap
                                            {{ $col === 'Actions' ? 'text-right' : '' }}">
                                    {{ $col }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($personnes as $personne)
                            @php
                                $roleFamilles = $personne->roles->first();
                                $roleInfo = $roleFamilles ? ($roleMap[$roleFamilles->code] ?? null) : null;
                            @endphp
                            <tr class="border-b border-surface-3 last:border-0 hover:bg-surface-2 transition-colors">
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-[30px] h-[30px] bg-accent rounded-full flex items-center justify-center text-white text-[11px] font-bold flex-shrink-0">
                                            {{ strtoupper(substr($personne->prenom, 0, 1)) }}
                                        </div>
                                        <span class="font-semibold text-ink">{{ $personne->prenom }} {{ $personne->nom }}</span>
                                    </div>
                                </td>
                                <td class="px-5 py-3">
                                    @if($roleInfo)
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11.5px] font-semibold border
                                                     {{ $roleInfo['bg'] }} {{ $roleInfo['text'] }} {{ $roleInfo['border'] }}">
                                            {{ $roleInfo['icon'] }} {{ $roleInfo['label'] }}
                                        </span>
                                    @else
                                        <span class="text-ink-faint text-xs italic">Aucun rôle</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-ink-muted text-[12.5px]">{{ $personne->email }}</td>
                                <td class="px-5 py-3 text-ink-muted text-[12.5px]">{{ $personne->telephone ?? '—' }}</td>
                                <td class="px-5 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('admin.personnes.edit', $personne->id) }}"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-surface-border bg-surface hover:bg-surface-2 text-sm transition-colors no-underline min-h-[44px] min-w-[44px]"
                                            title="Modifier">✏️</a>
                                        <form action="{{ route('admin.personnes.destroy', $personne->id) }}" method="POST"
                                            data-confirm="Révoquer l'accès Familles de {{ $personne->prenom }} {{ $personne->nom }} ? Son compte AMANA (autres apps) n'est pas supprimé."
                                            data-confirm-danger data-confirm-label="Révoquer l'accès">
                                            @csrf @method('DELETE')
                                            <button type="submit"
                                                class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-rose-200 bg-rose-50 hover:bg-rose-100 text-sm transition-colors cursor-pointer min-h-[44px] min-w-[44px]"
                                                title="Révoquer l'accès">🗑️</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Cartes mobile (< md) --}}
            <div class="md:hidden divide-y divide-surface-3">
                @foreach($personnes as $personne)
                    @php
                        $roleFamilles = $personne->roles->first();
                        $roleInfo = $roleFamilles ? ($roleMap[$roleFamilles->code] ?? null) : null;
                    @endphp
                    <div class="px-4 py-3.5">
                        <div class="flex items-center justify-between mb-2.5">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 bg-accent rounded-full flex items-center justify-center text-white text-[12px] font-bold flex-shrink-0">
                                    {{ strtoupper(substr($personne->prenom, 0, 1)) }}
                                </div>
                                <div>
                                    <div class="font-semibold text-[13.5px] text-ink">{{ $personne->prenom }} {{ $personne->nom }}</div>
                                    <div class="text-[12px] text-ink-muted mt-px">{{ $personne->email }}</div>
                                </div>
                            </div>
                            <div class="flex items-center gap-1.5 ml-2 flex-shrink-0">
                                <a href="{{ route('admin.personnes.edit', $personne->id) }}"
                                    class="inline-flex items-center justify-center w-9 h-9 rounded-md border border-surface-border bg-surface hover:bg-surface-2 text-sm transition-colors no-underline min-h-[44px] min-w-[44px]"
                                    title="Modifier">✏️</a>
                                <form action="{{ route('admin.personnes.destroy', $personne->id) }}" method="POST"
                                    data-confirm="Révoquer l'accès Familles de {{ $personne->prenom }} {{ $personne->nom }} ?"
                                    data-confirm-danger data-confirm-label="Révoquer l'accès">
                                    @csrf @method('DELETE')
                                    <button type="submit"
                                        class="inline-flex items-center justify-center w-9 h-9 rounded-md border border-rose-200 bg-rose-50 hover:bg-rose-100 text-sm transition-colors cursor-pointer min-h-[44px] min-w-[44px]"
                                        title="Révoquer l'accès">🗑️</button>
                                </form>
                            </div>
                        </div>
                        @if($roleInfo)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $roleInfo['bg'] }} {{ $roleInfo['text'] }} {{ $roleInfo['border'] }}">
                                {{ $roleInfo['icon'] }} {{ $roleInfo['label'] }}
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

@endsection
