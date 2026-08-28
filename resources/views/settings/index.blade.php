{{-- resources/views/settings/index.blade.php --}}
{{--
Surcharge locale de amana-shared::settings.index (voir SettingsControllerBase
pour le schéma de surcharge) — ajoutée le 26/08/2026 pour intégrer la
section "Types de véhicule" à la même page que les réglages génériques,
plutôt qu'une page/entrée de menu séparée (voir VehiculeTypesController).
Le formulaire des réglages génériques est un copier-strict de la vue
partagée ; seule la section véhicules en dessous est propre à familles.
--}}
@extends('layouts.app')

@section('title', 'Paramètres')

@section('content')

    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Paramètres</h1>
            <p class="text-[13px] text-ink-muted mt-1">Réglages de l'application</p>
        </div>
    </div>

    <form action="{{ route('settings.update') }}" method="POST" class="max-w-2xl mb-8">
        @csrf

        <div class="bg-surface border border-surface-border rounded-lg divide-y divide-surface-border">
            @forelse($settings as $cle => $data)
                <div class="p-4 flex {{ $data['type'] === 'encrypted' ? 'flex-col' : 'items-center justify-between' }} gap-4">
                    <div class="min-w-0">
                        <label for="setting-{{ $cle }}"
                            class="block text-sm font-semibold text-ink">{{ $data['libelle'] }}</label>
                        @if($data['description'])
                            <p class="text-xs text-ink-muted mt-0.5">{{ $data['description'] }}</p>
                        @endif
                    </div>
                    <div class="flex-shrink-0 {{ $data['type'] === 'encrypted' ? 'w-full' : 'w-56' }}">
                        @if($data['type'] === 'boolean')
                            <select id="setting-{{ $cle }}" name="settings[{{ $cle }}]"
                                class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink">
                                <option value="1" @selected($data['valeur'])>Activé</option>
                                <option value="0" @selected(!$data['valeur'])>Désactivé</option>
                            </select>
                        @elseif($data['type'] === 'encrypted')
                            <textarea id="setting-{{ $cle }}" name="settings[{{ $cle }}]" rows="3"
                                class="w-full max-w-md px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-xs font-mono bg-surface-2 text-ink resize-y">{{ $data['valeur'] }}</textarea>
                        @else
                            <input type="text" id="setting-{{ $cle }}" name="settings[{{ $cle }}]" value="{{ $data['valeur'] }}"
                                class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink">
                        @endif
                    </div>
                </div>
            @empty
                <p class="p-4 text-sm text-ink-muted">Aucun paramètre configuré pour cette application.</p>
            @endforelse
        </div>

        <button type="submit"
            class="mt-5 px-5 py-2.5 bg-accent hover:bg-accent-dark text-white font-bold text-sm rounded-lg transition-colors cursor-pointer">
            Enregistrer
        </button>
    </form>

    <div class="max-w-2xl">
        <h2 class="font-heading text-lg font-semibold text-ink tracking-tight mb-1">Types de véhicule</h2>
        <p class="text-[13px] text-ink-muted mb-4">
            Capacité de charge et nombre de colis transportables par type de véhicule — utilisés par le
            formulaire de candidature bénévole et le futur moteur de répartition des livraisons.
            Le libellé de chaque type n'est pas modifiable ici.
        </p>

        <form action="{{ route('vehicules.update') }}" method="POST" class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden">
            @csrf
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            @foreach(['Type', 'Capacité (kg)', 'Nb. colis max'] as $col)
                                <th class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                                    {{ $col }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($vehicules as $vehicule)
                            <tr class="border-b border-surface-3 last:border-0">
                                <td class="px-4 py-2.5 text-ink font-semibold">{{ $vehicule->type }}</td>
                                <td class="px-4 py-2.5">
                                    <input type="number" step="0.01" min="0" name="vehicules[{{ $vehicule->id }}][capacite_kg]"
                                        value="{{ old('vehicules.' . $vehicule->id . '.capacite_kg', $vehicule->capacite_kg) }}"
                                        class="w-28 px-2.5 py-1.5 border border-surface-border rounded-md text-[13px] bg-surface text-ink">
                                </td>
                                <td class="px-4 py-2.5">
                                    <input type="number" step="1" min="0" name="vehicules[{{ $vehicule->id }}][nombre_part_max]"
                                        value="{{ old('vehicules.' . $vehicule->id . '.nombre_part_max', $vehicule->nombre_part_max) }}"
                                        class="w-24 px-2.5 py-1.5 border border-surface-border rounded-md text-[13px] bg-surface text-ink">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-surface-3">
                <button type="submit" class="px-4 py-2.5 bg-accent hover:bg-accent-dark text-white text-[13px] font-semibold rounded-lg transition-colors cursor-pointer min-h-[44px]">
                    Enregistrer
                </button>
            </div>
        </form>
    </div>

    <div class="max-w-2xl mt-10">
        <h2 class="font-heading text-lg font-semibold text-ink tracking-tight mb-1">Organisations partenaires</h2>
        <p class="text-[13px] text-ink-muted mb-4">
            Organisations pouvant enregistrer des familles dans un dossier commun avec AMANA — voir le rôle
            "Gestionnaire (organisation partenaire)" dans la gestion des personnes. Liste fermée : seules les
            organisations actives apparaissent dans les formulaires publics et les imports.
        </p>

        <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden mb-4">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        @foreach(['Code', 'Nom', 'Statut', ''] as $col)
                            <th class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                                {{ $col }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($organisations as $organisation)
                        <tr class="border-b border-surface-3 last:border-0">
                            <form action="{{ route('admin.organisations.update', $organisation->id) }}" method="POST" class="contents">
                                @csrf
                                @method('PUT')
                                <td class="px-4 py-2.5 text-ink-muted font-mono text-xs">
                                    {{ $organisation->code }}
                                    @if($organisation->est_principale)
                                        <span class="ml-1.5 px-1.5 py-0.5 rounded bg-accent/10 text-accent text-[10px] font-bold uppercase tracking-wide">Principale</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    <input type="text" name="nom" value="{{ old('nom', $organisation->nom) }}"
                                        class="w-full px-2.5 py-1.5 border border-surface-border rounded-md text-[13px] bg-surface text-ink">
                                </td>
                                <td class="px-4 py-2.5">
                                    <select name="actif" {{ $organisation->est_principale ? 'disabled' : '' }}
                                        class="px-2.5 py-1.5 border border-surface-border rounded-md text-[13px] bg-surface text-ink disabled:opacity-60">
                                        <option value="1" @selected($organisation->actif)>Active</option>
                                        <option value="0" @selected(!$organisation->actif)>Désactivée</option>
                                    </select>
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <button type="submit"
                                        class="px-3 py-1.5 bg-accent hover:bg-accent-dark text-white text-[12px] font-semibold rounded-md transition-colors cursor-pointer">
                                        Enregistrer
                                    </button>
                                </td>
                            </form>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form action="{{ route('admin.organisations.store') }}" method="POST"
            class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 flex items-end gap-3">
            @csrf
            <div class="flex-1">
                <label for="org-code" class="block text-xs font-bold text-ink mb-1.5">Code</label>
                <input type="text" id="org-code" name="code" value="{{ old('code') }}" required placeholder="ex : secours-machin"
                    class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink">
                @error('code')<span class="block text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="flex-1">
                <label for="org-nom" class="block text-xs font-bold text-ink mb-1.5">Nom</label>
                <input type="text" id="org-nom" name="nom" value="{{ old('nom') }}" required placeholder="ex : Secours Machin"
                    class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink">
                @error('nom')<span class="block text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
            </div>
            <button type="submit"
                class="px-5 py-2.5 bg-accent hover:bg-accent-dark text-white font-bold text-sm rounded-lg transition-colors cursor-pointer">
                + Ajouter
            </button>
        </form>
    </div>

@endsection
