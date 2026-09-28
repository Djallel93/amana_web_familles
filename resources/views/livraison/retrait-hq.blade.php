{{-- resources/views/livraison/retrait-hq.blade.php --}}
{{--
    Écran Retrait QG — familles se_deplace (24/09/2026, prompt de cette
    date §2). Pendant de chargement.blade.php : même mécanique de polling
    (Scénario 1), adaptée à 4 statuts au lieu de 2 (voir
    RetraitHqController) et à des cartes de Livraison plutôt que de
    RouteLivraison (préfixe DOM 'retrait-' plutôt que 'route-').
--}}
@extends('layouts.app')

@section('title', 'Retrait QG — AMANA Familles')

@section('content')
    <style>
        @keyframes retrait-hq-nouvelle {
            from { box-shadow: 0 0 0 4px rgb(16 185 129 / 0.75); background-color: rgb(209 250 229); }
            to { box-shadow: 0 0 0 0 rgb(16 185 129 / 0); }
        }
        .ligne-nouvelle { animation: retrait-hq-nouvelle 5s ease-out 1; }
        @media (prefers-reduced-motion: reduce) {
            .ligne-nouvelle { animation: none; box-shadow: 0 0 0 3px rgb(16 185 129 / 0.75); }
        }
    </style>
    <div class="max-w-3xl mx-auto py-8">
        <a href="{{ $urlRetour }}"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
            ← Retour à la campagne
        </a>

        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Retrait QG — familles se déplaçant</h1>
        <form id="csrf-holder">@csrf</form>

        {{--
            Cartes statistiques — total/en préparation/prêtes/livrées/non
            livrées + "avec email" (Additional points 2 du prompt : "add
            a with emails stat card").
        --}}
        <div class="grid grid-cols-3 gap-3 mb-2">
            <div class="bg-stone-50 border border-surface-border rounded-xl p-3">
                <p class="text-[11px] text-ink-muted uppercase tracking-wide">Total</p>
                <p id="stat-total" class="text-[20px] font-semibold text-ink">{{ $stats['total'] }}</p>
            </div>
            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3">
                <p class="text-[11px] text-emerald-700 uppercase tracking-wide">Livrée(s)</p>
                <p id="stat-delivrees" class="text-[20px] font-semibold text-emerald-700">{{ $stats['delivrees'] }}</p>
            </div>
            <div class="bg-sky-50 border border-sky-100 rounded-xl p-3">
                <p class="text-[11px] text-sky-700 uppercase tracking-wide">Avec email (QR)</p>
                <p id="stat-avec-email" class="text-[20px] font-semibold text-sky-700">{{ $stats['avec_email'] }}</p>
            </div>
        </div>
        <div class="grid grid-cols-3 gap-3 mb-4">
            <div class="bg-stone-50 border border-surface-border rounded-xl p-3">
                <p class="text-[11px] text-ink-muted uppercase tracking-wide">En préparation</p>
                <p id="stat-en-preparation" class="text-[20px] font-semibold text-ink">{{ $stats['en_preparation'] }}</p>
            </div>
            <div class="bg-stone-50 border border-surface-border rounded-xl p-3">
                <p class="text-[11px] text-ink-muted uppercase tracking-wide">Prête(s)</p>
                <p id="stat-pretes" class="text-[20px] font-semibold text-ink">{{ $stats['pretes'] }}</p>
            </div>
            <div class="bg-rose-50 border border-rose-100 rounded-xl p-3">
                <p class="text-[11px] text-rose-700 uppercase tracking-wide">Non livrée(s)</p>
                <p id="stat-non-delivrees" class="text-[20px] font-semibold text-rose-700">{{ $stats['non_delivrees'] }}</p>
            </div>
        </div>

        <div class="flex gap-2 mb-4 flex-wrap" id="filtre-retrait-hq">
            @foreach([
                'toutes' => 'Toutes',
                'en_preparation' => 'En préparation',
                'prete' => 'Prêtes',
                'delivre' => 'Livrées',
                'non_delivre' => 'Non livrées',
            ] as $valeur => $libelle)
                <button type="button" data-valeur="{{ $valeur }}"
                    onclick="appliquerFiltreRetraitHq('{{ $valeur }}')"
                    class="text-[12.5px] px-3 py-1.5 rounded-lg border {{ $filtreRetraitHq === $valeur ? 'bg-accent text-white border-accent' : 'border-surface-border text-ink-muted' }}">
                    {{ $libelle }}
                </button>
            @endforeach
        </div>

        {{--
            Recherche client (§7 du prompt : repli "Famille.id" quand pas
            de QR) — filtre simple sur les cartes déjà affichées, pas un
            aller-retour serveur : le nom/dossier est déjà visible sur
            chaque carte (voir retrait-hq-ligne.blade.php).
        --}}
        <input type="search" id="recherche-retrait-hq" placeholder="Rechercher par nom ou n° de dossier…"
            class="w-full text-[13px] border border-surface-border rounded-lg px-3 py-2 mb-4"
            oninput="filtrerRecherche(this.value)">

        <div class="space-y-3" id="liste-retrait-hq">
            @foreach($lignes as $ligne)
                {!! $ligne['html'] !!}
            @endforeach
        </div>
        <p id="liste-vide" class="text-[14px] text-ink-muted {{ count($lignes) > 0 ? 'hidden' : '' }}">Aucune famille se_deplace confirmée pour le moment.</p>
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

        function appliquerFiltreRetraitHq(valeur) {
            const url = new URL(window.location.href);
            if (valeur === 'toutes') url.searchParams.delete('filtre_retrait_hq');
            else url.searchParams.set('filtre_retrait_hq', valeur);
            window.location.href = url.toString();
        }

        function filtrerRecherche(valeur) {
            const terme = valeur.trim().toLowerCase();
            document.querySelectorAll('#liste-retrait-hq > [id^="retrait-"]').forEach((ligne) => {
                ligne.style.display = !terme || ligne.textContent.toLowerCase().includes(terme) ? '' : 'none';
            });
        }

        function appliquerStatutLocal(id, statut, libelle, classe) {
            const ligne = document.getElementById(`retrait-${id}`);
            if (!ligne) return;
            ligne.dataset.statut = statut;
            const badge = ligne.querySelector('.statut-retrait-hq');
            badge.textContent = libelle;
            badge.className = `statut-retrait-hq text-[11px] font-medium px-2 py-0.5 rounded-full ${classe}`;
            ligne.querySelector('.flex.gap-2').style.display = 'none';
        }

        async function marquerLivre(id) {
            await occuper(id, async () => {
                const r = await poster(`/livraison/retrait-hq/livraisons/${id}/livre`);
                if (!r.success) { if (r.message) alert(r.message); return; }
                appliquerStatutLocal(id, 'delivre', 'Livré', 'bg-emerald-100 text-emerald-700');
            });
        }

        async function marquerNonLivre(id) {
            await occuper(id, async () => {
                const r = await poster(`/livraison/retrait-hq/livraisons/${id}/non-livre`);
                if (!r.success) { if (r.message) alert(r.message); return; }
                appliquerStatutLocal(id, 'non_delivre', 'Non livré', 'bg-rose-100 text-rose-700');
            });
        }

        // ── Polling (même mécanique que chargement.blade.php — voir son
        // docblock pour le raisonnement détaillé de la réconciliation) ──
        const URL_LISTE = @json(route('livraison.retrait-hq.liste', $campagne));
        const POLL_MS = 20000;
        const signatures = new Map(Object.entries(@json(collect($lignes)->pluck('sig', 'id'))));
        const lignesVues = new Set(signatures.keys());
        const modifieLocalement = new Map();
        let pollEnCours = false;

        async function occuper(id, action) {
            const ligne = document.getElementById(`retrait-${id}`);
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

        function mettreEnSurbrillance(ligne) {
            ligne.classList.add('ligne-nouvelle');
            const retirer = () => ligne.classList.remove('ligne-nouvelle');
            ligne.addEventListener('animationend', retirer, { once: true });
            setTimeout(retirer, 6000);
        }

        function appliquerListe(donnees, debutRequete) {
            const liste = document.getElementById('liste-retrait-hq');
            const idsServeur = new Set(donnees.lignes.map((l) => String(l.id)));
            const ordre = [];
            const nouvelles = [];

            const protegee = (id, ligne) =>
                (ligne && ligne.dataset.busy) || (modifieLocalement.get(id) ?? 0) > debutRequete;

            for (const l of donnees.lignes) {
                const id = String(l.id);
                let ligne = document.getElementById(`retrait-${id}`);

                if (protegee(id, ligne)) {
                    if (ligne) ordre.push(ligne);
                    continue;
                }

                if (!ligne) {
                    ligne = creerLigne(l.html);
                    liste.appendChild(ligne);
                } else if (signatures.get(id) !== l.sig) {
                    const remplacement = creerLigne(l.html);
                    ligne.replaceWith(remplacement);
                    ligne = remplacement;
                }
                signatures.set(id, l.sig);
                ordre.push(ligne);

                if (!lignesVues.has(id)) {
                    lignesVues.add(id);
                    if (l.statut === 'prete') nouvelles.push(ligne);
                }
            }

            liste.querySelectorAll(':scope > [id^="retrait-"]').forEach((ligne) => {
                const id = ligne.id.replace('retrait-', '');
                if (!idsServeur.has(id) && !protegee(id, ligne)) {
                    ligne.remove();
                    signatures.delete(id);
                }
            });

            let repere = liste.firstElementChild;
            for (const ligne of ordre) {
                if (ligne === repere) repere = repere.nextElementSibling;
                else liste.insertBefore(ligne, repere);
            }

            document.getElementById('stat-total').textContent = donnees.stats.total;
            document.getElementById('stat-delivrees').textContent = donnees.stats.delivrees;
            document.getElementById('stat-avec-email').textContent = donnees.stats.avec_email;
            document.getElementById('stat-en-preparation').textContent = donnees.stats.en_preparation;
            document.getElementById('stat-pretes').textContent = donnees.stats.pretes;
            document.getElementById('stat-non-delivrees').textContent = donnees.stats.non_delivrees;
            document.getElementById('liste-vide').classList.toggle('hidden', liste.querySelector(':scope > [id^="retrait-"]') !== null);

            if (nouvelles.length > 0) nouvelles.forEach(mettreEnSurbrillance);
        }

        async function rafraichirListe() {
            if (pollEnCours || document.hidden) return;
            pollEnCours = true;
            const debutRequete = Date.now();
            try {
                const reponse = await fetch(URL_LISTE + window.location.search, { headers: { 'Accept': 'application/json' } });
                if (!reponse.ok) return;
                appliquerListe(await reponse.json(), debutRequete);
            } catch (e) {
                // Silencieux, prochain tick réessaie.
            } finally {
                pollEnCours = false;
            }
        }

        setInterval(rafraichirListe, POLL_MS);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) rafraichirListe(); });
    </script>
@endsection
