{{-- resources/views/familles/index.blade.php --}}
@extends('layouts.app')

@section('title', 'Dossiers — AMANA Familles')

@section('content')

    @php
        // Couleurs "pleines" par statut — utilisées à la fois par le mini
        // bandeau de répartition du KPI et par le liseré de gauche de
        // chaque ligne du tableau. Classes Tailwind écrites en toutes
        // lettres (pas de concaténation dynamique bg-{{ }}) : le scanner
        // JIT de Tailwind ne détecte que des tokens littéraux dans le
        // fichier source, une classe construite à l'exécution (ex :
        // str_replace('bg-', 'border-l-', ...)) serait purgée du CSS
        // généré et n'aurait donc aucun effet visuel.
        $etatColorsPleines = [
            'Recu' => 'bg-stone-400',
            'En cours' => 'bg-sky-400',
            'En attente' => 'bg-amber-400',
            'Validé' => 'bg-emerald-500',
            'Rejeté' => 'bg-rose-400',
            'Archivé' => 'bg-gray-400',
        ];
        $etatColorsListere = [
            'Recu' => 'border-l-stone-400',
            'En cours' => 'border-l-sky-400',
            'En attente' => 'border-l-amber-400',
            'Validé' => 'border-l-emerald-500',
            'Rejeté' => 'border-l-rose-400',
            'Archivé' => 'border-l-gray-400',
        ];
        $totalStatuts = max($stats['par_statut']->sum(), 1);
        $filtresActifs = request()->anyFilled(['etat_dossier', 'id_quartier', 'id_secteur', 'id_ville', 'zakat_el_fitr', 'sadaqa', 'se_deplace', 'criticite_min', 'criticite_max', 'recherche']);

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
        if (request()->filled('recherche')) {
            $puces[] = ['label' => 'Recherche : "' . request('recherche') . '"', 'href' => route('familles.index', $parametresBase->except('recherche')->all())];
        }
        if (request()->filled('criticite_min')) {
            $puces[] = ['label' => 'Criticité ≥ ' . request('criticite_min'), 'href' => route('familles.index', $parametresBase->except('criticite_min')->all())];
        }
        if (request()->filled('criticite_max')) {
            $puces[] = ['label' => 'Criticité ≤ ' . request('criticite_max'), 'href' => route('familles.index', $parametresBase->except('criticite_max')->all())];
        }
        if (request('se_deplace') === '1' || request('se_deplace') === '0') {
            $puces[] = ['label' => 'Se déplace : ' . (request('se_deplace') === '1' ? 'Oui' : 'Non'), 'href' => route('familles.index', $parametresBase->except('se_deplace')->all())];
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

    {{-- ── Bandeau KPI ── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5 animate-fade-in-up">
        <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center text-lg flex-shrink-0">🏠</div>
            <div class="min-w-0">
                <div class="text-[20px] font-heading font-semibold text-ink leading-none">{{ $stats['total'] }}</div>
                <div class="text-[10.5px] text-ink-muted uppercase tracking-wide mt-1">Dossiers (filtres géo/recherche)</div>
            </div>
        </div>

        <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full {{ $stats['a_traiter'] > 0 ? 'bg-rose-100' : 'bg-emerald-100' }} flex items-center justify-center text-lg flex-shrink-0">
                {{ $stats['a_traiter'] > 0 ? '⚠️' : '✅' }}
            </div>
            <div class="min-w-0">
                <div class="text-[20px] font-heading font-semibold {{ $stats['a_traiter'] > 0 ? 'text-rose-600' : 'text-ink' }} leading-none">{{ $stats['a_traiter'] }}</div>
                <div class="text-[10.5px] text-ink-muted uppercase tracking-wide mt-1">À traiter en priorité</div>
            </div>
        </div>

        <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center text-lg flex-shrink-0">📊</div>
            <div class="min-w-0">
                <div class="text-[20px] font-heading font-semibold text-ink leading-none">
                    {{ number_format($stats['moyenne_criticite'], 1) }}<span class="text-[13px] text-ink-muted font-medium">/5</span>
                </div>
                <div class="text-[10.5px] text-ink-muted uppercase tracking-wide mt-1">Criticité moyenne</div>
            </div>
        </div>

        <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4">
            <div class="text-[10.5px] text-ink-muted uppercase tracking-wide mb-2">Répartition par statut</div>
            @if($stats['par_statut']->isEmpty())
                <div class="text-[12px] text-ink-faint">Aucun dossier.</div>
            @else
                <div class="flex h-2 rounded-full overflow-hidden bg-surface-3 mb-2">
                    @foreach($stats['par_statut'] as $etat => $total)
                        <div class="{{ $etatColorsPleines[$etat] ?? 'bg-gray-300' }}" style="width: {{ $total / $totalStatuts * 100 }}%" title="{{ $etat }} : {{ $total }}"></div>
                    @endforeach
                </div>
                <div class="flex flex-wrap gap-x-2.5 gap-y-1">
                    @foreach($stats['par_statut'] as $etat => $total)
                        <span class="inline-flex items-center gap-1 text-[10.5px] text-ink-muted">
                            <span class="w-1.5 h-1.5 rounded-full {{ $etatColorsPleines[$etat] ?? 'bg-gray-300' }}"></span>
                            {{ $etat }} ({{ $total }})
                        </span>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ── Barre de filtres ── --}}
    <form method="GET" action="{{ route('familles.index') }}"
        class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 mb-4">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">

            {{-- Groupe 1 : recherche & statut --}}
            <div class="bg-surface-2 rounded-lg p-3">
                <div class="text-[10px] font-bold text-ink-muted uppercase tracking-wide mb-2">🔎 Recherche &amp; statut</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <div class="sm:col-span-2">
                        <label class="block text-[10.5px] font-semibold text-ink-muted mb-1">Recherche</label>
                        <input type="text" name="recherche" value="{{ request('recherche') }}" placeholder="Nom, prénom, téléphone…"
                            class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface outline-none
                                    focus:border-accent focus:shadow-[0_0_0_3px_rgba(180,83,9,0.15)]">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-[10.5px] font-semibold text-ink-muted mb-1">🏷️ Statut</label>
                        <select name="etat_dossier" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface outline-none focus:border-accent">
                            <option value="" {{ $etatDossier === '' ? 'selected' : '' }}>Tous</option>
                            @foreach(\App\Models\Famille::ETATS_MODIFIABLES as $etat)
                                <option value="{{ $etat }}" {{ $etatDossier === $etat ? 'selected' : '' }}>{{ $etat }}</option>
                            @endforeach
                        </select>
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
                </div>
            </div>

            {{-- Groupe 3 : profil du foyer --}}
            <div class="bg-surface-2 rounded-lg p-3">
                <div class="text-[10px] font-bold text-ink-muted uppercase tracking-wide mb-2">🧾 Profil du foyer</div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[10.5px] font-semibold text-ink-muted mb-1">🎚️ Criticité ≥</label>
                        <input type="number" name="criticite_min" min="0" max="5" value="{{ request('criticite_min') }}"
                            class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface outline-none focus:border-accent">
                    </div>
                    <div>
                        <label class="block text-[10.5px] font-semibold text-ink-muted mb-1">🎚️ Criticité ≤</label>
                        <input type="number" name="criticite_max" min="0" max="5" value="{{ request('criticite_max') }}"
                            class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface outline-none focus:border-accent">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-[10.5px] font-semibold text-ink-muted mb-1">🚗 Se déplace</label>
                        <select name="se_deplace" class="w-full px-3 py-2 border border-ink-faint rounded-md text-[13px] bg-surface outline-none focus:border-accent">
                            <option value="">Indifférent</option>
                            <option value="1" {{ request('se_deplace') === '1' ? 'selected' : '' }}>Oui</option>
                            <option value="0" {{ request('se_deplace') === '0' ? 'selected' : '' }}>Non</option>
                        </select>
                    </div>
                    <div class="col-span-2 flex items-center gap-4 pt-1">
                        <label class="flex items-center gap-1.5 text-[12px] text-ink-muted cursor-pointer select-none">
                            <input type="checkbox" name="zakat_el_fitr" value="1" {{ request()->boolean('zakat_el_fitr') ? 'checked' : '' }} class="w-4 h-4 accent-accent">
                            🌙 Zakat El Fitr
                        </label>
                        <label class="flex items-center gap-1.5 text-[12px] text-ink-muted cursor-pointer select-none">
                            <input type="checkbox" name="sadaqa" value="1" {{ request()->boolean('sadaqa') ? 'checked' : '' }} class="w-4 h-4 accent-accent">
                            🤲 Sadaqa
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2 mt-3">
            <a href="{{ route('familles.index', ['etat_dossier' => '']) }}"
                class="px-3 py-2 border border-surface-border bg-surface hover:bg-surface-2 text-ink-muted text-[12.5px] font-semibold rounded-md transition-colors active:scale-95 no-underline min-h-[38px] flex items-center">
                ✕ Réinitialiser
            </a>
            <button type="submit"
                class="px-5 py-2 bg-accent hover:bg-accent-dark text-white text-[12.5px] font-semibold rounded-md transition-colors active:scale-95 min-h-[38px]">
                Filtrer
            </button>
        </div>
    </form>

    {{-- ── Puces de filtres actifs ── --}}
    @if(count($puces))
        <div class="flex flex-wrap items-center gap-2 mb-5 animate-fade-in-up">
            <span class="text-[10.5px] text-ink-muted uppercase tracking-wide font-semibold">Filtres actifs :</span>
            @foreach($puces as $puce)
                <a href="{{ $puce['href'] }}"
                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-accent/10 text-accent-dark text-[11.5px] font-semibold no-underline hover:bg-accent/20 active:scale-95 transition-all">
                    {{ $puce['label'] }}
                    <span class="text-[10px]">✕</span>
                </a>
            @endforeach
        </div>
    @endif

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
            'quartier' => ['label' => 'Quartier', 'triable' => false, 'defaut' => false],
            'nombre_adulte' => ['label' => 'Adultes', 'triable' => true, 'defaut' => false],
            'nombre_enfant' => ['label' => 'Enfants', 'triable' => true, 'defaut' => false],
            'criticite' => ['label' => 'Criticité', 'triable' => true, 'defaut' => true],
            'eligibilite' => ['label' => 'Éligibilité', 'triable' => true, 'defaut' => true],
            'se_deplace' => ['label' => 'Se déplace', 'triable' => true, 'defaut' => false],
            'est_hotel' => ['label' => 'Hôtel', 'triable' => true, 'defaut' => false],
            'langue' => ['label' => 'Langue', 'triable' => true, 'defaut' => false],
            'type_hebergement' => ['label' => 'Hébergement', 'triable' => true, 'defaut' => false],
            'hosted_by' => ['label' => 'Hébergé par', 'triable' => false, 'defaut' => false],
            'type_piece_identite' => ['label' => 'Pièce identité', 'triable' => true, 'defaut' => false],
            'type_activite' => ['label' => 'Activité', 'triable' => true, 'defaut' => false],
            'work_days' => ['label' => 'Jours travaillés', 'triable' => true, 'defaut' => false],
            'circonstances' => ['label' => 'Circonstances', 'triable' => false, 'defaut' => false],
            'ressentit' => ['label' => 'Ressenti', 'triable' => false, 'defaut' => false],
            'specificites' => ['label' => 'Spécificités', 'triable' => false, 'defaut' => false],
            'commentaire_dossier' => ['label' => 'Commentaire', 'triable' => false, 'defaut' => false],
            'created_at' => ['label' => 'Créé le', 'triable' => true, 'defaut' => false],
        ];
        $typeHebergementLabels = [
            'organisation' => "Structure d'accueil",
            'proche' => 'Chez un proche',
            'non' => 'Aucun',
        ];
        $typePieceIdentiteLabels = [
            'nationalite' => 'Nationalité',
            'titre_sejour' => 'Titre de séjour',
            'demande_asile' => "Demande d'asile",
            'autre' => 'Autre',
        ];
        $typeActiviteLabels = [
            'temps_plein' => 'Temps plein',
            'temps_partiel' => 'Temps partiel',
            'non' => 'Sans activité',
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
                                <td data-col="langue" {{ $colonnes['langue']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted">{{ \App\Models\Famille::LANGUES[$famille->langue] ?? $famille->langue }}</td>
                                <td data-col="type_hebergement" {{ $colonnes['type_hebergement']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted">{{ $typeHebergementLabels[$famille->type_hebergement] ?? ($famille->type_hebergement ?? '—') }}</td>
                                <td data-col="hosted_by" {{ $colonnes['hosted_by']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted max-w-[160px] truncate" title="{{ $famille->hosted_by }}">{{ $famille->hosted_by ?? '—' }}</td>
                                <td data-col="type_piece_identite" {{ $colonnes['type_piece_identite']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted">{{ $typePieceIdentiteLabels[$famille->type_piece_identite] ?? ($famille->type_piece_identite ?? '—') }}</td>
                                <td data-col="type_activite" {{ $colonnes['type_activite']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted">{{ $typeActiviteLabels[$famille->type_activite] ?? ($famille->type_activite ?? '—') }}</td>
                                <td data-col="work_days" {{ $colonnes['work_days']['defaut'] ? '' : 'hidden' }} class="px-4 py-2.5 text-ink-muted text-center">{{ $famille->work_days ?? '—' }}</td>
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
                {{ $familles->links() }}
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
         data-delete-doc-url-template="{{ route('familles.documents.destroy', ['id' => '__ID__', 'documentId' => '__DOC__']) }}">
    </div>

@endsection
