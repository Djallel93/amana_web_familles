{{--
    resources/views/partials/pagination.blade.php

    Pagination stylée + sélecteur "lignes par page", partagée entre
    familles/index.blade.php et familles/nouvelles.blade.php (le composant
    par défaut de Laravel — {{ $paginator->links() }} — était visuellement
    à l'étroit dans la carte du tableau, sans thème cohérent avec le reste
    de l'app ; ajouté le 12/08/2026).

    Attend une variable $paginator (instance LengthAwarePaginator, déjà
    triée/filtrée/withQueryString()ée par le contrôleur).

    N'affiche PAS de liste de numéros de page (1 2 3 4 …) : avec les
    filtres actifs, le nombre de pages est généralement faible et une
    pagination Précédent/Suivant + "Page X / Y" reste lisible sans
    calculer quels numéros tronquer — plus simple à maintenir que la
    logique de fenêtre glissante du composant Laravel par défaut, pour un
    gain de clarté minime ici.
--}}
@if($paginator->total() > 0)
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <p class="text-[12px] text-ink-muted order-2 sm:order-1">
            Affichage <strong class="text-ink">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</strong>
            sur <strong class="text-ink">{{ $paginator->total() }}</strong>
            {{ $paginator->total() > 1 ? 'résultats' : 'résultat' }}
        </p>

        <div class="flex items-center gap-3 order-1 sm:order-2">
            <label class="flex items-center gap-1.5 text-[12px] text-ink-muted">
                Lignes par page
                <select data-per-page-select
                    class="px-2 py-1.5 border border-ink-faint rounded-md text-[12.5px] bg-surface outline-none focus:border-accent cursor-pointer">
                    @foreach(\App\Models\Famille::PAGINATION_PAR_PAGE as $option)
                        <option value="{{ $option }}" {{ $paginator->perPage() === $option ? 'selected' : '' }}>{{ $option }}</option>
                    @endforeach
                </select>
            </label>

            @if($paginator->hasPages())
                <div class="flex items-center gap-1">
                    <a href="{{ $paginator->previousPageUrl() ?? '#' }}"
                        class="px-2.5 py-1.5 border border-surface-border rounded-md text-[12.5px] font-semibold no-underline transition-colors
                            {{ $paginator->onFirstPage()
                                ? 'text-ink-faint bg-surface-2 pointer-events-none cursor-not-allowed'
                                : 'text-ink bg-surface hover:bg-surface-2 active:scale-95' }}"
                        @if($paginator->onFirstPage()) aria-disabled="true" tabindex="-1" @endif>
                        ← Précédent
                    </a>
                    <span class="px-2 text-[12.5px] text-ink-muted whitespace-nowrap">
                        Page {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
                    </span>
                    <a href="{{ $paginator->nextPageUrl() ?? '#' }}"
                        class="px-2.5 py-1.5 border border-surface-border rounded-md text-[12.5px] font-semibold no-underline transition-colors
                            {{ !$paginator->hasMorePages()
                                ? 'text-ink-faint bg-surface-2 pointer-events-none cursor-not-allowed'
                                : 'text-ink bg-surface hover:bg-surface-2 active:scale-95' }}"
                        @if(!$paginator->hasMorePages()) aria-disabled="true" tabindex="-1" @endif>
                        Suivant →
                    </a>
                </div>
            @endif
        </div>
    </div>

    <script>
        // Change de page 1 et recharge avec le nouveau per_page — tous les
        // autres paramètres de filtre/tri actuels sont préservés (on part
        // de window.location.search, pas d'un formulaire séparé).
        (function () {
            document.querySelectorAll('[data-per-page-select]').forEach(function (select) {
                select.addEventListener('change', function () {
                    const params = new URLSearchParams(window.location.search);
                    params.set('per_page', select.value);
                    params.set('page', '1');
                    window.location.search = params.toString();
                });
            });
        })();
    </script>
@endif
