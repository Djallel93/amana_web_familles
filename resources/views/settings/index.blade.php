{{-- resources/views/settings/index.blade.php --}}
{{--
Surcharge locale de amana-shared::settings.index (voir SettingsControllerBase
pour le schéma de surcharge) — ajoutée le 26/08/2026 pour intégrer la
section "Types de véhicule" à la même page que les réglages génériques,
plutôt qu'une page/entrée de menu séparée (voir VehiculeTypesController).
Le formulaire des réglages génériques réutilise settings._reglage_row pour
le rendu de chaque ligne ; les sections Véhicules/Organisations/Adresses
hôtel en dessous restent propres à familles.

Onglets ajoutés le 05/09/2026 (voir SettingsTabs.vue) : cinq sections
(Général, Itinéraires, Véhicules, Organisations, Adresses hôtel) marquées
data-settings-tab="..." — la page reste du Blade classique avec ses <form>
POST habituels, les onglets ne font que montrer/cacher ces blocs, aucune
conversion en SPA.
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

    {{-- Onglets ajoutés le 05/09/2026 (page trop chargée) — voir
         SettingsTabs.vue, qui montre/cache chaque bloc data-settings-tab
         ci-dessous. Onglet par défaut calculé ici plutôt que toujours
         "general" : si une soumission échoue la validation, on rouvre
         l'onglet où se trouve l'erreur au lieu de la cacher. --}}
    @php
        $defaultTab = 'general';
        if (collect($errors->keys())->contains(fn($cle) => str_starts_with($cle, 'settings.route_'))) {
            $defaultTab = 'itineraires';
        } elseif ($errors->has('code') || $errors->has('nom')) {
            $defaultTab = 'organisations';
        } elseif ($errors->has('adresse')) {
            $defaultTab = 'hotels';
        } elseif (collect($errors->keys())->contains(fn($cle) => str_starts_with($cle, 'vehicules.'))) {
            $defaultTab = 'vehicules';
        }
    @endphp
    <div id="vue-settings-tabs" data-default-tab="{{ $defaultTab }}"></div>

    <form action="{{ route('settings.update') }}" method="POST" class="max-w-2xl mb-8">
        @csrf

        <div data-settings-tab="general">
            <div class="bg-surface border border-surface-border rounded-lg divide-y divide-surface-border">
                @forelse($reglagesGeneraux as $cle => $data)
                    @include('settings._reglage_row', ['cle' => $cle, 'data' => $data])
                @empty
                    <p class="p-4 text-sm text-ink-muted">Aucun paramètre configuré pour cette application.</p>
                @endforelse
            </div>
        </div>

        <div data-settings-tab="itineraires">
            <div class="bg-surface border border-surface-border rounded-lg divide-y divide-surface-border">
                @forelse($reglagesItineraires as $cle => $data)
                    @include('settings._reglage_row', ['cle' => $cle, 'data' => $data])
                @empty
                    <p class="p-4 text-sm text-ink-muted">Aucun réglage d'itinéraire configuré.</p>
                @endforelse
            </div>

            <div class="mt-6">
                <h3 class="font-heading text-base font-semibold text-ink tracking-tight mb-1">HQ par défaut</h3>
                <p class="text-[13px] text-ink-muted mb-3">
                    Coordonnées du point de départ des tournées (local de l'association). Requises avant tout
                    clustering. Recherchez une adresse pour remplir automatiquement les coordonnées, ou
                    saisissez-les manuellement — seules les coordonnées sont conservées, pas l'adresse recherchée.
                </p>

                <div id="vue-hq-coordinates-autocomplete" class="mb-3"
                    data-google-places-key="{{ $googlePlacesKey }}"
                    data-target-lat-id="setting-route_hq_latitude"
                    data-target-lng-id="setting-route_hq_longitude"></div>

                <div class="flex gap-3 max-w-md">
                    <div class="flex-1">
                        <label for="setting-route_hq_latitude" class="block text-xs font-bold text-ink mb-1.5">Latitude</label>
                        <input type="number" step="any" id="setting-route_hq_latitude" name="settings[route_hq_latitude]"
                            value="{{ old('settings.route_hq_latitude', $settings['route_hq_latitude']['valeur']) }}"
                            readonly
                            class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink-muted">
                    </div>
                    <div class="flex-1">
                        <label for="setting-route_hq_longitude" class="block text-xs font-bold text-ink mb-1.5">Longitude</label>
                        <input type="number" step="any" id="setting-route_hq_longitude" name="settings[route_hq_longitude]"
                            value="{{ old('settings.route_hq_longitude', $settings['route_hq_longitude']['valeur']) }}"
                            readonly
                            class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink-muted">
                    </div>
                </div>
                @error('settings.route_hq_latitude')<span class="block text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
                @error('settings.route_hq_longitude')<span class="block text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
            </div>
        </div>

        <button type="submit"
            class="mt-5 px-5 py-2.5 bg-accent hover:bg-accent-dark text-white font-bold text-sm rounded-lg transition-colors cursor-pointer">
            Enregistrer
        </button>
    </form>

    <div class="max-w-2xl" data-settings-tab="vehicules">
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

    <div class="max-w-2xl mt-10" data-settings-tab="organisations">
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

    <div class="max-w-2xl mt-10" data-settings-tab="hotels">
        <h2 class="font-heading text-lg font-semibold text-ink tracking-tight mb-1">Adresses hôtel</h2>
        <p class="text-[13px] text-ink-muted mb-4">
            Adresses d'hébergement d'urgence connues (hôtels, appart-hôtels). Quand l'adresse d'une famille
            correspond à une entrée de cette liste, la case "hôtel" de son dossier est cochée automatiquement,
            même si la famille ne l'a pas cochée elle-même. Un même établissement peut avoir plusieurs adresses.
        </p>

        <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden mb-4">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        @foreach(['Adresse', ''] as $col)
                            <th class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                                {{ $col }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($hotelAddresses as $hotelAddress)
                        <tr class="border-b border-surface-3 last:border-0">
                            <form action="{{ route('hotel-addresses.update', $hotelAddress->id) }}" method="POST" class="contents">
                                @csrf
                                @method('PUT')
                                <td class="px-4 py-2.5">
                                    <input type="text" name="adresse" value="{{ old('adresse', $hotelAddress->adresse) }}" required
                                        class="w-full px-2.5 py-1.5 border border-surface-border rounded-md text-[13px] bg-surface text-ink">
                                </td>
                                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                    <button type="submit"
                                        class="px-3 py-1.5 bg-accent hover:bg-accent-dark text-white text-[12px] font-semibold rounded-md transition-colors cursor-pointer">
                                        Enregistrer
                                    </button>
                                </td>
                            </form>
                            <td class="px-1 py-2.5 text-right">
                                <form action="{{ route('hotel-addresses.destroy', $hotelAddress->id) }}" method="POST"
                                    data-confirm="Supprimer cette adresse hôtel ? Les dossiers déjà marqués « hôtel » ne seront pas modifiés."
                                    data-confirm-danger data-confirm-label="Supprimer">
                                    @csrf @method('DELETE')
                                    <button type="submit"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-rose-200 bg-rose-50 hover:bg-rose-100 text-sm transition-colors cursor-pointer min-h-[44px] min-w-[44px]"
                                        title="Supprimer">🗑️</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="px-4 py-4 text-ink-muted text-center">Aucune adresse enregistrée.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <form action="{{ route('hotel-addresses.store') }}" method="POST"
            class="bg-surface rounded-xl border border-surface-border shadow-sm p-4">
            @csrf
            <label for="hotel-address-adresse" class="block text-xs font-bold text-ink mb-1.5">Nouvelle adresse</label>
            <div class="flex items-end gap-3">
                <div class="flex-1">
                    <input type="text" id="hotel-address-adresse" name="adresse" value="{{ old('adresse') }}" required
                        placeholder="ex : 12 Rue de la Johardière, 44800 Saint-Herblain"
                        class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink">
                    @error('adresse')<span class="block text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
                    {{-- Widget d'appoint : bouton "Rechercher via Google Maps" qui
                         remplit le champ ci-dessus au lieu de le remplacer — voir
                         HotelAddressAutocomplete.vue. La saisie manuelle reste
                         toujours possible sans JS. --}}
                    <div id="vue-hotel-address-autocomplete" class="mt-1.5"
                        data-google-places-key="{{ $googlePlacesKey }}"
                        data-target-input-id="hotel-address-adresse"></div>
                </div>
                <button type="submit"
                    class="px-5 py-2.5 bg-accent hover:bg-accent-dark text-white font-bold text-sm rounded-lg transition-colors cursor-pointer">
                    + Ajouter
                </button>
            </div>
        </form>
    </div>

@endsection
