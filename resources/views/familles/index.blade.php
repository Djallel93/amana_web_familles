{{-- resources/views/familles/index.blade.php --}}
@extends('layouts.app')

@section('title', 'Dossiers — AMANA Familles')

@section('content')

    @php
        // Couleurs de statut/liseré/badge — source unique désormais
        // Famille::ETAT_COLORS_LISTERE / Famille::ETAT_COLORS (extraites le
        // 10/09/2026, Section A2 du refactor, en même temps que
        // Famille::COLONNES_TABLEAU / <x-partial> familles.partials.tableau
        // plus bas) plutôt que deux tableaux littéraux dupliqués dans cette
        // vue. Toujours la même seule source de vérité entre le liseré, le
        // badge de statut et les pastilles du filtre Statut ci-dessous.
        $etatColorsListere = \App\Models\Famille::ETAT_COLORS_LISTERE;
        $etatColors = \App\Models\Famille::ETAT_COLORS;
        $filtresActifs = request()->anyFilled(['etat_dossier', 'id_quartier', 'id_secteur', 'id_ville', 'zakat_el_fitr', 'sadaqa', 'se_deplace', 'est_hotel', 'etudiant', 'criticite', 'nom', 'telephone', 'id_organisation_origine', 'id_organisation_rattachee']);

        // Puces de filtres actifs (voir section juste après le formulaire) —
        // chaque puce porte son URL de suppression déjà calculée (paramètres
        // hors 'page' + le filtre en question retiré, ou pour le statut,
        // explicitement mis à '' puisque l'absence du paramètre retomberait
        // sur le défaut "Validé" plutôt que "Tous").
        $parametresBase = collect(request()->except('page'));
        $puces = [];
        if ($etatDossier !== '') {
            $puces[] = ['label' => 'Statut : ' . $etatDossier, 'href' => route('familles.index', $parametresBase->merge(['etat_dossier' => ''])->all())];
        }
        if (request()->filled('id_ville')) {
            $villeNom = optional($villes->firstWhere('id', (int) request('id_ville')))->nom ?? request('id_ville');
            $puces[] = ['label' => 'Ville : ' . $villeNom, 'href' => route('familles.index', $parametresBase->except('id_ville')->all())];
        }
        if (request()->filled('id_quartier')) {
            $quartierNom = optional($quartiers->firstWhere('id', (int) request('id_quartier')))->nom ?? request('id_quartier');
            $puces[] = ['label' => 'Quartier : ' . $quartierNom, 'href' => route('familles.index', $parametresBase->except('id_quartier')->all())];
        }
        if (request()->filled('id_selection')) {
            // Sélection précise via l'autocomplétion (voir rechercheSuggestions())
            // plutôt que le texte du champ Nom/Téléphone, qui ne sert plus qu'à
            // l'affichage une fois une suggestion choisie.
            $puces[] = ['label' => '🔗 Résultat sélectionné', 'href' => route('familles.index', $parametresBase->except(['id_selection', 'nom', 'telephone'])->all())];
        } else {
            if (request()->filled('nom')) {
                $puces[] = ['label' => 'Nom : "' . request('nom') . '"', 'href' => route('familles.index', $parametresBase->except('nom')->all())];
            }
            if (request()->filled('telephone')) {
                $puces[] = ['label' => 'Téléphone : "' . request('telephone') . '"', 'href' => route('familles.index', $parametresBase->except('telephone')->all())];
            }
        }
        if (request()->filled('criticite')) {
            $criticiteValeurs = collect((array) request('criticite'))->map(fn($v) => (int) $v)->sort()->values();
            if ($criticiteValeurs->isNotEmpty()) {
                $puces[] = ['label' => 'Criticité : ' . $criticiteValeurs->implode(', '), 'href' => route('familles.index', $parametresBase->except('criticite')->all())];
            }
        }
        if (request()->boolean('se_deplace')) {
            $puces[] = ['label' => 'Se déplace', 'href' => route('familles.index', $parametresBase->except('se_deplace')->all())];
        }
        if (request()->boolean('est_hotel')) {
            $puces[] = ['label' => '🏨 Hôtel', 'href' => route('familles.index', $parametresBase->except('est_hotel')->all())];
        }
        if (request()->boolean('etudiant')) {
            $puces[] = ['label' => '🎓 Étudiant', 'href' => route('familles.index', $parametresBase->except('etudiant')->all())];
        }
        if (request()->boolean('zakat_el_fitr')) {
            $puces[] = ['label' => 'Zakat El Fitr', 'href' => route('familles.index', $parametresBase->except('zakat_el_fitr')->all())];
        }
        if (request()->boolean('sadaqa')) {
            $puces[] = ['label' => 'Sadaqa', 'href' => route('familles.index', $parametresBase->except('sadaqa')->all())];
        }
        if (request()->filled('id_organisation_origine')) {
            $orgOrigineNom = optional($organisations->firstWhere('id', (int) request('id_organisation_origine')))->nom ?? request('id_organisation_origine');
            $puces[] = ['label' => 'Organisation d\'origine : ' . $orgOrigineNom, 'href' => route('familles.index', $parametresBase->except('id_organisation_origine')->all())];
        }
        if (request()->filled('id_organisation_rattachee')) {
            $orgRattacheeNom = optional($organisations->firstWhere('id', (int) request('id_organisation_rattachee')))->nom ?? request('id_organisation_rattachee');
            $puces[] = ['label' => 'Organisation rattachée : ' . $orgRattacheeNom, 'href' => route('familles.index', $parametresBase->except('id_organisation_rattachee')->all())];
        }
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-4 mb-5">
        <div>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Dossiers familles</h1>
            <p class="text-[13px] text-ink-muted mt-1">
                {{ $familles->total() }} dossier{{ $familles->total() !== 1 ? 's' : '' }}
                @if($filtresActifs)
                    (filtré{{ $familles->total() !== 1 ? 's' : '' }})
                @endif
            </p>
        </div>
    </div>

    {{-- Bandeau KPI retiré le 13/08/2026 — "Criticité moyenne" et
         "Répartition par statut" existent déjà tels quels sur la page
         Statistiques (cartes.criticiteMoyenne / parEtatDossier), pas de
         raison de les dupliquer ici. Le total filtré était déjà visible
         dans le sous-titre juste au-dessus ("X dossiers (filtrés)"). Seul
         "À traiter en priorité" était une info propre à ce bandeau, sans
         équivalent ailleurs — migré vers FamilleStatistics::cartes()
         (aTraiterPriorite) et FamillesStatistiques.vue plutôt que perdu. --}}

    {{-- ── Barre de filtres ── panneau partagé (FamilleFilterPanel.vue via
         FamilleFiltresBar.vue) depuis le 10/09/2026 (Section A3 du
         refactor) — remplace le <form method="GET"> + script
         d'autocomplétion propres à cette vue, voir
         familles/partials/filtres.blade.php. Statut + autocomplétion
         Nom/Téléphone activés ici (avecStatut/avecAutocompletion),
         propres à Dossier Familles — voir le docblock du composant. --}}
    @include('familles.partials.filtres', [
        'villes' => $villes,
        'secteurs' => $secteurs,
        'quartiers' => $quartiers,
        'organisations' => $organisations,
        'valeursFiltres' => $valeursFiltres,
        'avecStatut' => true,
        'avecAutocompletion' => true,
        'ouvertParDefaut' => true,
        'routeIndex' => 'familles.index',
    ])

    {{-- ── Puces de filtres actifs + export CSV ──
         Même niveau visuel que demandé le 13/08/2026 : le bouton d'export
         reste toujours visible (filtres actifs ou non — "filtré/non
         filtré"), le bandeau de puces lui n'apparaît que s'il y a quelque
         chose à afficher. request()->query() propage tels quels tous les
         paramètres de filtre courants vers /familles/export, qui applique
         exactement la même logique que baseQuery()/index(). --}}
    <div class="flex flex-wrap items-start justify-between gap-2 mb-5">
        <div class="flex-1 min-w-0">
            @if(count($puces))
                <div class="flex flex-wrap items-center gap-2 px-3 py-2.5 rounded-lg bg-accent/5 border border-accent/20 animate-fade-in-up">
                    <span class="text-[10.5px] text-accent-dark uppercase tracking-wide font-bold flex items-center gap-1">🔎 Filtres actifs</span>
                    @foreach($puces as $puce)
                        <a href="{{ $puce['href'] }}"
                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-accent/15 text-accent-dark text-[11.5px] font-semibold no-underline hover:bg-accent/25 active:scale-95 transition-all">
                            {{ $puce['label'] }}
                            <span class="text-[10px]">✕</span>
                        </a>
                    @endforeach
                    <a href="{{ route('familles.index', ['etat_dossier' => '']) }}"
                        class="ml-auto text-[11px] text-ink-muted hover:text-accent-dark font-semibold no-underline transition-colors">
                        Tout réinitialiser
                    </a>
                </div>
            @endif
        </div>
        {{-- Sync retour Google Contacts (décision du 14/08/2026) — voir
             ReverseSyncPanel.vue + GoogleContactsReverseSyncController.
             Réservé gestionnaire+ : même niveau d'accès que l'édition des
             dossiers eux-mêmes (le bouton écrit potentiellement en base). --}}
        @if(auth()->user()->isAdmin() || auth()->user()->isGestionnaire())
            <button type="button" onclick="window.openReverseSyncPanel && window.openReverseSyncPanel()"
                class="inline-flex items-center gap-1.5 px-3 py-2 border border-surface-border bg-surface hover:bg-surface-2 text-ink text-[12.5px] font-semibold rounded-lg transition-colors active:scale-95 flex-shrink-0">
                🔄 Sync retour Google Contacts
            </button>
        @endif
        <a href="{{ route('familles.export', request()->query()) }}"
            class="inline-flex items-center gap-1.5 px-3 py-2 border border-surface-border bg-surface hover:bg-surface-2 text-ink text-[12.5px] font-semibold rounded-lg transition-colors active:scale-95 no-underline flex-shrink-0">
            ⬇️ Exporter CSV
        </a>
    </div>

    <script>
        // Ouverture automatique de la fiche après sélection d'une suggestion
        // (voir ci-dessus) — le paramètre ?ouvrir=<id> survit au rechargement
        // de page complet (pas d'AJAX ici, cohérent avec le reste du
        // formulaire de filtres). window.openFamilleDetail n'existe qu'une
        // fois le composant Vue du panneau monté (voir DetailPanel.vue,
        // onMounted), d'où le sondage court plutôt qu'un appel direct.
        (function () {
            var params = new URLSearchParams(window.location.search);
            var idAOuvrir = params.get('ouvrir');
            if (!idAOuvrir) return;
            var tentatives = 0;
            var intervalle = setInterval(function () {
                tentatives++;
                if (typeof window.openFamilleDetail === 'function') {
                    clearInterval(intervalle);
                    window.openFamilleDetail(parseInt(idAOuvrir, 10));
                    params.delete('ouvrir');
                    var reste = params.toString();
                    window.history.replaceState({}, '', window.location.pathname + (reste ? '?' + reste : ''));
                } else if (tentatives > 60) { // ~3s à 50ms
                    clearInterval(intervalle);
                }
            }, 50);
        })();
    </script>

    @include('familles.partials.tableau', [
        'familles' => $familles,
        'triActuel' => request('tri'),
        'directionActuelle' => request('direction') === 'desc' ? 'desc' : 'asc',
        'routeTri' => 'familles.index',
        'videIcone' => '🏠',
        'videTitre' => 'Aucun dossier',
        'aFiltresActifs' => $filtresActifs,
        'videMessageBase' => "Aucune famille enregistrée pour l'instant. L'import des dossiers existants est une étape à venir.",
        'videMessageFiltre' => "Aucun résultat pour ces filtres. Essayez d'élargir vos critères ou",
        'videLienReinitialisation' => ['texte' => 'réinitialisez-les', 'href' => route('familles.index', ['etat_dossier' => ''])],
    ])

    {{-- Point de montage du panneau de détail/édition et du bloc secteurs/organismes
         d'aide, voir resources/js/components/familles/DetailPanel.vue --}}
    @include('familles.partials.vue-famille-detail', ['secteursActivite' => $secteursActivite, 'organismesAide' => $organismesAide])

    {{-- Point de montage du panneau "Sync retour Google Contacts" — voir
         resources/js/components/familles/ReverseSyncPanel.vue. Monté sur
         toutes les vues affichant le bouton (actuellement uniquement
         familles/index.blade.php), gestionnaire+ (voir bouton ci-dessus). --}}
    @if(auth()->user()->isAdmin() || auth()->user()->isGestionnaire())
        <div id="vue-reverse-sync-panel"
             data-scan-url="{{ route('familles.google-contacts.scan') }}"
             data-apply-url="{{ route('familles.google-contacts.appliquer') }}">
        </div>
    @endif

@endsection
