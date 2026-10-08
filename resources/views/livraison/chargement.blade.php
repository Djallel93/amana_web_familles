{{-- resources/views/livraison/chargement.blade.php --}}
@extends('layouts.app')

@section('title', 'Chargement — AMANA Familles')

@section('content')
    {{--
        Surbrillance d'une tournée qui vient d'apparaître par polling (Scénario 1).
        Animation d'un halo + fond vers les valeurs CALCULÉES de la carte (pas de
        keyframe "to") : le fond rose/ambre des cartes urgentes est préservé.
    --}}
    <style>
        @keyframes chargement-nouvelle {
            from { box-shadow: 0 0 0 4px rgb(16 185 129 / 0.75); background-color: rgb(209 250 229); }
            to { box-shadow: 0 0 0 0 rgb(16 185 129 / 0); }
        }
        .ligne-nouvelle { animation: chargement-nouvelle 5s ease-out 1; }
        @media (prefers-reduced-motion: reduce) {
            .ligne-nouvelle { animation: none; box-shadow: 0 0 0 3px rgb(16 185 129 / 0.75); }
        }
    </style>
    <div class="max-w-3xl mx-auto py-8">
        {{--
            Retour visible (07/09/2026, prompt §4.2) — même bouton plein
            bg-ink que Pesee/Réception/Packaging/Suivi livraison.
        --}}
        <a href="{{ $urlRetour }}"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
            ← Retour à la campagne
        </a>

        <div class="flex items-center justify-between gap-3 mb-6">
            <h1 class="font-heading text-xl font-semibold text-ink">Chargement — tournées prêtes</h1>
            {{--
                Planche d'étiquettes pour toute la campagne (07/09/2026,
                prompt §4.1) — voir ChargementController::etiquettesCampagne().
            --}}
            <a href="{{ route('livraison.chargement.etiquettes', $campagne) }}" target="_blank"
                class="text-[12.5px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted shrink-0 bg-white hover:bg-stone-50">
                🖨️ Étiquettes — toute la campagne
            </a>
        </div>
        <form id="csrf-holder">@csrf</form>

        {{--
            Cartes statistiques + filtres (09/09/2026, refaits le 06/10/2026) :
            statuts DÉRIVÉS du conditionnement des familles de chaque tournée
            (voir App\Support\StatutChargement) — Restantes (aucun colis prêt,
            packaging annulé compris), En préparation (au moins un colis prêt),
            Prêtes (tous les colis prêts, à charger), Chargées. Une tournée
            quitte ce périmètre dès que le bénévole démarre réellement sa
            tournée (statut 'en_cours', voir MaRouteController).
        --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
            <div class="bg-stone-50 border border-surface-border rounded-xl p-3">
                <p class="text-[11px] text-ink-muted uppercase tracking-wide">Restantes</p>
                <p id="stat-restantes" class="text-[20px] font-semibold text-ink">{{ $stats['restantes'] }}</p>
            </div>
            <div class="bg-amber-50 border border-amber-100 rounded-xl p-3">
                <p class="text-[11px] text-amber-700 uppercase tracking-wide">En préparation</p>
                <p id="stat-en-preparation" class="text-[20px] font-semibold text-amber-700">{{ $stats['en_preparation'] }}</p>
            </div>
            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3">
                <p class="text-[11px] text-emerald-700 uppercase tracking-wide">Prête(s)</p>
                <p id="stat-pretes" class="text-[20px] font-semibold text-emerald-700">{{ $stats['pretes'] }}</p>
            </div>
            <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-3">
                <p class="text-[11px] text-indigo-700 uppercase tracking-wide">Chargée(s)</p>
                <p id="stat-chargees" class="text-[20px] font-semibold text-indigo-700">{{ $stats['chargees'] }}</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2 mb-6" id="filtre-chargement">
            @foreach(['toutes' => 'Toutes', 'restantes' => 'Restantes', 'en_preparation' => 'En préparation', 'pretes' => 'Prêtes', 'chargees' => 'Chargées'] as $valeur => $libelle)
                <button type="button" data-valeur="{{ $valeur }}"
                    onclick="appliquerFiltreChargement('{{ $valeur }}')"
                    class="text-[12.5px] px-3 py-1.5 rounded-lg border {{ $filtreChargement === $valeur ? 'bg-accent text-white border-accent' : 'border-surface-border text-ink-muted' }}">
                    {{ $libelle }}
                </button>
            @endforeach
        </div>

        {{--
            Rappel « générez les routes » (refait le 06/10/2026) : affiché dès
            qu'il y a des familles confirmées et aucune tournée, sans attendre
            la fin du packaging, et rafraîchi par le polling (zone remplacée
            quand sa signature change). Voir ChargementController::rappelRoutes().
        --}}
        <div id="zone-rappel">{!! $rappelHtml !!}</div>

        <div class="space-y-3" id="liste-routes">
            @foreach($lignes as $ligne)
                {!! $ligne['html'] !!}
            @endforeach
        </div>
        {{--
            État vide — masqué/affiché par le polling (voir le script de polling plus
            bas) plutôt que rendu par un @forelse dans #liste-routes :
            la liste ne doit contenir que des cartes de tournée pour que
            la réconciliation par id reste triviale.
        --}}
        <p id="liste-vide" class="text-[14px] text-ink-muted {{ count($lignes) > 0 || count($familles) > 0 ? 'hidden' : '' }}">Aucune tournée à charger pour le moment.</p>

        {{--
            Familles confirmées pas encore dans une tournée (06/10/2026) —
            statut = conditionnement (Restante / En préparation / Prête), polled
            comme les tournées. Voir ChargementController::construireFamillesSansTournee().
            Suivent le filtre de l'écran (aucune avec « Chargées »).
        --}}
        <div id="section-familles" class="mt-6 {{ count($familles) > 0 ? '' : 'hidden' }}">
            <p class="text-[13px] text-ink-muted mb-2">Familles pas encore dans une tournée :</p>
            <div class="space-y-3" id="liste-familles">
                @foreach($familles as $famille)
                    {!! $famille['html'] !!}
                @endforeach
            </div>
        </div>
    </div>

    <script>
        const csrf = document.querySelector('#csrf-holder input[name="_token"]').value;

        async function poster(url, body = {}) {
            const reponse = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body),
            });
            return reponse.json();
        }

        // Filtre restantes/chargée (09/09/2026, prompt de cette date §4)
        // — même principe que appliquerFiltreConditionnement() côté
        // Packaging.
        function appliquerFiltreChargement(valeur) {
            const url = new URL(window.location.href);
            if (valeur === 'toutes') url.searchParams.delete('filtre_chargement');
            else url.searchParams.set('filtre_chargement', valeur);
            window.location.href = url.toString();
        }

        // Popup de confirmation avant de confirmer un chargement (06/10/2026) :
        // une confirmation notifie le chauffeur, autant éviter un clic par erreur.
        async function confirmerChargement(id) {
            const confirme = await window.amanaConfirm({
                title: 'Confirmer le chargement ?',
                message: 'Le chauffeur sera prévenu que son véhicule est chargé.',
                confirmLabel: 'Chargement confirmé',
            });
            if (!confirme) return;

            await occuper(id, async () => {
                const r = await poster(`/livraison/chargement/routes/${id}/confirmer`);
                if (!r.success) {
                    window.amanaToast(r.message || 'Impossible de confirmer le chargement.', 'error');
                    return;
                }

                // Ne retire plus la ligne du DOM (08/09/2026, prompt de cette
                // date §7.1/§7.2) : reste visible avec son statut "Chargée",
                // déplacée en bas de liste plutôt que supprimée — voir
                // ChargementController::construireListe() pour le même tri
                // au prochain chargement de page.
                const ligne = document.getElementById(`route-${id}`);
                if (!ligne) return;

                // Renommé 'en_cours' → 'charge' le 09/09/2026 (prompt §4).
                ligne.dataset.statut = 'charge';
                ligne.dataset.etat = 'chargee';
                ligne.classList.add('opacity-60');
                ligne.querySelector('.statut-route').textContent = 'Chargée';
                ligne.querySelector('.statut-route').className = 'statut-route text-[11px] font-medium px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700';
                ligne.querySelector('.actions-prete').style.display = 'none';
                ligne.querySelector('.actions-chargee').style.display = '';
                ligne.parentElement.appendChild(ligne);
            });
        }

        // Annulation d'un chargement confirmé par erreur (06/10/2026) : popup
        // de confirmation, puis la tournée redevient « Prête » et un incident
        // est ouvert côté serveur. Le rendu exact revient du polling, forcé
        // tout de suite pour ne pas attendre 20 s.
        async function annulerChargement(id) {
            const confirme = await window.amanaConfirm({
                title: 'Annuler le chargement ?',
                message: 'La tournée repasse « Prête » et un incident est ouvert pour les administrateurs.',
                confirmLabel: "Annuler le chargement",
                danger: true,
            });
            if (!confirme) return;

            await occuper(id, async () => {
                const r = await poster(`/livraison/chargement/routes/${id}/annuler-chargement`);
                if (!r.success) {
                    window.amanaToast(r.message || "Impossible d'annuler le chargement.", 'error');
                    return;
                }
                window.amanaToast('Chargement annulé — incident ouvert.', 'success');
            });

            modifieLocalement.delete(String(id));
            pollEnCours = false;
            await rafraichirListe(true);
        }

        // Popups natives remplacées le 29/09/2026 (prompt de cette date §5) :
        // prompt()/alert() → window.amanaPrompt()/amanaToast() (amana_shared_ui,
        // voir lib/dialogs.ts). amanaPrompt() résout à null quand l'utilisateur
        // annule ou clique à côté : on ne fait alors RIEN — avant, `prompt(...)
        // || ''` traitait l'annulation comme une saisie vide et créait quand
        // même l'incident (donc notification + email admin) sans action réelle.
        async function signalerAbsent(id) {
            const notes = await window.amanaPrompt({
                title: 'Bénévole absent',
                message: 'Les livraisons non chargées de cette tournée repasseront en attente.',
                label: 'Détails (optionnel)',
                confirmLabel: 'Signaler',
            });
            if (notes === null) return;

            await occuper(id, async () => {
                const r = await poster(`/livraison/chargement/routes/${id}/benevole-absent`, { notes });
                if (r.success) {
                    window.amanaToast('Incident signalé — les livraisons non chargées repassent en attente.', 'success');
                    document.getElementById(`route-${id}`).remove();
                } else {
                    window.amanaToast("Impossible de signaler l'incident.", 'error');
                }
            });
        }

        async function signalerCapacite(id) {
            const notes = await window.amanaPrompt({
                title: 'Problème de capacité',
                message: "Les administrateurs seront prévenus que le véhicule ne peut pas tout contenir.",
                label: 'Détails (optionnel)',
                confirmLabel: 'Signaler',
            });
            if (notes === null) return;

            await occuper(id, async () => {
                const r = await poster(`/livraison/chargement/routes/${id}/capacite`, { notes });
                if (r.success) {
                    window.amanaToast("Incident signalé à l'admin.", 'success');
                } else {
                    window.amanaToast("Impossible de signaler l'incident.", 'error');
                }
            });
        }

        // ── Polling de la liste (Scénario 1 du chantier "polling live") ────
        //
        // Toutes les 20s (POLL_MS, même cadence que le centre de
        // notifications — useNotifications.ts), demande à
        // ChargementController::liste() le même rendu que la page initiale
        // et réconcilie par id de tournée : ajoute les nouvelles cartes,
        // remplace celles dont la signature a changé, retire celles qui ont
        // quitté le périmètre (tournée démarrée, incident bénévole absent
        // traité depuis un autre appareil...), remet le tri du serveur et
        // met à jour les compteurs. Suspendu quand l'onglet est masqué.
        //
        // Deux garde-fous contre l'écrasement d'une action locale (voir
        // occuper()) :
        //  1. une carte marquée data-busy (POST en cours) n'est jamais
        //     touchée ;
        //  2. une réponse de polling PARTIE AVANT la fin d'une action
        //     locale sur une carte est ignorée pour cette carte — sinon un
        //     état serveur lu juste avant la confirmation la remettrait à
        //     "Prête à charger" une fois la confirmation terminée.
        const URL_LISTE = @json(route('livraison.chargement.liste', $campagne));
        const POLL_MS = 20000;
        const signatures = new Map(Object.entries(@json(collect($lignes)->pluck('sig', 'id'))));
        // Signatures des cartes « famille sans tournée » et du rappel (06/10/2026) :
        // mêmes principes que les tournées, sans les actions locales à protéger.
        const signaturesFamilles = new Map(Object.entries(@json(collect($familles)->pluck('sig', 'id'))));
        let signatureRappel = @json(md5($rappelHtml));
        // Toute tournée déjà affichée pendant cette visite de page — y compris
        // une carte retirée localement (bénévole absent) — n'est jamais
        // "nouvelle" : pas de surbrillance ni de son pour elle.
        const routesVues = new Set(signatures.keys());
        const modifieLocalement = new Map(); // id → Date.now() de fin de la dernière action locale
        let pollEnCours = false;

        async function occuper(id, action) {
            const ligne = document.getElementById(`route-${id}`);
            if (ligne) ligne.dataset.busy = '1';
            try {
                return await action();
            } finally {
                modifieLocalement.set(String(id), Date.now());
                if (ligne) delete ligne.dataset.busy;
            }
        }

        function creerLigne(html) {
            const modele = document.createElement('template');
            modele.innerHTML = html;
            return modele.content.firstElementChild;
        }

        // Son : un AudioContext ne peut démarrer qu'après un geste de
        // l'utilisateur (politique d'autoplay des navigateurs) — on le
        // crée/reprend au premier tap/clic/touche ; d'ici là, la
        // surbrillance seule signale une nouvelle tournée.
        let contexteAudio = null;
        function debloquerAudio() {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!contexteAudio && AC) contexteAudio = new AC();
            if (contexteAudio && contexteAudio.state === 'suspended') contexteAudio.resume();
        }
        ['click', 'touchend', 'keydown'].forEach((evenement) => document.addEventListener(evenement, debloquerAudio, { passive: true }));

        function jouerSon() {
            if (!contexteAudio || contexteAudio.state !== 'running') return;
            const debut = contexteAudio.currentTime;
            [880, 1174.66].forEach((frequence, i) => {
                const oscillateur = contexteAudio.createOscillator();
                const gain = contexteAudio.createGain();
                const t = debut + i * 0.16;
                oscillateur.type = 'sine';
                oscillateur.frequency.value = frequence;
                gain.gain.setValueAtTime(0.0001, t);
                gain.gain.exponentialRampToValueAtTime(0.25, t + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.15);
                oscillateur.connect(gain).connect(contexteAudio.destination);
                oscillateur.start(t);
                oscillateur.stop(t + 0.16);
            });
        }

        function mettreEnSurbrillance(ligne) {
            ligne.classList.add('ligne-nouvelle');
            const retirer = () => ligne.classList.remove('ligne-nouvelle');
            ligne.addEventListener('animationend', retirer, { once: true });
            setTimeout(retirer, 6000); // filet si animationend ne part pas (prefers-reduced-motion)
        }

        function appliquerListe(donnees, debutRequete) {
            const liste = document.getElementById('liste-routes');
            const idsServeur = new Set(donnees.routes.map((r) => String(r.id)));
            const ordre = [];
            const nouvelles = [];

            const protegee = (id, ligne) =>
                (ligne && ligne.dataset.busy) || (modifieLocalement.get(id) ?? 0) > debutRequete;

            for (const r of donnees.routes) {
                const id = String(r.id);
                let ligne = document.getElementById(`route-${id}`);
                let devenuePrete = false;

                if (protegee(id, ligne)) {
                    if (ligne) ordre.push(ligne);
                    continue;
                }

                if (!ligne) {
                    ligne = creerLigne(r.html);
                    liste.appendChild(ligne);
                } else if (signatures.get(id) !== r.sig) {
                    // Une tournée qui passe à « Prête » (dernier colis prêt, ou
                    // re-conditionnement après un packaging annulé, voir
                    // PackagingController::finaliserConditionnement()) est à
                    // signaler comme une nouvelle tournée à charger.
                    devenuePrete = r.etat === 'prete' && ligne.dataset.etat !== 'prete';
                    const remplacement = creerLigne(r.html);
                    ligne.replaceWith(remplacement);
                    ligne = remplacement;
                }
                signatures.set(id, r.sig);
                ordre.push(ligne);
                if (devenuePrete) nouvelles.push(ligne);

                if (!routesVues.has(id)) {
                    routesVues.add(id);
                    // Toutes les tournées sont maintenant listées dès leur
                    // création (06/10/2026) : seules celles découvertes déjà
                    // « Prêtes » méritent surbrillance et son.
                    if (r.etat === 'prete') nouvelles.push(ligne);
                }
            }

            // Retire les cartes sorties du périmètre (sauf celles protégées).
            liste.querySelectorAll(':scope > [id^="route-"]').forEach((ligne) => {
                const id = ligne.id.replace('route-', '');
                if (!idsServeur.has(id) && !protegee(id, ligne)) {
                    ligne.remove();
                    signatures.delete(id);
                }
            });

            // Remet l'ordre du serveur en ne déplaçant que ce qui est mal placé.
            let repere = liste.firstElementChild;
            for (const ligne of ordre) {
                if (ligne === repere) {
                    repere = repere.nextElementSibling;
                } else {
                    liste.insertBefore(ligne, repere);
                }
            }

            appliquerFamilles(donnees.familles);
            appliquerRappel(donnees.rappel);

            document.getElementById('stat-chargees').textContent = donnees.stats.chargees;
            document.getElementById('stat-restantes').textContent = donnees.stats.restantes;
            document.getElementById('stat-en-preparation').textContent = donnees.stats.en_preparation;
            document.getElementById('stat-pretes').textContent = donnees.stats.pretes;
            const aDesRoutes = liste.querySelector(':scope > [id^="route-"]') !== null;
            const aDesFamilles = document.getElementById('liste-familles').children.length > 0;
            document.getElementById('liste-vide').classList.toggle('hidden', aDesRoutes || aDesFamilles);

            if (nouvelles.length > 0) {
                nouvelles.forEach(mettreEnSurbrillance);
                jouerSon();
            }
        }

        // Familles sans tournée (statut = conditionnement) : réconciliation par
        // id, sans surbrillance. Le serveur applique déjà le filtre de l'écran.
        function appliquerFamilles(familles) {
            const conteneur = document.getElementById('liste-familles');
            const visibles = familles;
            const ids = new Set(visibles.map((f) => String(f.id)));
            const ordre = [];

            for (const f of visibles) {
                const id = String(f.id);
                let carte = document.getElementById(`famille-${id}`);
                if (!carte) {
                    carte = creerLigne(f.html);
                    conteneur.appendChild(carte);
                } else if (signaturesFamilles.get(id) !== f.sig) {
                    const remplacement = creerLigne(f.html);
                    carte.replaceWith(remplacement);
                    carte = remplacement;
                }
                signaturesFamilles.set(id, f.sig);
                ordre.push(carte);
            }

            conteneur.querySelectorAll(':scope > [id^="famille-"]').forEach((carte) => {
                const id = carte.id.replace('famille-', '');
                if (!ids.has(id)) {
                    carte.remove();
                    signaturesFamilles.delete(id);
                }
            });

            let repere = conteneur.firstElementChild;
            for (const carte of ordre) {
                if (carte === repere) {
                    repere = repere.nextElementSibling;
                } else {
                    conteneur.insertBefore(carte, repere);
                }
            }

            document.getElementById('section-familles').classList.toggle('hidden', conteneur.children.length === 0);
        }

        // Rappel « générez les routes » : remplacé seulement si sa signature a changé.
        function appliquerRappel(rappel) {
            if (!rappel || rappel.sig === signatureRappel) return;
            document.getElementById('zone-rappel').innerHTML = rappel.html;
            signatureRappel = rappel.sig;
        }

        async function rafraichirListe(forcer = false) {
            if ((pollEnCours && !forcer) || document.hidden) return;
            pollEnCours = true;
            const debutRequete = Date.now();
            try {
                // window.location.search : transmet filtre_chargement tel quel.
                const reponse = await fetch(URL_LISTE + window.location.search, { headers: { 'Accept': 'application/json' } });
                if (!reponse.ok) return;
                appliquerListe(await reponse.json(), debutRequete);
            } catch (e) {
                // Silencieux — comme useNotifications.ts : le prochain tick réessaie.
            } finally {
                pollEnCours = false;
            }
        }

        setInterval(() => rafraichirListe(), POLL_MS);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) rafraichirListe(); });
    </script>
@endsection
