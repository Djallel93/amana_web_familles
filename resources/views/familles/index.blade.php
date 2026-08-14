{{-- resources/views/familles/index.blade.php --}}
@extends('layouts.app')

@section('title', 'Dossiers — AMANA Familles')

@section('content')

    @php
        // Couleur du liseré de gauche de chaque ligne du tableau (voir
        // <tbody> plus bas). Classes Tailwind écrites en toutes lettres
        // (pas de concaténation dynamique bg-{{ }}) : le scanner JIT de
        // Tailwind ne détecte que des tokens littéraux dans le fichier
        // source, une classe construite à l'exécution serait purgée du CSS
        // généré et n'aurait donc aucun effet visuel.
        $etatColorsListere = [
            'Recu' => 'border-l-stone-400',
            'En cours' => 'border-l-sky-400',
            'En attente' => 'border-l-amber-400',
            'Validé' => 'border-l-emerald-500',
            'Rejeté' => 'border-l-rose-400',
            'Archivé' => 'border-l-gray-400',
        ];
        // Badges de statut (texte + fond léger) — remontés ici depuis le
        // <tbody> le 13/08/2026 pour être partagés avec le filtre Statut
        // (pastilles colorées ci-dessous), qui reprend désormais exactement
        // ces couleurs plutôt qu'un <select> texte : une seule source de
        // vérité au lieu de deux palettes qui auraient pu diverger.
        $etatColors = [
            'Recu' => 'bg-stone-100 text-stone-700 border-stone-300',
            'En cours' => 'bg-sky-50 text-sky-700 border-sky-200',
            'En attente' => 'bg-amber-50 text-amber-700 border-amber-200',
            'Validé' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'Rejeté' => 'bg-rose-50 text-rose-700 border-rose-200',
            'Archivé' => 'bg-gray-100 text-gray-500 border-gray-300',
        ];
        $filtresActifs = request()->anyFilled(['etat_dossier', 'id_quartier', 'id_secteur', 'id_ville', 'zakat_el_fitr', 'sadaqa', 'se_deplace', 'est_hotel', 'etudiant', 'criticite', 'nom', 'telephone']);

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

    {{-- ── Barre de filtres ──
         <details>/<summary> comme bouton de réduction (même pattern que
         le sélecteur de colonnes plus bas) : le contenu masqué par un
         <details> fermé n'est que display:none côté rendu, les champs
         qu'il contient restent normalement soumis avec le formulaire —
         pas besoin de JS supplémentaire pour préserver les filtres actifs
         en repliant la section (demande du 13/08/2026). Ouvert par défaut,
         pas de persistance de l'état (même décision que "Colonnes"). --}}
    <form method="GET" action="{{ route('familles.index') }}" id="filtres-form"
        class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 mb-4">
        {{-- Préserve le "lignes par page" en cours au clic sur "Filtrer" —
             sans ça, appliquer un filtre réinitialiserait silencieusement
             la pagination au défaut (ajouté le 12/08/2026, voir
             partials/pagination.blade.php). --}}
        <input type="hidden" name="per_page" value="{{ request('per_page', \App\Models\Famille::PAGINATION_PAR_PAGE_DEFAUT) }}">
        <details class="group" open>
            <summary class="cursor-pointer list-none flex items-center justify-between select-none -mx-2 -my-1 px-2 py-2 mb-2 rounded-lg hover:bg-surface-2 transition-colors">
                <span class="text-[13px] font-bold text-ink flex items-center gap-1.5">
                    🔎 Filtres
                    @if($filtresActifs)
                        <span class="px-1.5 py-0.5 rounded-full bg-accent/10 text-accent-dark text-[10px] font-bold">actifs</span>
                    @endif
                </span>
                <span class="text-ink-muted text-[13px] transition-transform duration-200 group-open:rotate-180">▾</span>
            </summary>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">

            {{-- Groupe 1 : recherche & statut --}}
            <div class="bg-surface-2 rounded-lg p-3">
                <div class="text-[10px] font-bold text-ink-muted uppercase tracking-wide mb-2">🔎 Recherche &amp; statut</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    {{-- id_selection : posé par l'autocomplétion (voir le
                         script en bas de page) quand l'utilisateur clique une
                         suggestion — prime alors sur nom/telephone côté
                         FamillesController::baseQuery(), voir le commentaire
                         là-bas. Vidé automatiquement dès que l'utilisateur
                         retape dans l'un des deux champs. --}}
                    <input type="hidden" name="id_selection" id="filtre-id-selection" value="{{ request('id_selection') }}">
                    <div>
                        <label class="block text-[10.5px] font-semibold text-ink-muted mb-1">Nom</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint text-[13px] pointer-events-none">🔍</span>
                            <input type="text" name="nom" id="filtre-recherche-nom" value="{{ request('nom') }}" placeholder="Nom ou prénom…" autocomplete="off"
                                class="w-full pl-9 pr-3 py-2 border border-ink-faint rounded-lg text-[13px] bg-surface outline-none
                                        focus:border-accent focus:shadow-[0_0_0_3px_rgba(180,83,9,0.15)]">
                            <div id="filtre-recherche-nom-suggestions" class="hidden absolute z-20 left-0 right-0 mt-1 bg-surface border border-surface-border rounded-lg shadow-lg overflow-hidden"></div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10.5px] font-semibold text-ink-muted mb-1">Téléphone</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint text-[13px] pointer-events-none">📞</span>
                            <input type="text" name="telephone" id="filtre-recherche-telephone" value="{{ request('telephone') }}" placeholder="Numéro…" autocomplete="off"
                                class="w-full pl-9 pr-3 py-2 border border-ink-faint rounded-lg text-[13px] bg-surface outline-none
                                        focus:border-accent focus:shadow-[0_0_0_3px_rgba(180,83,9,0.15)]">
                            <div id="filtre-recherche-telephone-suggestions" class="hidden absolute z-20 left-0 right-0 mt-1 bg-surface border border-surface-border rounded-lg shadow-lg overflow-hidden"></div>
                        </div>
                    </div>
                    <div class="sm:col-span-2">
                        {{-- Pastilles colorées plutôt qu'un <select> texte —
                             reprend $etatColors (même palette que le badge de
                             statut dans le tableau, voir <thead> plus bas) au
                             lieu d'options en texte brut (demande du
                             13/08/2026). Radios visuellement masqués
                             (sr-only) + <label> stylée avec peer-checked :
                             pas de JS, fonctionne tel quel dans le <details>
                             replié.
                             Pas d'option "Tous" (retirée le 13/08/2026, jugée
                             redondante avec le "Tout réinitialiser" du
                             bandeau Filtres actifs) — les radios ne pouvant
                             pas se décocher nativement, revenir à "Tous" se
                             fait via ce bandeau, pas depuis ces pastilles. --}}
                        <label class="block text-[10.5px] font-semibold text-ink-muted mb-1">🏷️ Statut</label>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach(\App\Models\Famille::ETATS_MODIFIABLES as $etat)
                                <label class="cursor-pointer">
                                    <input type="radio" name="etat_dossier" value="{{ $etat }}" class="sr-only peer" {{ $etatDossier === $etat ? 'checked' : '' }}>
                                    <span class="inline-flex px-2.5 py-1 rounded-full text-[11.5px] font-semibold border {{ $etatColors[$etat] ?? '' }} peer-checked:ring-2 peer-checked:ring-offset-1 peer-checked:ring-current peer-checked:font-bold transition-all">{{ $etat }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            {{-- Groupe 2 : localisation --}}
            <div class="bg-surface-2 rounded-lg p-3">
                <div class="text-[10px] font-bold text-ink-muted uppercase tracking-wide mb-2">📍 Localisation</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[10.5px] font-semibold text-ink-muted mb-1">🏙️ Ville</label>
                        <select name="id_ville" id="filtre-id-ville" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface outline-none focus:border-accent">
                            <option value="">Toutes</option>
                            @foreach($villes as $ville)
                                <option value="{{ $ville->id }}" {{ (string) request('id_ville') === (string) $ville->id ? 'selected' : '' }}>{{ $ville->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10.5px] font-semibold text-ink-muted mb-1">📌 Quartier</label>
                        <select name="id_quartier" id="filtre-id-quartier" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface outline-none focus:border-accent">
                            <option value="">Tous</option>
                            @foreach($quartiers as $quartier)
                                {{-- data-id-ville : Quartier n'a pas de colonne id_ville directe
                                     (voir Amana\Shared\Models\Quartier::ville(), via secteur) —
                                     utilisé par le script ci-dessous pour filtrer cette liste
                                     quand "Ville" change, sans aller-retour serveur. --}}
                                <option value="{{ $quartier->id }}"
                                    data-id-ville="{{ $quartier->secteur->id_ville ?? '' }}"
                                    {{ (string) request('id_quartier') === (string) $quartier->id ? 'selected' : '' }}>{{ $quartier->nom }}</option>
                            @endforeach
                        </select>
                        @if($quartiers->isEmpty())
                            <p class="text-[10px] text-ink-faint mt-1">Aucun quartier importé pour l'instant.</p>
                        @endif
                    </div>
                    <div class="col-span-2 pt-1">
                        {{-- has-[:checked] (Tailwind 3.4+, CSS :has()) plutôt
                             qu'un binding JS — même intention que les
                             checkboxes "chip" de IntakeForm.vue/DetailPanel.vue
                             (bordure + fond teinté à la coche) mais en pur
                             CSS puisque Blade est rendu côté serveur. --}}
                        <label class="flex items-center gap-2 px-3 py-2 border border-ink-faint rounded-md text-[12.5px] text-ink-muted cursor-pointer select-none transition-colors has-[:checked]:border-accent has-[:checked]:bg-accent/5 has-[:checked]:text-ink has-[:checked]:font-semibold">
                            <input type="checkbox" name="est_hotel" value="1" {{ request()->boolean('est_hotel') ? 'checked' : '' }} class="w-4 h-4 accent-accent">
                            🏨 Hôtel (hébergement d'urgence)
                        </label>
                    </div>
                </div>
            </div>

            {{-- Groupe 3 : profil du foyer --}}
            <div class="bg-surface-2 rounded-lg p-3">
                <div class="text-[10px] font-bold text-ink-muted uppercase tracking-wide mb-2">🧾 Profil du foyer</div>
                <div class="mb-3">
                    {{-- Sélection discrète plutôt qu'un intervalle min/max —
                         cocher 3 ET 5 ne doit pas ramener 4 (demande du
                         13/08/2026). Un <input type="checkbox"> par valeur,
                         tous nommés criticite[] pour arriver en tableau côté
                         FamillesController::baseQuery(). --}}
                    <label class="block text-[10.5px] font-semibold text-ink-muted mb-1.5">🎚️ Criticité</label>
                    {{-- Chips colorées reprenant l'échelle de sévérité des
                         pastilles du tableau (0-1 vert / 2-3 ambre / 4-5
                         rose, voir la colonne "criticite" du <tbody>) plutôt
                         que des cases à cocher nues — demande du 13/08/2026,
                         jugées "moche" et peu lisibles. Classes has-[:checked]
                         écrites en toutes lettres par palier (pas de
                         construction dynamique bg-{{ }}) pour rester
                         détectables par le scanner JIT de Tailwind, même
                         raison que $etatColorsListere plus haut. --}}
                    @php
                        $criticiteSelectionnees = array_map('strval', (array) request('criticite', []));
                        $criticitePaliers = [
                            0 => 'has-[:checked]:bg-emerald-500 has-[:checked]:border-emerald-500 has-[:checked]:text-white',
                            1 => 'has-[:checked]:bg-emerald-500 has-[:checked]:border-emerald-500 has-[:checked]:text-white',
                            2 => 'has-[:checked]:bg-amber-500 has-[:checked]:border-amber-500 has-[:checked]:text-white',
                            3 => 'has-[:checked]:bg-amber-500 has-[:checked]:border-amber-500 has-[:checked]:text-white',
                            4 => 'has-[:checked]:bg-rose-500 has-[:checked]:border-rose-500 has-[:checked]:text-white',
                            5 => 'has-[:checked]:bg-rose-500 has-[:checked]:border-rose-500 has-[:checked]:text-white',
                        ];
                    @endphp
                    <div class="flex flex-wrap items-center gap-1.5">
                        @for ($i = 0; $i <= 5; $i++)
                            <label class="w-9 h-9 flex items-center justify-center border border-ink-faint rounded-md text-[13px] font-bold text-ink-muted cursor-pointer select-none transition-colors {{ $criticitePaliers[$i] }}">
                                <input type="checkbox" name="criticite[]" value="{{ $i }}" {{ in_array((string) $i, $criticiteSelectionnees, true) ? 'checked' : '' }} class="sr-only">
                                {{ $i }}
                            </label>
                        @endfor
                    </div>
                </div>
                {{-- Sous-bloc "caractéristiques" séparé du reste — quatre
                     coches en vrac sur deux lignes serrées donnaient une
                     impression de fouillis (signalé le 13/08/2026). Une
                     grille 2×2 de chips avec libellé propre + bordure
                     dédiée à chacune est plus lisible et laisse respirer
                     la section. --}}
                <div>
                    <label class="block text-[10.5px] font-semibold text-ink-muted mb-1.5">Caractéristiques</label>
                    <div class="grid grid-cols-2 gap-1.5">
                        <label class="flex items-center gap-2 px-3 py-2 border border-ink-faint rounded-md text-[12.5px] text-ink-muted cursor-pointer select-none transition-colors has-[:checked]:border-accent has-[:checked]:bg-accent/5 has-[:checked]:text-ink has-[:checked]:font-semibold">
                            <input type="checkbox" name="se_deplace" value="1" {{ request()->boolean('se_deplace') ? 'checked' : '' }} class="w-4 h-4 accent-accent">
                            🚗 Se déplace
                        </label>
                        <label class="flex items-center gap-2 px-3 py-2 border border-ink-faint rounded-md text-[12.5px] text-ink-muted cursor-pointer select-none transition-colors has-[:checked]:border-accent has-[:checked]:bg-accent/5 has-[:checked]:text-ink has-[:checked]:font-semibold">
                            <input type="checkbox" name="etudiant" value="1" {{ request()->boolean('etudiant') ? 'checked' : '' }} class="w-4 h-4 accent-accent">
                            🎓 Étudiant
                        </label>
                        <label class="flex items-center gap-2 px-3 py-2 border border-ink-faint rounded-md text-[12.5px] text-ink-muted cursor-pointer select-none transition-colors has-[:checked]:border-accent has-[:checked]:bg-accent/5 has-[:checked]:text-ink has-[:checked]:font-semibold">
                            <input type="checkbox" name="zakat_el_fitr" value="1" {{ request()->boolean('zakat_el_fitr') ? 'checked' : '' }} class="w-4 h-4 accent-accent">
                            🌙 Zakat El Fitr
                        </label>
                        <label class="flex items-center gap-2 px-3 py-2 border border-ink-faint rounded-md text-[12.5px] text-ink-muted cursor-pointer select-none transition-colors has-[:checked]:border-accent has-[:checked]:bg-accent/5 has-[:checked]:text-ink has-[:checked]:font-semibold">
                            <input type="checkbox" name="sadaqa" value="1" {{ request()->boolean('sadaqa') ? 'checked' : '' }} class="w-4 h-4 accent-accent">
                            🤲 Sadaqa
                        </label>
                    </div>
                </div>
            </div>
        </div>

        {{-- Plus de boutons Filtrer/Réinitialiser (retirés le 13/08/2026) —
             chaque filtre s'applique immédiatement au changement (voir le
             script d'auto-soumission en bas de page), la réinitialisation se
             fait désormais uniquement via "Tout réinitialiser" dans le
             bandeau Filtres actifs ci-dessous. --}}
        </details>
    </form>

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
        <a href="{{ route('familles.export', request()->query()) }}"
            class="inline-flex items-center gap-1.5 px-3 py-2 border border-surface-border bg-surface hover:bg-surface-2 text-ink text-[12.5px] font-semibold rounded-lg transition-colors active:scale-95 no-underline flex-shrink-0">
            ⬇️ Exporter CSV
        </a>
    </div>

    <script>
        // Filtre Quartier en cascade sur la sélection de Ville — Quartier n'a
        // pas de colonne id_ville directe (voir data-id-ville posé sur
        // chaque <option> ci-dessus), tout est déjà chargé côté client donc
        // pas besoin d'aller-retour serveur.
        (function () {
            var villeSelect = document.getElementById('filtre-id-ville');
            var quartierSelect = document.getElementById('filtre-id-quartier');
            if (!villeSelect || !quartierSelect) return;

            function filtrerQuartiers() {
                var idVille = villeSelect.value;
                var selectionEncoreValide = false;

                Array.from(quartierSelect.options).forEach(function (option) {
                    if (!option.value) return; // "Tous" — toujours visible.
                    var correspond = !idVille || option.dataset.idVille === idVille;
                    option.hidden = !correspond;
                    if (option.selected && correspond) selectionEncoreValide = true;
                });

                if (!selectionEncoreValide) quartierSelect.value = '';
            }

            villeSelect.addEventListener('change', filtrerQuartiers);
            filtrerQuartiers(); // état initial (ex: retour arrière avec ville déjà sélectionnée dans l'URL)
        })();

        // Auto-application des filtres (retrait des boutons Filtrer/
        // Réinitialiser le 13/08/2026) — tout changement sur un <select>,
        // une case à cocher ou un bouton radio du formulaire soumet
        // immédiatement. 'change' est un événement bouillonnant (bubbles),
        // la délégation sur le formulaire capture donc aussi bien le select
        // Ville/Quartier que les chips criticité/statut/caractéristiques,
        // APRÈS le gestionnaire ci-dessus posé directement sur villeSelect
        // (les écouteurs sur la cible elle-même s'exécutent avant ceux posés
        // en délégation sur un ancêtre, quel que soit l'ordre d'attache).
        // Les deux champs texte (Nom/Téléphone) sont volontairement exclus :
        // voir la logique d'autocomplétion ci-dessous, qui gère elle-même
        // quand soumettre.
        (function () {
            var form = document.getElementById('filtres-form');
            if (!form) return;
            form.addEventListener('change', function (e) {
                if (e.target.matches('select, input[type="radio"], input[type="checkbox"]')) {
                    form.submit();
                }
            });
        })();

        // Autocomplétion Nom / Téléphone — un clic sur une suggestion
        // remplit le champ, verrouille la sélection sur l'id exact via le
        // champ caché id_selection (voir FamillesController::baseQuery()),
        // soumet le filtre ET marque la fiche à ouvrir automatiquement une
        // fois la page rechargée (voir l'IIFE tout en bas, après le rendu
        // du tableau) — demande du 13/08/2026 : "remplir, filtrer puis
        // ouvrir la fiche".
        (function () {
            var form = document.getElementById('filtres-form');
            var idSelection = document.getElementById('filtre-id-selection');
            if (!form) return;

            function setupChamp(champ, inputId, suggestionsId) {
                var input = document.getElementById(inputId);
                var boite = document.getElementById(suggestionsId);
                if (!input || !boite) return;
                var minuteur = null;
                var url = @json(route('familles.recherche-suggestions'));

                function fermer() {
                    boite.classList.add('hidden');
                    boite.innerHTML = '';
                }

                input.addEventListener('input', function () {
                    // Une frappe manuelle invalide toute sélection précédente
                    // (revient à une recherche LIKE classique sur le texte).
                    if (idSelection) idSelection.value = '';
                    clearTimeout(minuteur);
                    var terme = input.value.trim();
                    if (terme.length < 2) { fermer(); return; }
                    minuteur = setTimeout(function () {
                        fetch(url + '?champ=' + champ + '&q=' + encodeURIComponent(terme))
                            .then(function (r) { return r.ok ? r.json() : []; })
                            .then(function (resultats) {
                                if (!Array.isArray(resultats) || !resultats.length) { fermer(); return; }
                                boite.innerHTML = resultats.map(function (f) {
                                    var valeur = String(f.valeur || '').replace(/"/g, '&quot;');
                                    var label = String(f.label || '');
                                    var sousLabel = String(f.sous_label || '');
                                    return '<button type="button" data-id="' + f.id + '" data-valeur="' + valeur + '" ' +
                                        'class="w-full text-left px-3 py-2 hover:bg-surface-2 text-[12.5px] flex items-center justify-between gap-2 border-b border-surface-3 last:border-b-0">' +
                                        '<span class="font-semibold text-ink">' + label + '</span>' +
                                        '<span class="text-ink-muted text-[11px]">' + sousLabel + '</span>' +
                                    '</button>';
                                }).join('');
                                boite.classList.remove('hidden');
                            })
                            .catch(fermer);
                    }, 300);
                });

                boite.addEventListener('click', function (e) {
                    var bouton = e.target.closest('button[data-id]');
                    if (!bouton) return;
                    input.value = bouton.dataset.valeur;
                    if (idSelection) idSelection.value = bouton.dataset.id;
                    fermer();
                    var params = new URLSearchParams(new FormData(form));
                    params.set('ouvrir', bouton.dataset.id);
                    window.location.href = @json(route('familles.index')) + '?' + params.toString();
                });

                // Entrée = filtrer sur le texte tel quel, tous les résultats
                // correspondants (pas de sélection, pas d'ouverture de
                // fiche) — demande du 13/08/2026. Sans bouton "Filtrer" dans
                // le formulaire, la soumission implicite du navigateur sur
                // Entrée NE SE DÉCLENCHE PAS d'elle-même dès qu'il y a
                // plusieurs champs texte (règle HTML : implicite seulement
                // s'il n'existe qu'UN SEUL champ texte, or il y en a deux
                // ici, Nom et Téléphone) — d'où ce gestionnaire explicite,
                // plutôt qu'un bug de navigateur à contourner autrement.
                input.addEventListener('keydown', function (e) {
                    if (e.key !== 'Enter') return;
                    e.preventDefault();
                    clearTimeout(minuteur);
                    fermer();
                    if (idSelection) idSelection.value = '';
                    form.submit();
                });

                document.addEventListener('click', function (e) {
                    if (e.target !== input && !boite.contains(e.target)) fermer();
                });
            }

            setupChamp('nom', 'filtre-recherche-nom', 'filtre-recherche-nom-suggestions');
            setupChamp('telephone', 'filtre-recherche-telephone', 'filtre-recherche-telephone-suggestions');
        })();

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

    @php
        // Colonnes du tableau — clé utilisée à la fois pour data-col (bascule
        // d'affichage, voir <details> ci-dessous et le script en bas de page)
        // et pour ?tri=clé (en-têtes cliquables, voir
        // FamillesController::COLONNES_TRIABLES / appliquerTri()).
        // 'defaut' = colonne visible sans que l'utilisateur touche au
        // sélecteur de colonnes ; les autres existent mais démarrent
        // masquées pour ne pas surcharger le tableau par défaut (demande du
        // 12/08/2026 : pouvoir tout afficher, mais pas tout afficher d'office).
        // 'triable' à false pour les champs texte libre / relations (pas de
        // colonne SQL unique exploitable simplement) — voir
        // FamillesController::COLONNES_TRIABLES pour la liste exacte des clés
        // triables côté serveur.
        $colonnes = [
            'id' => ['label' => 'ID', 'triable' => true, 'defaut' => true],
            'nom' => ['label' => 'Nom', 'triable' => true, 'defaut' => true],
            'statut' => ['label' => 'Statut', 'triable' => true, 'defaut' => true],
            'email' => ['label' => 'Email', 'triable' => true, 'defaut' => false],
            'telephone' => ['label' => 'Téléphone', 'triable' => true, 'defaut' => true],
            'telephone_bis' => ['label' => 'Tél. bis', 'triable' => true, 'defaut' => false],
            'adresse' => ['label' => 'Adresse', 'triable' => true, 'defaut' => true],
            'quartier' => ['label' => 'Quartier', 'triable' => false, 'defaut' => true],
            'ville' => ['label' => 'Ville', 'triable' => false, 'defaut' => true],
            'nombre_adulte' => ['label' => 'Adultes', 'triable' => true, 'defaut' => false],
            'nombre_enfant' => ['label' => 'Enfants', 'triable' => true, 'defaut' => false],
            'criticite' => ['label' => 'Criticité', 'triable' => true, 'defaut' => true],
            'eligibilite' => ['label' => 'Éligibilité', 'triable' => true, 'defaut' => true],
            'se_deplace' => ['label' => 'Se déplace', 'triable' => true, 'defaut' => false],
            'est_hotel' => ['label' => 'Hôtel', 'triable' => true, 'defaut' => false],
            'etudiant' => ['label' => 'Étudiant', 'triable' => true, 'defaut' => false],
            'langue' => ['label' => 'Langue', 'triable' => true, 'defaut' => false],
            'type_piece_identite' => ['label' => 'Pièce identité', 'triable' => true, 'defaut' => false],
            'circonstances' => ['label' => 'Circonstances', 'triable' => false, 'defaut' => false],
            'ressentit' => ['label' => 'Ressenti', 'triable' => false, 'defaut' => false],
            'specificites' => ['label' => 'Spécificités', 'triable' => false, 'defaut' => false],
            'commentaire_dossier' => ['label' => 'Commentaire', 'triable' => false, 'defaut' => false],
            'created_at' => ['label' => 'Créé le', 'triable' => true, 'defaut' => false],
        ];
        $typePieceIdentiteLabels = [
            'nationalite' => 'Nationalité',
            'titre_sejour' => 'Titre de séjour',
            'demande_asile' => "Demande d'asile",
            'autre' => 'Autre',
        ];
        // Palette d'avatars (initiales) — couleur choisie par id % taille de
        // la palette, simple et stable (même famille = même couleur d'une
        // page à l'autre) sans avoir besoin de stocker quoi que ce soit.
        $avatarPalette = [
            ['bg' => 'bg-sky-100', 'text' => 'text-sky-700'],
            ['bg' => 'bg-amber-100', 'text' => 'text-amber-700'],
            ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-700'],
            ['bg' => 'bg-violet-100', 'text' => 'text-violet-700'],
            ['bg' => 'bg-rose-100', 'text' => 'text-rose-700'],
            ['bg' => 'bg-cyan-100', 'text' => 'text-cyan-700'],
        ];
        $triActuel = request('tri');
        $directionActuelle = request('direction') === 'desc' ? 'desc' : 'asc';
    @endphp

    {{-- ── Sélecteur de colonnes — pas de persistance (toujours réinitialisé
         au rechargement, décision du 12/08/2026), pur JS/CSS via [hidden]
         sur les <th>/<td> correspondants (data-col). ── --}}
    <div class="flex justify-end mb-2">
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

    {{-- ── Tableau compact ── --}}
    <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden">
        @if($familles->isEmpty())
            <div class="text-center py-16 px-8">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-accent/10 flex items-center justify-center text-3xl">🏠</div>
                <h3 class="font-heading text-base font-semibold text-ink mb-1.5">Aucun dossier</h3>
                <p class="text-ink-muted text-[13.5px] max-w-sm mx-auto">
                    @if($filtresActifs)
                        Aucun résultat pour ces filtres. Essayez d'élargir vos critères ou
                        <a href="{{ route('familles.index', ['etat_dossier' => '']) }}" class="text-accent hover:underline font-semibold">réinitialisez-les</a>.
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
                            @foreach($colonnes as $cle => $colonne)
                                <th data-col="{{ $cle }}" {{ $colonne['defaut'] ? '' : 'hidden' }}
                                    class="sticky top-topbar sm:top-0 z-10 text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                                    @if($colonne['triable'])
                                        @php
                                            $prochaineDirection = ($triActuel === $cle && $directionActuelle === 'asc') ? 'desc' : 'asc';
                                        @endphp
                                        <a href="{{ route('familles.index', array_merge(request()->except(['tri', 'direction', 'page']), ['tri' => $cle, 'direction' => $prochaineDirection])) }}"
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
                            @php $avatarStyle = $avatarPalette[$famille->id % count($avatarPalette)]; @endphp
                            <tr onclick="openFamilleDetail({{ $famille->id }})"
                                class="border-b border-surface-3 last:border-b-0 border-l-4 {{ $etatColorsListere[$famille->etat_dossier] ?? 'border-l-gray-300' }} hover:bg-surface-2 active:bg-surface-3 transition-colors duration-150 cursor-pointer {{ $famille->probleme_traitement ? 'bg-rose-50/60' : '' }}">
                                <td data-col="id" class="px-4 py-2.5 text-ink-faint font-mono text-[12px]">#{{ $famille->id }}</td>
                                <td data-col="nom" class="px-4 py-2.5">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 rounded-full {{ $avatarStyle['bg'] }} {{ $avatarStyle['text'] }} flex items-center justify-center text-[11px] font-bold flex-shrink-0">
                                            {{ strtoupper(mb_substr($famille->prenom, 0, 1) . mb_substr($famille->nom, 0, 1)) }}
                                        </div>
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
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $etatColors[$famille->etat_dossier] ?? '' }}">
                                        {{ $famille->etat_dossier }}
                                    </span>
                                </td>
                                <td data-col="email" {{ $colonnes['email']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted">{{ $famille->email ?? '—' }}</td>
                                <td data-col="telephone" class="px-4 py-2.5 text-ink-muted whitespace-nowrap">{{ $famille->telephone_formate }}</td>
                                <td data-col="telephone_bis" {{ $colonnes['telephone_bis']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted whitespace-nowrap">{{ $famille->telephone_bis_formate ?? '—' }}</td>
                                <td data-col="adresse" class="px-4 py-2.5 text-ink-muted">{{ $famille->adresse_complete }}</td>
                                <td data-col="quartier" {{ $colonnes['quartier']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted">{{ $famille->quartier->nom ?? '—' }}</td>
                                <td data-col="ville" {{ $colonnes['ville']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted">{{ $famille->ville ?? '—' }}</td>
                                <td data-col="nombre_adulte" {{ $colonnes['nombre_adulte']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted text-center">{{ $famille->nombre_adulte }}</td>
                                <td data-col="nombre_enfant" {{ $colonnes['nombre_enfant']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted text-center">{{ $famille->nombre_enfant }}</td>
                                <td data-col="criticite" class="px-4 py-2.5">
                                    <div class="flex items-center gap-1" title="Criticité {{ $famille->criticite }}/5">
                                        @for ($i = 1; $i <= 5; $i++)
                                            <span class="w-2 h-2 rounded-full {{ $i <= $famille->criticite ? ($famille->criticite >= 4 ? 'bg-rose-500' : ($famille->criticite >= 2 ? 'bg-amber-500' : 'bg-emerald-500')) : 'bg-surface-3' }}"></span>
                                        @endfor
                                    </div>
                                </td>
                                <td data-col="eligibilite" class="px-4 py-2.5">
                                    <div class="flex gap-1 flex-wrap">
                                        @if($famille->zakat_el_fitr)
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-accent/10 text-accent-dark">Zakat El Fitr</span>
                                        @endif
                                        @if($famille->sadaqa)
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-700">Sadaqa</span>
                                        @endif
                                    </div>
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

            <div class="px-4 py-3 border-t border-surface-3">
                @include('partials.pagination', ['paginator' => $familles])
            </div>
        @endif
    </div>

    <script>
        // Bascule d'affichage des colonnes — pas de persistance (voir
        // décision du 12/08/2026) : l'état des cases (voir 'defaut' dans le
        // tableau $colonnes plus haut) est toujours réinitialisé au
        // rechargement de la page, jamais mémorisé entre deux visites.
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

    {{-- Point de montage du panneau de détail/édition — voir
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
