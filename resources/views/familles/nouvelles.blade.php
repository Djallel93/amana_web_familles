{{-- resources/views/familles/nouvelles.blade.php --}}
{{--
    File d'attente des dossiers pas encore ouverts par le staff
    (etat_dossier = 'Recu', réservé aux soumissions du formulaire public
    d'intake — voir Famille::ETATS_MODIFIABLES et
    FamillesController::nouvelles()). Tri par ancienneté (le plus vieux
    d'abord) plutôt que par criticité comme la liste générale, pour
    qu'aucune demande ne reste oubliée. Réutilise le même panneau de
    détail/édition que familles/index.blade.php (DetailPanel.vue) — ouvrir
    un dossier ici fonctionne exactement pareil.
--}}
@extends('layouts.app')

@section('title', 'Nouvelles demandes — AMANA Familles')

@section('content')

    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Nouvelles demandes</h1>
            <p class="text-[13px] text-ink-muted mt-1">
                {{ $familles->total() }} demande{{ $familles->total() !== 1 ? 's' : '' }} pas encore ouverte{{ $familles->total() !== 1 ? 's' : '' }},
                triées de la plus ancienne à la plus récente
            </p>
        </div>
    </div>

    <form method="GET" action="{{ route('familles.nouvelles') }}"
        class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 mb-5">
        {{-- Voir index.blade.php pour le même correctif du 12/08/2026. --}}
        <input type="hidden" name="per_page" value="{{ request('per_page', \App\Models\Famille::PAGINATION_PAR_PAGE_DEFAUT) }}">
        <div class="flex gap-3">
            <input type="text" name="recherche" value="{{ request('recherche') }}" placeholder="Nom, prénom, téléphone…"
                class="flex-1 px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface-2 outline-none
                        focus:border-accent focus:bg-surface focus:shadow-[0_0_0_3px_rgba(180,83,9,0.15)]">
            <button type="submit"
                class="px-4 py-2 bg-accent hover:bg-accent-dark text-white text-[12.5px] font-semibold rounded-md transition-colors min-h-[38px]">
                Filtrer
            </button>
        </div>
    </form>

    <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden">
        @if($familles->isEmpty())
            <div class="text-center py-16 px-8">
                <div class="text-5xl mb-3 opacity-40">📭</div>
                <h3 class="font-heading text-base font-semibold text-ink mb-1.5">Aucune nouvelle demande</h3>
                <p class="text-ink-muted text-[13.5px]">
                    @if(request()->filled('recherche'))
                        Aucun résultat pour cette recherche.
                    @else
                        Tout est à jour — aucune soumission en attente d'ouverture.
                    @endif
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            @foreach(['ID', 'Nom', 'Reçue le', 'Ville', 'Téléphone', 'Problème'] as $col)
                                <th class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                                    {{ $col }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($familles as $famille)
                            <tr onclick="openFamilleDetail({{ $famille->id }})"
                                class="border-b border-surface-3 last:border-0 hover:bg-surface-2 transition-colors cursor-pointer {{ $famille->probleme_traitement ? 'bg-rose-50/60' : '' }}">
                                <td class="px-4 py-2.5 text-ink-faint font-mono text-[12px]">#{{ $famille->id }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="font-semibold text-ink">{{ $famille->prenom }} {{ $famille->nom }}</div>
                                    <div class="text-[11.5px] text-ink-muted">{{ $famille->nombre_foyer }} pers.</div>
                                </td>
                                <td class="px-4 py-2.5 text-ink-muted whitespace-nowrap">{{ $famille->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-2.5 text-ink-muted">{{ $famille->quartier->nom ?? $famille->ville_texte ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-ink-muted whitespace-nowrap">{{ $famille->telephone_formate }}</td>
                                <td class="px-4 py-2.5">
                                    @if($famille->probleme_traitement)
                                        <span class="inline-flex items-center gap-1 text-[11.5px] font-semibold text-rose-600">
                                            ⚠️ {{ $famille->probleme_traitement }}
                                        </span>
                                    @else
                                        <span class="text-ink-faint text-[11.5px]">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3 border-t border-surface-3">
                @include('partials.pagination', ['paginator' => $familles])
            </div>
        @endif
    </div>

    {{-- Même panneau de détail/édition que familles/index.blade.php — voir
         resources/js/components/familles/DetailPanel.vue --}}
    <div id="vue-famille-detail"
         data-update-url-template="{{ route('familles.update', ['id' => '__ID__']) }}"
         data-show-url-template="{{ route('familles.show', ['id' => '__ID__']) }}"
         data-upload-url-template="{{ route('familles.documents.store', ['id' => '__ID__']) }}"
         data-download-url-template="{{ route('familles.documents.download', ['id' => '__ID__', 'documentId' => '__DOC__']) }}"
         data-delete-doc-url-template="{{ route('familles.documents.destroy', ['id' => '__ID__', 'documentId' => '__DOC__']) }}"
         data-secteurs-activite="{{ $secteursActivite->toJson() }}"
         data-organismes-aide="{{ $organismesAide->toJson() }}"
         data-google-places-key="{{ config('services.google.maps.places_api_key') }}"
         data-google-embed-key="{{ config('services.google.maps.embed_api_key') }}">
    </div>

@endsection
