{{-- resources/views/livraison/packaging.blade.php --}}
@extends('layouts.app')

@section('title', 'Packaging — AMANA Familles')

@section('content')
    <div class="max-w-3xl mx-auto py-8">
        <a href="{{ $urlRetour }}"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
            ← Retour à la campagne
        </a>

        <div class="flex items-center justify-between mb-4">
            <h1 class="font-heading text-xl font-semibold text-ink">Packaging — file de priorité</h1>
            <a href="{{ route('livraison.packaging.feuille-preparation', $campagne) }}" target="_blank"
                class="text-[13px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted">
                🖨️ Feuille de préparation
            </a>
        </div>

        {{-- Sélecteurs campagne / journée (prompt §5.4) --}}
        <div class="flex gap-2 mb-4">
            <select id="select-campagne" class="flex-1 text-[13px] rounded-lg border border-surface-border px-3 py-2">
                @foreach($autresCampagnes as $c)
                    <option value="{{ route('livraison.packaging.index', $c) }}" @selected($c->id === $campagne->id)>
                        {{ ucfirst($c->type) }} — {{ $c->date_livraison->format('d/m/Y') }}
                    </option>
                @endforeach
            </select>
            @if($campagne->journees->count() > 1)
                <select id="select-journee" class="flex-1 text-[13px] rounded-lg border border-surface-border px-3 py-2"
                    onchange="location.href = this.value ? `{{ route('livraison.packaging.index', $campagne) }}?id_campagne_journee=${this.value}` : `{{ route('livraison.packaging.index', $campagne) }}`">
                    <option value="">Toutes les journées</option>
                    @foreach($campagne->journees as $j)
                        <option value="{{ $j->id }}" @selected($idCampagneJourneeSelectionnee === $j->id)>{{ $j->label ?? $j->date->format('d/m') }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        {{--
            Cartes statistiques + filtre restantes/en cours/terminées
            (08/09/2026, prompt de cette date §6.2/§6.3 ; "En cours"
            ajouté le 09/09/2026, prompt de cette date §4) — les
            comptages viennent de PackagingController::index() (portée
            campagne/journée SEULE, pas affectés par ce filtre, voir son
            docblock).
        --}}
        <div class="grid grid-cols-3 gap-3 mb-4">
            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3">
                <p class="text-[11px] text-emerald-700 uppercase tracking-wide">Terminées</p>
                <p id="stat-terminees" class="text-[20px] font-semibold text-emerald-700">{{ $stats['terminees'] }}</p>
            </div>
            <div class="bg-amber-50 border border-amber-100 rounded-xl p-3">
                <p class="text-[11px] text-amber-700 uppercase tracking-wide">En cours</p>
                <p id="stat-en-cours" class="text-[20px] font-semibold text-amber-700">{{ $stats['en_cours'] }}</p>
            </div>
            <div class="bg-stone-50 border border-surface-border rounded-xl p-3">
                <p class="text-[11px] text-ink-muted uppercase tracking-wide">Restantes</p>
                <p id="stat-restantes" class="text-[20px] font-semibold text-ink">{{ $stats['restantes'] }}</p>
            </div>
        </div>
        <div class="flex gap-2 mb-6" id="filtre-conditionnement">
            @foreach(['toutes' => 'Toutes', 'restantes' => 'Restantes', 'en_cours' => 'En cours', 'terminees' => 'Terminées'] as $valeur => $libelle)
                <button type="button" data-valeur="{{ $valeur }}"
                    onclick="appliquerFiltreConditionnement('{{ $valeur }}')"
                    class="text-[12.5px] px-3 py-1.5 rounded-lg border {{ $filtreConditionnement === $valeur ? 'bg-accent text-white border-accent' : 'border-surface-border text-ink-muted' }}">
                    {{ $libelle }}
                </button>
            @endforeach
        </div>

        {{--
            Couverture de la collecte (Scénario 4 du chantier "polling live",
            19/09/2026) — le poids collecté à la pesée suffit-il pour
            conditionner ce qui reste, au poids moyen actuel ? AFFICHAGE
            PASSIF : c'est à l'équipe de décider d'ajuster le poids moyen
            (bloc ci-dessous) puis de recalculer. Portée = campagne entière
            (voir CouvertureCollecteService). Rendu et rafraîchissement
            (toutes les 20s) entièrement côté JS, voir rafraichirCouverture().
        --}}
        {{--
            29/09/2026 (prompt de cette date §4) : l'ancien encart texte
            « ⚖️ Couverture de la collecte » est remplacé par de petites
            cartes, une statistique chacune, pour décider d'ajuster (ou non)
            les poids moyens d'un coup d'œil. Mêmes données
            (PackagingController::poids()), même rafraîchissement 20 s —
            seul le rendu change, voir afficherCouverture().
        --}}
        <div id="couverture-collecte" class="mb-4" aria-live="polite">
            <p class="text-[13px] font-medium text-ink-muted mb-2">⚖️ Couverture de la collecte</p>
            <div id="couverture-contenu" class="space-y-2"><p class="text-[12.5px] text-ink-muted">Chargement…</p></div>
        </div>

        {{--
            Poids moyen par type de livraison (prompt du 05/09/2026 §5.2) —
            mise à jour + recalcul manuel scopé aux livraisons pas encore
            conditionnées (voir CampagnesController::mettreAJourPoidsMoyen()/
            recalculerPoids()). Repliable : ce n'est pas un réglage qu'on
            touche à chaque visite de l'écran.
        --}}
        <details class="mb-6 bg-surface border border-surface-border rounded-xl">
            <summary class="cursor-pointer px-4 py-3 text-[13px] font-medium text-ink">⚖️ Poids moyen par type de livraison</summary>
            <div class="px-4 pb-4">
                <form id="form-poids-moyen" class="grid grid-cols-3 gap-3 mb-3">
                    @csrf
                    <label class="text-[12px] text-ink-muted">Normal (kg)
                        <input type="number" step="0.1" min="0" name="poids_moyen_kg" value="{{ $campagne->poids_moyen_kg }}"
                            class="mt-1 w-full text-[13px] rounded-lg border border-surface-border px-2 py-1.5">
                    </label>
                    <label class="text-[12px] text-ink-muted">Hôtel (kg)
                        <input type="number" step="0.1" min="0" name="poids_moyen_hotel_kg" value="{{ $campagne->poids_moyen_hotel_kg }}"
                            class="mt-1 w-full text-[13px] rounded-lg border border-surface-border px-2 py-1.5">
                    </label>
                    <label class="text-[12px] text-ink-muted">Étudiant (kg)
                        <input type="number" step="0.1" min="0" name="poids_moyen_etudiant_kg" value="{{ $campagne->poids_moyen_etudiant_kg }}"
                            class="mt-1 w-full text-[13px] rounded-lg border border-surface-border px-2 py-1.5">
                    </label>
                </form>
                <div class="flex items-center gap-2 mb-4">
                    <button type="button" onclick="enregistrerPoidsMoyen()" class="text-[12px] px-3 py-1.5 rounded-lg bg-accent text-white">Enregistrer</button>
                    <button type="button" onclick="recalculerPoids()" class="text-[12px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted"
                        title="Recalcule le poids des livraisons pas encore conditionnées avec les valeurs ci-dessus">
                        Recalculer les livraisons non conditionnées
                    </button>
                    <span id="poids-moyen-message" class="text-[12px] text-ink-muted"></span>
                </div>

                <p class="text-[12px] font-medium text-ink-muted mb-1">Historique des modifications</p>
                <ul id="historique-poids" class="text-[12px] text-ink-muted space-y-1">
                    @forelse($campagne->poidsMoyenHistorique as $h)
                        <li>{{ $h->horodatage->format('d/m/Y H:i') }} — {{ ucfirst($h->type) }} : {{ $h->ancienne_valeur }} → {{ $h->nouvelle_valeur }} kg
                            ({{ $h->loggePar->prenom ?? '' }} {{ $h->loggePar->nom ?? '' }})</li>
                    @empty
                        <li>Aucune modification enregistrée.</li>
                    @endforelse
                </ul>
            </div>
        </details>

        <form id="csrf-holder">@csrf</form>

        <div class="space-y-3" id="liste-livraisons">
            {{-- Cartes rendues par PackagingController::construireLignes() (partial packaging-ligne) : les mêmes que celles du polling (liste()). --}}
            @foreach($lignes as $ligne)
                {!! $ligne['html'] !!}
            @endforeach
            <p id="liste-vide" class="text-[14px] text-ink-muted {{ count($lignes) > 0 ? 'hidden' : '' }}">Aucune livraison en attente de conditionnement.</p>
        </div>
    </div>

    <script>
        const csrf = document.querySelector('#csrf-holder input[name="_token"]').value;

        document.getElementById('select-campagne').addEventListener('change', (e) => window.location.href = e.target.value);

        // Garde-fou du polling (30/09/2026) : aucune réponse de polling n'est
        // appliquée pendant (ou juste après) une action locale — une case
        // cochée ou une confirmation ouverte ne doit pas être écrasée par un
        // état serveur lu juste avant.
        let actionsEnCours = 0;
        let derniereAction = 0;
        async function enAction(fn) {
            actionsEnCours++;
            try {
                return await fn();
            } finally {
                actionsEnCours--;
                derniereAction = Date.now();
            }
        }
        const toggleColis = (idColis, idLivraison, coche) => enAction(() => basculerColis(idColis, idLivraison, coche));
        const toggleFamille = (idLivraison, coche) => enAction(() => basculerFamille(idLivraison, coche));

        async function basculerColis(idColis, idLivraison, coche) {
            const checkbox = document.querySelector(`.case-colis[data-id-colis="${idColis}"]`);
            const reponse = await fetch(`/livraison/packaging/colis/${idColis}/statut`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ statut: coche ? 'pret' : 'a_preparer' }),
            });
            let resultat;
            try {
                resultat = await reponse.json();
            } catch {
                resultat = { success: false };
            }
            if (!resultat.success) {
                // Échec serveur — y compris le refus §2.1 si la livraison
                // est déjà 'prete' (ne devrait normalement pas arriver ici,
                // les cases colis sont disabled à ce stade par
                // appliquerStatutConditionnement() ci-dessous, mais on
                // annule quand même le changement visuel par sécurité)
                // plutôt que de laisser la case dans un état qui ne
                // correspond plus à ce qui est réellement enregistré.
                if (checkbox) checkbox.checked = !coche;
                window.amanaToast(resultat.message ?? "Erreur lors de l'enregistrement de ce colis.", 'error');
                return;
            }

            appliquerStatutConditionnement(idLivraison, resultat.statut_conditionnement);
        }

        /**
         * Applique l'état affiché (case famille, verrouillage des cases
         * colis, badge) à partir du statut_conditionnement RENVOYÉ PAR LE
         * SERVEUR — remplace le 09/09/2026 (prompt de cette date §2.1)
         * l'ancienne synchroniserCaseFamille() qui le RECALCULAIT depuis
         * l'état des cases colis dans le DOM (insuffisant désormais : il y
         * a 3 états réels en_attente/en_cours/prete à distinguer, pas
         * juste "tous prêts ou non" — voir
         * Livraison::statutConditionnementDerive()). Verrouille aussi les
         * cases colis une fois 'prete' (même prompt) : jusque-là
         * cliquables même après coup (voir PackagingController::
         * marquerColisPret()), ce qui contournait annulerConditionnement()
         * sans confirmation ni notification à l'équipe chargement.
         */
        function appliquerStatutConditionnement(idLivraison, statutConditionnement) {
            const caseFamille = document.querySelector(`.case-famille[data-id-livraison="${idLivraison}"]`);
            const tousLesColis = document.querySelectorAll(`.case-colis[data-id-livraison="${idLivraison}"]`);
            const prete = statutConditionnement === 'prete';

            if (caseFamille) {
                caseFamille.checked = prete;
                caseFamille.dataset.statutConditionnement = statutConditionnement;
                caseFamille.classList.toggle('opacity-40', !prete);
                caseFamille.title = prete ? '' : 'Cochez d\'abord tous les colis de cette famille';
            }

            tousLesColis.forEach((c) => {
                c.disabled = prete;
                c.closest('label')?.classList.toggle('opacity-60', prete);
            });

            const badge = document.querySelector(`.statut-conditionnement[data-id-livraison="${idLivraison}"]`);
            if (badge) {
                const LIBELLES = { en_attente: 'Restante', en_cours: 'En cours', prete: 'Terminée' };
                const STYLES = {
                    en_attente: ['bg-stone-100', 'text-ink-muted'],
                    en_cours: ['bg-amber-100', 'text-amber-700'],
                    prete: ['bg-emerald-100', 'text-emerald-700'],
                };
                badge.textContent = LIBELLES[statutConditionnement] ?? LIBELLES.en_attente;
                Object.values(STYLES).flat().forEach((classe) => badge.classList.remove(classe));
                (STYLES[statutConditionnement] ?? STYLES.en_attente).forEach((classe) => badge.classList.add(classe));
            }
        }

        // Filtre restantes/terminées (08/09/2026, prompt de cette date
        // §6.2) — conserve id_campagne_journee s'il est déjà présent dans
        // l'URL.
        function appliquerFiltreConditionnement(valeur) {
            const url = new URL(window.location.href);
            if (valeur === 'toutes') url.searchParams.delete('filtre_conditionnement');
            else url.searchParams.set('filtre_conditionnement', valeur);
            window.location.href = url.toString();
        }

        /**
         * Cocher : n'est censé arriver qu'automatiquement une fois tous
         * les colis prêts (voir appliquerStatutConditionnement()) — un
         * clic manuel prématuré est annulé ici plutôt que laissé passer.
         * Décocher : demande confirmation puis appelle annuler() (prompt
         * du 05/09/2026 §5.3 : "get a confirmation screen before
         * validating uncheck").
         */
        async function basculerFamille(idLivraison, coche) {
            const caseFamille = document.querySelector(`.case-famille[data-id-livraison="${idLivraison}"]`);
            const tousLesColis = document.querySelectorAll(`.case-colis[data-id-livraison="${idLivraison}"]`);
            const tousPrets = Array.from(tousLesColis).every((c) => c.checked);

            if (coche) {
                if (!tousPrets && caseFamille) caseFamille.checked = false;
                return;
            }

            // confirm() natif remplacé le 29/09/2026 par amanaConfirm() (amana_shared_ui).
            if (!(await window.amanaConfirm({
                title: 'Reprendre les colis',
                message: "Reprendre les colis de cette famille pour correction ? L'équipe chargement sera avertie si la tournée était déjà prête à charger.",
                confirmLabel: 'Reprendre',
            }))) {
                if (caseFamille) caseFamille.checked = true;
                return;
            }

            const reponse = await fetch(`/livraison/packaging/${idLivraison}/annuler`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ confirme: true }),
            });
            const resultat = await reponse.json();
            if (!resultat.success) {
                window.amanaToast(resultat.message ?? "Erreur lors de l'annulation.", 'error');
                if (caseFamille) caseFamille.checked = true;
                return;
            }

            document.querySelectorAll(`.case-colis[data-id-livraison="${idLivraison}"]`).forEach((c) => c.checked = false);
            // annulerConditionnement() remet toujours à 'en_attente' (tous
            // les colis réinitialisés à 'a_preparer', voir le contrôleur) —
            // pas besoin d'un aller-retour supplémentaire pour connaître le
            // statut résultant.
            appliquerStatutConditionnement(idLivraison, 'en_attente');
        }

        // Synchronise l'état visuel de chaque case famille au chargement à
        // partir du statut_conditionnement déjà rendu par Blade
        // (data-statut-conditionnement, voir la case-famille ci-dessus) —
        // remplace le 09/09/2026 (prompt de cette date §2.1) l'ancienne
        // version qui le RECALCULAIT depuis l'état des cases colis dans le
        // DOM (synchroniserCaseFamille()) : superflu maintenant que Blade
        // rend directement le statut réel à 3 valeurs, et insuffisant pour
        // verrouiller correctement les cases colis d'une livraison 'prete'.
        document.querySelectorAll('.case-famille').forEach((c) =>
            appliquerStatutConditionnement(c.dataset.idLivraison, c.dataset.statutConditionnement));

        async function enregistrerPoidsMoyen() {
            const form = document.getElementById('form-poids-moyen');
            const donnees = Object.fromEntries(new FormData(form).entries());
            delete donnees._token;

            const reponse = await fetch(`{{ route('livraison.campagnes.poids-moyen', $campagne) }}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify(donnees),
            });
            const resultat = await reponse.json();
            const message = document.getElementById('poids-moyen-message');
            if (resultat.success) {
                message.textContent = 'Enregistré.';
                rafraichirCouverture(true);
                if (resultat.historique) {
                    const liste = document.getElementById('historique-poids');
                    liste.innerHTML = resultat.historique.map((h) =>
                        `<li>${new Date(h.horodatage).toLocaleString('fr-FR')} — ${h.type} : ${h.ancienne_valeur} → ${h.nouvelle_valeur} kg (${h.logge_par?.prenom ?? ''} ${h.logge_par?.nom ?? ''})</li>`
                    ).join('') || '<li>Aucune modification enregistrée.</li>';
                }
            } else {
                message.textContent = "Erreur d'enregistrement.";
            }
        }

        async function recalculerPoids() {
            if (!(await window.amanaConfirm({
                title: 'Recalculer les poids',
                message: 'Recalculer le poids des livraisons pas encore conditionnées avec les valeurs actuelles ?',
                confirmLabel: 'Recalculer',
            }))) return;

            const reponse = await fetch(`{{ route('livraison.campagnes.recalculer-poids', $campagne) }}`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            });
            const resultat = await reponse.json();
            document.getElementById('poids-moyen-message').textContent = resultat.success
                ? `${resultat.nombre_livraisons_recalculees} livraison(s) recalculée(s).`
                : "Erreur lors du recalcul.";
            if (resultat.success) rafraichirCouverture(true);
        }

        // ── Couverture de la collecte (Scénario 4) ─────────────────────────
        //
        // Encart en lecture seule alimenté par PackagingController::poids()
        // (voir CouvertureCollecteService pour les définitions). Relu toutes
        // les 20s — les pesées et les colis prêts changent lentement — et
        // immédiatement après "Enregistrer"/"Recalculer" (force = true).
        // Dernière requête gagnante : la réponse d'une requête dépassée par
        // une plus récente (ex. le tick parti avant un "Recalculer") est
        // écartée. Un échec ponctuel garde l'affichage en place ; l'encart
        // ne passe à "indisponible" que s'il n'a jamais rien affiché.
        const URL_COUVERTURE = @json(route('livraison.packaging.poids', $campagne));
        const COUVERTURE_POLL_MS = 20000;
        // Tons des cartes (un ton par carte, pas par encart entier comme avant).
        const CLASSES_CARTE = {
            neutre: 'border-surface-border bg-stone-50 text-ink',
            ok: 'border-emerald-100 bg-emerald-50 text-emerald-800',
            alerte: 'border-amber-200 bg-amber-50 text-amber-800',
            danger: 'border-rose-200 bg-rose-50 text-rose-800',
        };
        let jetonCouverture = 0;
        let couvertureEnCours = false;
        let couvertureAffichee = false;

        const formaterKg = (n, decimales = 1) => `${Number(n).toLocaleString('fr-FR', { maximumFractionDigits: decimales })} kg`;

        function creerCarte(libelle, valeur, detail, ton = 'neutre') {
            const carte = document.createElement('div');
            carte.className = `rounded-xl border px-3 py-2.5 ${CLASSES_CARTE[ton]}`;

            const l = document.createElement('p');
            l.className = 'text-[11px] uppercase tracking-wide opacity-70';
            l.textContent = libelle;

            const v = document.createElement('p');
            v.className = 'text-[20px] font-semibold leading-tight mt-0.5';
            v.textContent = valeur;

            carte.append(l, v);

            if (detail) {
                const dt = document.createElement('p');
                dt.className = 'text-[11.5px] opacity-80 mt-0.5';
                dt.textContent = detail;
                carte.append(dt);
            }

            return carte;
        }

        function creerNote(texte, ton) {
            const p = document.createElement('p');
            p.className = `rounded-lg border px-3 py-2 text-[12px] ${CLASSES_CARTE[ton]}`;
            p.textContent = texte;
            return p;
        }

        function afficherCouverture(d) {
            const elements = [];

            if (!d.collecte_demarree) {
                elements.push(creerNote('Aucune pesée enregistrée pour le moment — couverture non calculable.', 'neutre'));
            } else if (d.couverture === null) {
                const grille = document.createElement('div');
                grille.className = 'grid grid-cols-2 sm:grid-cols-4 gap-2';
                grille.append(
                    creerCarte('Collecté', formaterKg(d.collecte_kg)),
                    creerCarte('Déjà conditionné / livré', formaterKg(d.engage_kg)),
                    creerCarte('Reste à conditionner', formaterKg(d.reste_kg), 'rien à conditionner pour le moment'),
                );
                elements.push(grille);
            } else {
                const suffisante = d.couverture >= 1;
                const ecart = d.disponible_kg - d.reste_kg; // > 0 : surplus, < 0 : manque
                const pourcentage = Math.round(d.couverture * 100);

                // Ligne 1 — les quantités : de quoi décider si la collecte suffit.
                const quantites = document.createElement('div');
                quantites.className = 'grid grid-cols-2 sm:grid-cols-5 gap-2';
                quantites.append(
                    creerCarte('Collecté', formaterKg(d.collecte_kg)),
                    creerCarte('Déjà conditionné / livré', formaterKg(d.engage_kg)),
                    creerCarte('Reste à conditionner', formaterKg(d.reste_kg), 'aux poids moyens actuels'),
                    creerCarte('Couverture', `${pourcentage} %`, suffisante ? 'collecte suffisante' : 'collecte insuffisante', suffisante ? 'ok' : 'alerte'),
                    creerCarte(
                        suffisante ? 'Surplus' : 'Manque',
                        formaterKg(Math.abs(ecart)),
                        suffisante ? 'disponible après tout conditionner' : 'à trouver ou à répartir',
                        suffisante ? 'ok' : 'alerte',
                    ),
                );
                elements.push(quantites);

                // Ligne 2 — poids moyen réalisable vs actuel, par type : la
                // stat qui dit quel poids moyen ajuster (rouge = l'actuel
                // n'est pas tenable avec la collecte disponible).
                if (d.poids_moyen_realisable) {
                    const poids = document.createElement('div');
                    poids.className = 'grid grid-cols-1 sm:grid-cols-3 gap-2';
                    [['normal', 'Normal'], ['hotel', 'Hôtel'], ['etudiant', 'Étudiant']].forEach(([cle, libelle]) => {
                        const realisable = d.poids_moyen_realisable[cle];
                        if (realisable === null || realisable === undefined) return;
                        const actuel = d.poids_moyen_actuel[cle];
                        poids.append(creerCarte(
                            `Poids moyen ${libelle.toLowerCase()} réalisable`,
                            `≈ ${formaterKg(realisable, 2)}`,
                            actuel === null || actuel === undefined ? null : `actuel ${formaterKg(actuel, 2)}`,
                            actuel === null || actuel === undefined || realisable >= actuel ? 'ok' : 'alerte',
                        ));
                    });
                    if (poids.childElementCount > 0) elements.push(poids);
                }

                if (d.disponible_kg < 0) {
                    elements.push(creerNote(`Le poids déjà conditionné/livré dépasse la collecte pesée de ${formaterKg(-d.disponible_kg)}.`, 'danger'));
                }
            }

            if (d.recalcul_necessaire) {
                elements.push(creerNote("Les poids des livraisons n'ont pas été recalculés avec les poids moyens actuels (bouton « Recalculer » ci-dessous).", 'alerte'));
            }
            if (d.livraisons_exclues > 0) {
                elements.push(creerNote(`${d.livraisons_exclues} livraison(s) exclue(s) du calcul (données famille incohérentes).`, 'neutre'));
            }

            document.getElementById('couverture-contenu').replaceChildren(...elements);
            couvertureAffichee = true;
        }

        async function rafraichirCouverture(force = false) {
            if (!force && (couvertureEnCours || document.hidden)) return;
            const jeton = ++jetonCouverture;
            couvertureEnCours = true;
            try {
                const reponse = await fetch(URL_COUVERTURE, { headers: { 'Accept': 'application/json' } });
                if (!reponse.ok) throw new Error(String(reponse.status));
                const donnees = await reponse.json();
                if (jeton !== jetonCouverture) return;
                afficherCouverture(donnees);
            } catch (e) {
                if (jeton === jetonCouverture && !couvertureAffichee) {
                    document.getElementById('couverture-contenu').replaceChildren(
                        Object.assign(document.createElement('p'), { textContent: 'Couverture indisponible pour le moment.' }),
                    );
                }
            } finally {
                if (jeton === jetonCouverture) couvertureEnCours = false;
            }
        }

        rafraichirCouverture(true);
        setInterval(() => rafraichirCouverture(), COUVERTURE_POLL_MS);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) rafraichirCouverture(); });

        // ── Polling de la liste (30/09/2026) ────────────────────────────
        //
        // Jusqu'ici seul l'encart « Couverture de la collecte » se
        // rafraîchissait : cartes statistiques, familles, colis et statuts
        // ne bougeaient qu'après rechargement manuel. Même mécanique que
        // chargement.blade.php : toutes les 20 s, PackagingController::liste()
        // renvoie le même rendu que la page ; on réconcilie par id de
        // livraison (ajout, remplacement si la signature change, retrait,
        // tri serveur) et on met à jour les compteurs. Suspendu quand
        // l'onglet est masqué.
        const URL_LISTE = @json(route('livraison.packaging.liste', $campagne));
        const POLL_MS = 20000;
        const signatures = new Map(Object.entries(@json(collect($lignes)->pluck('sig', 'id'))));
        let pollEnCours = false;

        function creerLigne(html) {
            const modele = document.createElement('template');
            modele.innerHTML = html;
            return modele.content.firstElementChild;
        }

        function appliquerListe(donnees, debutRequete) {
            // Action locale en cours ou survenue depuis le départ de la
            // requête : réponse potentiellement périmée, le tick suivant corrige.
            if (actionsEnCours > 0 || derniereAction >= debutRequete) return;

            const liste = document.getElementById('liste-livraisons');
            const nouveauxIds = donnees.lignes.map((l) => String(l.id));

            liste.querySelectorAll(':scope > [id^="livraison-"]').forEach((el) => {
                const id = el.id.replace('livraison-', '');
                if (!nouveauxIds.includes(id)) {
                    el.remove();
                    signatures.delete(id);
                }
            });

            donnees.lignes.forEach((ligne) => {
                const id = String(ligne.id);
                const existante = document.getElementById(`livraison-${id}`);
                if (existante && signatures.get(id) === ligne.sig) return;
                const nouvelle = creerLigne(ligne.html);
                if (!nouvelle) return;
                if (existante) existante.replaceWith(nouvelle);
                else liste.appendChild(nouvelle);
                signatures.set(id, ligne.sig);
            });

            // Tri serveur (urgentes puis criticité) : le DOM n'est touché que si l'ordre diffère.
            const ordreActuel = Array.from(liste.querySelectorAll(':scope > [id^="livraison-"]')).map((el) => el.id.replace('livraison-', ''));
            if (ordreActuel.join(',') !== nouveauxIds.join(',')) {
                const vide = document.getElementById('liste-vide');
                nouveauxIds.forEach((id) => liste.insertBefore(document.getElementById(`livraison-${id}`), vide));
            }

            document.getElementById('stat-terminees').textContent = donnees.stats.terminees;
            document.getElementById('stat-en-cours').textContent = donnees.stats.en_cours;
            document.getElementById('stat-restantes').textContent = donnees.stats.restantes;
            document.getElementById('liste-vide').classList.toggle('hidden', nouveauxIds.length > 0);
        }

        async function rafraichirListe() {
            if (pollEnCours || document.hidden) return;
            pollEnCours = true;
            const debutRequete = Date.now();
            try {
                // window.location.search : transmet filtre_conditionnement et id_campagne_journee tels quels.
                const reponse = await fetch(URL_LISTE + window.location.search, { headers: { 'Accept': 'application/json' } });
                if (!reponse.ok) return;
                appliquerListe(await reponse.json(), debutRequete);
            } catch (e) {
                // Silencieux : le prochain tick réessaie.
            } finally {
                pollEnCours = false;
            }
        }

        setInterval(rafraichirListe, POLL_MS);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) rafraichirListe(); });
    </script>
@endsection
