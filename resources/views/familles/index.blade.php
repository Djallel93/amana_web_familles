{{-- resources/views/familles/index.blade.php --}}
@extends('layouts.app')

@section('title', 'Dossiers — AMANA Familles')

@section('content')

    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Dossiers familles</h1>
            <p class="text-[13px] text-ink-muted mt-1">
                {{ $familles->total() }} dossier{{ $familles->total() !== 1 ? 's' : '' }}
                @if(request()->anyFilled(['etat_dossier','id_quartier','id_secteur','id_ville','zakat_el_fitr','sadaqa','se_deplace','langue','criticite_min','criticite_max','recherche']))
                    (filtré{{ $familles->total() !== 1 ? 's' : '' }})
                @endif
            </p>
        </div>
    </div>

    {{-- ── Barre de filtres ── --}}
    <form method="GET" action="{{ route('familles.index') }}"
        class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 mb-5">
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">

            <div class="col-span-2 sm:col-span-3 lg:col-span-2">
                <label class="block text-[10.5px] font-bold text-ink-muted uppercase tracking-wide mb-1">Recherche</label>
                <input type="text" name="recherche" value="{{ request('recherche') }}" placeholder="Nom, prénom, téléphone…"
                    class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface-2 outline-none
                            focus:border-accent focus:bg-surface focus:shadow-[0_0_0_3px_rgba(180,83,9,0.15)]">
            </div>

            <div>
                <label class="block text-[10.5px] font-bold text-ink-muted uppercase tracking-wide mb-1">Statut</label>
                <select name="etat_dossier" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface-2 outline-none focus:border-accent">
                    <option value="" {{ $etatDossier === '' ? 'selected' : '' }}>Tous</option>
                    @foreach(\App\Models\Famille::ETATS_MODIFIABLES as $etat)
                        <option value="{{ $etat }}" {{ $etatDossier === $etat ? 'selected' : '' }}>{{ $etat }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-[10.5px] font-bold text-ink-muted uppercase tracking-wide mb-1">Ville</label>
                <select name="id_ville" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface-2 outline-none focus:border-accent">
                    <option value="">Toutes</option>
                    @foreach($villes as $ville)
                        <option value="{{ $ville->id }}" {{ (string) request('id_ville') === (string) $ville->id ? 'selected' : '' }}>{{ $ville->nom }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-[10.5px] font-bold text-ink-muted uppercase tracking-wide mb-1">Quartier</label>
                <select name="id_quartier" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface-2 outline-none focus:border-accent">
                    <option value="">Tous</option>
                    @foreach($quartiers as $quartier)
                        <option value="{{ $quartier->id }}" {{ (string) request('id_quartier') === (string) $quartier->id ? 'selected' : '' }}>{{ $quartier->nom }}</option>
                    @endforeach
                </select>
                @if($quartiers->isEmpty())
                    <p class="text-[10.5px] text-ink-faint mt-1">Aucun quartier importé pour l'instant.</p>
                @endif
            </div>

            <div>
                <label class="block text-[10.5px] font-bold text-ink-muted uppercase tracking-wide mb-1">Langue</label>
                <select name="langue" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface-2 outline-none focus:border-accent">
                    <option value="">Toutes</option>
                    @foreach(\App\Models\Famille::LANGUES as $code => $label)
                        <option value="{{ $code }}" {{ request('langue') === $code ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-[10.5px] font-bold text-ink-muted uppercase tracking-wide mb-1">Criticité min.</label>
                <input type="number" name="criticite_min" min="0" max="5" value="{{ request('criticite_min') }}"
                    class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface-2 outline-none focus:border-accent">
            </div>

            <div>
                <label class="block text-[10.5px] font-bold text-ink-muted uppercase tracking-wide mb-1">Criticité max.</label>
                <input type="number" name="criticite_max" min="0" max="5" value="{{ request('criticite_max') }}"
                    class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface-2 outline-none focus:border-accent">
            </div>

            <div>
                <label class="block text-[10.5px] font-bold text-ink-muted uppercase tracking-wide mb-1">Se déplace</label>
                <select name="se_deplace" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface-2 outline-none focus:border-accent">
                    <option value="">Indifférent</option>
                    <option value="1" {{ request('se_deplace') === '1' ? 'selected' : '' }}>Oui</option>
                    <option value="0" {{ request('se_deplace') === '0' ? 'selected' : '' }}>Non</option>
                </select>
            </div>

            <div class="flex items-end gap-4 col-span-2 sm:col-span-3">
                <label class="flex items-center gap-1.5 text-[12.5px] text-ink-muted cursor-pointer select-none">
                    <input type="checkbox" name="zakat_el_fitr" value="1" {{ request()->boolean('zakat_el_fitr') ? 'checked' : '' }} class="w-4 h-4 accent-accent">
                    Zakat El Fitr
                </label>
                <label class="flex items-center gap-1.5 text-[12.5px] text-ink-muted cursor-pointer select-none">
                    <input type="checkbox" name="sadaqa" value="1" {{ request()->boolean('sadaqa') ? 'checked' : '' }} class="w-4 h-4 accent-accent">
                    Sadaqa
                </label>
            </div>

            <div class="flex items-end gap-2 col-span-2 sm:col-span-3 lg:col-span-1 lg:justify-self-end">
                <button type="submit"
                    class="flex-1 lg:flex-none px-4 py-2 bg-accent hover:bg-accent-dark text-white text-[12.5px] font-semibold rounded-md transition-colors min-h-[38px]">
                    Filtrer
                </button>
                <a href="{{ route('familles.index', ['etat_dossier' => '']) }}"
                    class="px-3 py-2 border border-surface-border bg-surface hover:bg-surface-2 text-ink-muted text-[12.5px] font-semibold rounded-md transition-colors no-underline min-h-[38px] flex items-center">
                    ✕
                </a>
            </div>
        </div>
    </form>

    {{-- ── Tableau compact ── --}}
    <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden">
        @if($familles->isEmpty())
            <div class="text-center py-16 px-8">
                <div class="text-5xl mb-3 opacity-40">🏠</div>
                <h3 class="font-heading text-base font-semibold text-ink mb-1.5">Aucun dossier</h3>
                <p class="text-ink-muted text-[13.5px]">
                    @if(request()->anyFilled(['recherche','etat_dossier','id_quartier']))
                        Aucun résultat pour ces filtres.
                    @else
                        Aucune famille enregistrée pour l'instant. L'import des dossiers existants est une étape à venir.
                    @endif
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            @foreach(['ID', 'Nom', 'Statut', 'Quartier', 'Criticité', 'Téléphone', 'Éligibilité'] as $col)
                                <th class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                                    {{ $col }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $etatColors = [
                                'Recu' => 'bg-stone-100 text-stone-700 border-stone-300',
                                'En cours' => 'bg-sky-50 text-sky-700 border-sky-200',
                                'En attente' => 'bg-amber-50 text-amber-700 border-amber-200',
                                'Validé' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                'Rejeté' => 'bg-rose-50 text-rose-700 border-rose-200',
                                'Archivé' => 'bg-gray-100 text-gray-500 border-gray-300',
                            ];
                        @endphp
                        @foreach($familles as $famille)
                            <tr onclick="openFamilleDetail({{ $famille->id }})"
                                class="border-b border-surface-3 last:border-0 hover:bg-surface-2 transition-colors cursor-pointer {{ $famille->probleme_traitement ? 'bg-rose-50/60' : '' }}">
                                <td class="px-4 py-2.5 text-ink-faint font-mono text-[12px]">#{{ $famille->id }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="font-semibold text-ink">{{ $famille->prenom }} {{ $famille->nom }}</div>
                                    <div class="text-[11.5px] text-ink-muted">{{ $famille->nombre_foyer }} pers.</div>
                                    @if($famille->probleme_traitement)
                                        <div class="text-[11px] text-rose-600 font-semibold mt-0.5">⚠️ {{ $famille->probleme_traitement }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $etatColors[$famille->etat_dossier] ?? '' }}">
                                        {{ $famille->etat_dossier }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-ink-muted">{{ $famille->quartier->nom ?? $famille->ville_texte ?? '—' }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="font-semibold {{ $famille->criticite >= 4 ? 'text-rose-600' : ($famille->criticite >= 2 ? 'text-amber-600' : 'text-ink-muted') }}">
                                        {{ $famille->criticite }}/5
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-ink-muted whitespace-nowrap">{{ $famille->telephone_formate }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="flex gap-1">
                                        @if($famille->zakat_el_fitr)
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-accent/10 text-accent-dark">ZF</span>
                                        @endif
                                        @if($famille->sadaqa)
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-700">SA</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3 border-t border-surface-3">
                {{ $familles->links() }}
            </div>
        @endif
    </div>

    {{-- Point de montage du panneau de détail/édition — voir
         resources/js/components/familles/DetailPanel.vue --}}
    <div id="vue-famille-detail"
         data-update-url-template="{{ route('familles.update', ['id' => '__ID__']) }}"
         data-show-url-template="{{ route('familles.show', ['id' => '__ID__']) }}"
         data-upload-url-template="{{ route('familles.documents.store', ['id' => '__ID__']) }}"
         data-download-url-template="{{ route('familles.documents.download', ['id' => '__ID__', 'documentId' => '__DOC__']) }}"
         data-delete-doc-url-template="{{ route('familles.documents.destroy', ['id' => '__ID__', 'documentId' => '__DOC__']) }}">
    </div>

@endsection
