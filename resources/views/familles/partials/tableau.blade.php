{{-- resources/views/familles/partials/tableau.blade.php --}}
{{--
    Tableau desktop + carte mobile + sélecteur de colonnes, partagés entre
    familles/index.blade.php et familles/nouvelles.blade.php depuis le
    10/09/2026 (Section A2 du refactor, décision du 10/09/2026 : "same
    everywhere" plutôt que deux tableaux visuellement différents). Colonnes/
    couleurs viennent de App\Models\Famille (COLONNES_TABLEAU/ETAT_COLORS/
    ETAT_COLORS_LISTERE/TYPE_PIECE_IDENTITE_LABELS) — seule config vraiment
    partagée, donc lue directement ici plutôt que passée en variable par
    chaque appelant.

    Variables attendues :
    - $familles          : LengthAwarePaginator (déjà trié/filtré/
                            withQueryString() par le contrôleur)
    - $triActuel          : string|null — colonne de tri active (request('tri'))
    - $directionActuelle  : 'asc'|'desc'
    - $routeTri           : nom de route (sans paramètres) utilisé pour les
                             liens d'en-tête triables — familles.index ou
                             familles.nouvelles
    - $videIcone          : emoji affiché dans l'état vide
    - $videTitre          : titre affiché dans l'état vide
    - $aFiltresActifs     : bool — bascule entre $videMessageBase et
                             $videMessageFiltre
    - $videMessageBase    : texte affiché quand $aFiltresActifs est false
    - $videMessageFiltre  : texte affiché quand $aFiltresActifs est true,
                             avant l'éventuel lien de réinitialisation
    - $videLienReinitialisation : ['texte' => string, 'href' => string]|null
                             — lien affiché après $videMessageFiltre
                             (uniquement index.blade.php ; null sur
                             nouvelles.blade.php, qui n'a pas de filtres à
                             réinitialiser)
--}}
@php
    $colonnes = \App\Models\Famille::COLONNES_TABLEAU;
    $typePieceIdentiteLabels = \App\Models\Famille::TYPE_PIECE_IDENTITE_LABELS;
    $etatColorsListere = \App\Models\Famille::ETAT_COLORS_LISTERE;
@endphp

{{-- ── Sélecteur de colonnes — pas de persistance (toujours réinitialisé au
     rechargement, décision du 12/08/2026), pur JS/CSS via [hidden] sur les
     <th>/<td> correspondants (data-col). Masqué sous md (04/09/2026) : il
     n'a plus de sens une fois la carte mobile en place juste en dessous,
     qui n'affiche que les colonnes 'defaut' et ne lit jamais ces [hidden]. ── --}}
<div class="hidden md:flex justify-end mb-2">
    <details class="relative">
        <summary class="cursor-pointer list-none px-3 py-1.5 border border-surface-border bg-surface hover:bg-surface-2 text-ink-muted text-[12px] font-semibold rounded-md inline-flex items-center gap-1 select-none transition-colors">
            Colonnes <span class="text-[10px]">▾</span>
        </summary>
        <div class="absolute right-0 mt-1 z-20 bg-surface border border-surface-border rounded-md shadow-lg p-1.5 w-48 max-h-80 overflow-y-auto flash-enter">
            @foreach($colonnes as $cle => $colonne)
                <label class="flex items-center gap-2 text-[12px] text-ink-muted px-2 py-1.5 hover:bg-surface-2 rounded cursor-pointer select-none">
                    <input type="checkbox" class="famille-col-toggle w-3.5 h-3.5 accent-accent" data-col-toggle="{{ $cle }}" {{ $colonne['defaut'] ? 'checked' : '' }}>
                    {{ $colonne['label'] }}
                </label>
            @endforeach
        </div>
    </details>
</div>

<div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden">
    @if($familles->isEmpty())
        <div class="text-center py-16 px-8">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-accent/10 flex items-center justify-center text-3xl">{{ $videIcone }}</div>
            <h3 class="font-heading text-base font-semibold text-ink mb-1.5">{{ $videTitre }}</h3>
            <p class="text-ink-muted text-[13.5px] max-w-sm mx-auto">
                @if($aFiltresActifs)
                    {{ $videMessageFiltre }}
                    @if($videLienReinitialisation)
                        <a href="{{ $videLienReinitialisation['href'] }}" class="text-accent hover:underline font-semibold">{{ $videLienReinitialisation['texte'] }}</a>.
                    @endif
                @else
                    {{ $videMessageBase }}
                @endif
            </p>
        </div>
    @else
        {{-- Tableau desktop (≥ md) — carte mobile juste après ce bloc pour
             les écrans < md, même convention que personnes/index.blade.php
             et PlanningGrid.vue (voir amana_shared/docs/mobile-patterns.md). --}}
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        @foreach($colonnes as $cle => $colonne)
                            <th data-col="{{ $cle }}" {{ $colonne['defaut'] ? '' : 'hidden' }}
                                class="sticky top-topbar sm:top-0 z-10 text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                                @if($colonne['triable'])
                                    @php
                                        $prochaineDirection = ($triActuel === $cle && $directionActuelle === 'asc') ? 'desc' : 'asc';
                                    @endphp
                                    <a href="{{ route($routeTri, array_merge(request()->except(['tri', 'direction', 'page']), ['tri' => $cle, 'direction' => $prochaineDirection])) }}"
                                        class="inline-flex items-center gap-1 text-ink-muted hover:text-ink no-underline">
                                        {{ $colonne['label'] }}
                                        @if($triActuel === $cle)
                                            <span class="text-accent normal-case">{{ $directionActuelle === 'asc' ? '↑' : '↓' }}</span>
                                        @endif
                                    </a>
                                @else
                                    {{ $colonne['label'] }}
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($familles as $famille)
                        <tr onclick="openFamilleDetail({{ $famille->id }})"
                            class="border-b border-surface-3 last:border-b-0 border-l-4 {{ $etatColorsListere[$famille->etat_dossier] ?? 'border-l-gray-300' }} hover:bg-surface-2 active:bg-surface-3 transition-colors duration-150 cursor-pointer {{ $famille->probleme_traitement ? 'bg-rose-50/60' : '' }}">
                            <td data-col="id" class="px-4 py-2.5 text-ink-faint font-mono text-[12px]">#{{ $famille->id }}</td>
                            <td data-col="nom" class="px-4 py-2.5">
                                <div class="flex items-center gap-2.5">
                                    @include('familles.partials.avatar', ['famille' => $famille, 'taille' => 'normal'])
                                    <div class="min-w-0">
                                        <div class="font-semibold text-ink">{{ $famille->prenom }} {{ $famille->nom }}</div>
                                        <div class="text-[11.5px] text-ink-muted">{{ $famille->nombre_foyer }} pers.</div>
                                        @if($famille->probleme_traitement)
                                            <div class="text-[11px] text-rose-600 font-semibold mt-0.5">⚠️ {{ $famille->probleme_traitement }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td data-col="statut" class="px-4 py-2.5">
                                @include('familles.partials.badge-statut', ['famille' => $famille])
                            </td>
                            <td data-col="email" {{ $colonnes['email']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted">{{ $famille->email ?? '—' }}</td>
                            <td data-col="telephone" class="px-4 py-2.5 text-ink-muted whitespace-nowrap">{{ $famille->telephone_formate }}</td>
                            <td data-col="telephone_bis" {{ $colonnes['telephone_bis']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted whitespace-nowrap">{{ $famille->telephone_bis_formate ?? '—' }}</td>
                            <td data-col="adresse" class="px-4 py-2.5 text-ink-muted">{{ $famille->adresse_complete }}</td>
                            <td data-col="quartier" {{ $colonnes['quartier']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted">{{ $famille->quartier->nom ?? '—' }}</td>
                            <td data-col="ville" {{ $colonnes['ville']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted">{{ $famille->ville ?? '—' }}</td>
                            <td data-col="organisation" {{ $colonnes['organisation']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted">
                                {{ $famille->organisationOrigine->nom ?? '—' }}
                                @foreach($famille->organisations as $organisationRattachee)
                                    @if(!$famille->organisationOrigine || $organisationRattachee->id !== $famille->organisationOrigine->id)
                                        <span class="inline-flex px-1.5 py-0.5 ml-1 rounded-full bg-accent/10 text-accent-dark text-[10px] font-semibold">{{ $organisationRattachee->nom }}</span>
                                    @endif
                                @endforeach
                            </td>
                            <td data-col="nombre_adulte" {{ $colonnes['nombre_adulte']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted text-center">{{ $famille->nombre_adulte }}</td>
                            <td data-col="nombre_enfant" {{ $colonnes['nombre_enfant']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted text-center">{{ $famille->nombre_enfant }}</td>
                            <td data-col="criticite" class="px-4 py-2.5">
                                @include('familles.partials.criticite', ['famille' => $famille])
                            </td>
                            <td data-col="eligibilite" class="px-4 py-2.5">
                                @include('familles.partials.chips-eligibilite', ['famille' => $famille])
                            </td>
                            <td data-col="se_deplace" {{ $colonnes['se_deplace']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted">{{ $famille->se_deplace ? 'Oui' : 'Non' }}</td>
                            <td data-col="est_hotel" {{ $colonnes['est_hotel']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted">{{ $famille->est_hotel ? 'Oui' : 'Non' }}</td>
                            <td data-col="etudiant" {{ $colonnes['etudiant']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted">{{ $famille->etudiant ? 'Oui' : 'Non' }}</td>
                            <td data-col="langue" {{ $colonnes['langue']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted">{{ \App\Models\Famille::LANGUES[$famille->langue] ?? $famille->langue }}</td>
                            <td data-col="type_piece_identite" {{ $colonnes['type_piece_identite']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted">{{ $typePieceIdentiteLabels[$famille->type_piece_identite] ?? ($famille->type_piece_identite ?? '—') }}</td>
                            <td data-col="circonstances" {{ $colonnes['circonstances']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted max-w-[220px] truncate" title="{{ $famille->circonstances }}">{{ $famille->circonstances ?? '—' }}</td>
                            <td data-col="ressentit" {{ $colonnes['ressentit']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted max-w-[220px] truncate" title="{{ $famille->ressentit }}">{{ $famille->ressentit ?? '—' }}</td>
                            <td data-col="specificites" {{ $colonnes['specificites']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted max-w-[220px] truncate" title="{{ $famille->specificites }}">{{ $famille->specificites ?? '—' }}</td>
                            <td data-col="commentaire_dossier" {{ $colonnes['commentaire_dossier']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted max-w-[220px] truncate" title="{{ $famille->commentaire_dossier }}">{{ $famille->commentaire_dossier ?? '—' }}</td>
                            <td data-col="created_at" {{ $colonnes['created_at']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted whitespace-nowrap">{{ $famille->created_at?->format('d/m/Y') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Cartes mobile (< md) — mêmes données que le tableau ci-dessus
             mais uniquement les colonnes 'defaut' (le sélecteur "Colonnes"
             est masqué sous md — pas de sens de basculer des colonnes sur
             une carte), anatomie standard (voir amana_shared/docs/
             mobile-patterns.md) : avatar + ligne d'identité + badge de
             statut en dessous + détails en grid-cols-1 sm:grid-cols-2.
             Même interaction tap-pour-ouvrir que la ligne du tableau
             (onclick openFamilleDetail), pas de boutons d'action dédiés ici. --}}
        <div class="md:hidden divide-y divide-surface-3">
            @foreach($familles as $famille)
                <div onclick="openFamilleDetail({{ $famille->id }})"
                    class="px-4 py-3.5 border-l-4 {{ $etatColorsListere[$famille->etat_dossier] ?? 'border-l-gray-300' }} active:bg-surface-2 transition-colors duration-150 cursor-pointer {{ $famille->probleme_traitement ? 'bg-rose-50/60' : '' }}">
                    <div class="flex items-start justify-between gap-2 mb-2">
                        <div class="flex items-center gap-2.5 min-w-0">
                            @include('familles.partials.avatar', ['famille' => $famille, 'taille' => 'grande'])
                            <div class="min-w-0">
                                <div class="font-semibold text-[13.5px] text-ink truncate">{{ $famille->prenom }} {{ $famille->nom }}</div>
                                <div class="text-[11.5px] text-ink-muted">#{{ $famille->id }} · {{ $famille->nombre_foyer }} pers.</div>
                            </div>
                        </div>
                        @include('familles.partials.badge-statut', ['famille' => $famille])
                    </div>
                    @if($famille->probleme_traitement)
                        <div class="text-[11px] text-rose-600 font-semibold mb-2">⚠️ {{ $famille->probleme_traitement }}</div>
                    @endif
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-3 gap-y-1 text-[12px] text-ink-muted mb-2.5">
                        <div class="truncate">📞 {{ $famille->telephone_formate }}</div>
                        <div class="truncate">📍 {{ $famille->adresse_complete }}</div>
                        @if($famille->quartier || $famille->ville)
                            <div class="truncate">🏙️ {{ $famille->quartier->nom ?? '—' }}@if($famille->ville), {{ $famille->ville }}@endif</div>
                        @endif
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        @include('familles.partials.criticite', ['famille' => $famille])
                        @include('familles.partials.chips-eligibilite', ['famille' => $famille, 'wrapJustifyEnd' => true])
                    </div>
                </div>
            @endforeach
        </div>

        <div class="px-4 py-3 border-t border-surface-3">
            @include('partials.pagination', ['paginator' => $familles])
        </div>
    @endif
</div>

<script>
    // Bascule d'affichage des colonnes — pas de persistance (voir décision
    // du 12/08/2026) : l'état des cases (voir 'defaut' dans
    // Famille::COLONNES_TABLEAU) est toujours réinitialisé au rechargement
    // de la page, jamais mémorisé entre deux visites.
    document.querySelectorAll('.famille-col-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            var colonne = this.dataset.colToggle;
            var visible = this.checked;
            document.querySelectorAll('[data-col="' + colonne + '"]').forEach(function (cellule) {
                cellule.hidden = !visible;
            });
        });
    });
</script>
