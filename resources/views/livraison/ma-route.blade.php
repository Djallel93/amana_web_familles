{{-- resources/views/livraison/ma-route.blade.php --}}
@extends('layouts.app')

@section('title', ($modeAdmin ?? false) ? 'Vue chauffeur — AMANA Familles' : 'Ma tournée — AMANA Familles')

@section('content')
    {{--
        $modeAdmin (29/09/2026, prompt §6.2) : même écran que celui du
        chauffeur, ouvert par un admin/gestionnaire depuis le suivi
        livraison (MaRouteController::voirCommeChauffeur()). Seuls le titre
        et le lien de retour changent ; les actions sont les mêmes.

        Refonte du 30/09/2026 : bouton « Je commence ma tournée » (seul
        moyen de démarrer, requis avant toute validation), lien Maps de la
        tournée complète, cartes de stats, un seul pill coloré par arrêt
        (statut de la tournée retiré), adresse cliquable + distance au QG,
        bloc téléphones, retour d'un arrêt ignoré à en_cours, fenêtre de fin
        de tournée (Oui/Non) et polling. Données préparées par
        App\Services\MaRouteVueService ($vues[id_route]).
    --}}
    @php
        $classesPill = [
            'en_attente' => 'bg-stone-100 text-stone-600',
            'en_cours' => 'bg-sky-100 text-sky-700',
            'livree' => 'bg-emerald-100 text-emerald-700',
            'ignoree' => 'bg-rose-100 text-rose-700',
        ];
        $libellesPill = ['en_attente' => 'En attente', 'en_cours' => 'En cours', 'livree' => 'Livrée', 'ignoree' => 'Ignorée'];
        $signatures = [];
    @endphp
    <div class="max-w-xl mx-auto py-8">
        @if($modeAdmin ?? false)
            @php $routeAdmin = $routes->first(); @endphp
            {{-- Même bouton plein bg-ink que Chargement/Packaging/Suivi livraison. --}}
            <a href="{{ route('livraison.suivi-livraison.index', $routeAdmin->id_campagne) }}"
                class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
                ← Retour au suivi livraison
            </a>
            <h1 class="font-heading text-xl font-semibold text-ink mb-1">Tournée #{{ $routeAdmin->id }} — vue chauffeur</h1>
            <p class="text-[13px] text-ink-muted mb-6">
                Chauffeur : {{ $routeAdmin->benevole ? $routeAdmin->benevole->prenom . ' ' . $routeAdmin->benevole->nom : 'non assigné' }}
                — vous agissez à sa place.
            </p>
        @else
            <h1 class="font-heading text-xl font-semibold text-ink mb-6">Ma tournée</h1>
        @endif
        <form id="csrf-holder">@csrf</form>

        @forelse($routes as $route)
            @php
                $vue = $vues[$route->id];
                $stats = $vue['stats'];
                $signatures[$route->id] = $vue['signature'];
                // 09/10/2026 : tant que la tournée n'a pas démarré, seul le bouton « Je commence ma
                // tournée » (et son explication) est affiché — ni lien Maps, ni stats, ni arrêts.
                $avantDemarrage = in_array($route->statut, ['planifiee', 'chargement', 'charge', 'packaging_annule'], true);
            @endphp
            <div class="bg-surface border border-surface-border rounded-xl p-5 mb-5">
                <p class="text-[13px] font-medium text-ink mb-3">
                    {{ $route->creneau ? \App\Support\Creneau::libelle($route->creneau) : 'Livraisons imposées' }}
                </p>

                {{-- Démarrage : actif uniquement quand le chargement est terminé (statut 'charge'). --}}
                @if($avantDemarrage)
                    <p class="text-[14px] text-ink text-center mb-3" id="texte-demarrer-{{ $route->id }}">
                        @if($route->statut === 'charge')
                            Le chargement est terminé : <strong>cliquez ici pour démarrer votre tournée</strong>.
                        @else
                            Disponible une fois le chargement terminé.
                        @endif
                    </p>
                    <button type="button" id="btn-demarrer-{{ $route->id }}" onclick="demarrerTournee({{ $route->id }})"
                        {{ $route->statut === 'charge' ? '' : 'disabled' }}
                        class="w-full text-[16px] font-semibold px-4 py-4 rounded-xl bg-accent text-white disabled:opacity-40 disabled:cursor-not-allowed">
                        Je commence ma tournée
                    </button>
                @endif

                @unless($avantDemarrage)
                @if($vue['lien_maps'])
                    <a href="{{ $vue['lien_maps'] }}" target="_blank" rel="noopener"
                        class="mt-3 flex items-center justify-center gap-2 text-[13px] font-medium text-accent border border-accent rounded-lg px-3 py-2">
                        🗺️ Voir toute la tournée sur Google Maps
                    </a>
                @endif

                {{-- Cartes de stats --}}
                <div class="grid grid-cols-2 gap-2 mt-4">
                    @foreach([
                        ['Colis à livrer', $stats['colis_restants'] . ' / ' . $stats['colis_total']],
                        ['Poids restant', $stats['poids_restant_kg'] . ' kg'],
                        ['Poids total', $stats['poids_total_kg'] . ' kg'],
                        ['Arrêts au total', $stats['arrets_total']],
                        ['Arrêts restants', $stats['arrets_restants']],
                        ['Livrés', $stats['arrets_livres']],
                        ['Ignorés', $stats['arrets_ignores']],
                        ['Distance', $stats['distance_km'] > 0 ? $stats['distance_km'] . ' km' : '—'],
                    ] as [$libelleStat, $valeurStat])
                        <div class="rounded-lg border border-surface-border bg-stone-50 px-3 py-2">
                            <p class="text-[11px] text-ink-muted">{{ $libelleStat }}</p>
                            <p class="text-[16px] font-semibold text-ink">{{ $valeurStat }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3 h-2 rounded-full bg-stone-100 overflow-hidden" title="Avancement {{ $stats['avancement_pct'] }} %">
                    <div class="h-full bg-emerald-500" style="width: {{ $stats['avancement_pct'] }}%"></div>
                </div>

                <ul class="space-y-3 mt-5">
                    @foreach($vue['etapes'] as $etape)
                        <li class="border-t border-surface-border pt-3 first:border-t-0 first:pt-0">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-[14px] font-medium text-ink">#{{ $etape['id_famille'] }} — {{ $etape['nom'] }}</p>
                                <span class="shrink-0 text-[12px] font-medium px-2.5 py-0.5 rounded-full {{ $classesPill[$etape['statut']] ?? $classesPill['en_attente'] }}">
                                    {{ $libellesPill[$etape['statut']] ?? $etape['statut'] }}
                                </span>
                            </div>

                            @if($etape['lien_adresse'])
                                <a href="{{ $etape['lien_adresse'] }}" target="_blank" rel="noopener"
                                    class="mt-1 inline-block text-[14px] font-semibold text-accent underline">📍 {{ $etape['adresse'] }}</a>
                            @else
                                <p class="mt-1 text-[14px] font-semibold text-ink">{{ $etape['adresse'] }}</p>
                            @endif
                            @if($etape['distance_km'] !== null)
                                <span class="text-[12px] text-ink-muted"> · {{ $etape['distance_km'] }} km du QG</span>
                            @endif

                            <div class="grid grid-cols-2 gap-2 mt-2">
                                <div class="rounded-lg bg-stone-50 px-2 py-1">
                                    <p class="text-[10.5px] text-ink-muted">Téléphone</p>
                                    @if($etape['telephone'])
                                        <a href="tel:{{ $etape['telephone'] }}" class="text-[13px] text-ink">{{ $etape['telephone'] }}</a>
                                    @endif
                                </div>
                                <div class="rounded-lg bg-stone-50 px-2 py-1">
                                    <p class="text-[10.5px] text-ink-muted">Téléphone bis</p>
                                    @if($etape['telephone_bis'])
                                        <a href="tel:{{ $etape['telephone_bis'] }}" class="text-[13px] text-ink">{{ $etape['telephone_bis'] }}</a>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center justify-between gap-3 mt-2">
                                <span class="text-[12px] text-ink-muted">📦 {{ $etape['nb_colis'] }} colis</span>

                                <div class="flex gap-2 shrink-0">
                                    @if($route->statut === 'en_cours' && in_array($etape['statut'], ['en_attente', 'en_cours'], true))
                                        <button type="button" onclick="confirmerEtape({{ $etape['id'] }}, {{ $route->id }})"
                                            class="text-[12px] px-3 py-1.5 rounded-lg bg-accent text-white">Livré</button>
                                        <button type="button" onclick="signalerIgnoree({{ $etape['id'] }}, {{ $route->id }})"
                                            class="text-[12px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted">Ignorer</button>
                                    @elseif($etape['statut'] === 'ignoree' && in_array($route->statut, ['en_cours', 'livraisons_terminees'], true))
                                        <button type="button" onclick="remettreEnCours({{ $etape['id'] }})"
                                            class="text-[12px] px-3 py-1.5 rounded-lg border border-accent text-accent">Annuler « ignorée »</button>
                                    @elseif($etape['statut'] === 'livree' && in_array($route->statut, ['en_cours', 'livraisons_terminees'], true))
                                        {{-- 09/10/2026 : « Livrée » cliqué par erreur — remet l'arrêt en cours. --}}
                                        <button type="button" onclick="annulerLivraison({{ $etape['id'] }})"
                                            class="text-[12px] px-3 py-1.5 rounded-lg border border-accent text-accent">Annuler « livrée »</button>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if(in_array($route->statut, ['en_cours', 'livraisons_terminees'], true))
                    {{--
                        Un seul bouton de fin (30/09/2026) : grisé tant que
                        tous les arrêts ne sont pas livrés/ignorés ; rouvre
                        la fenêtre Oui/Non si elle a été fermée. Tournée déjà
                        'livraisons_terminees' : il ne repose que la question
                        du retour au QG.
                    --}}
                    <div class="mt-4 pt-4 border-t border-surface-border">
                        <button type="button" onclick="finirTournee({{ $route->id }}, '{{ $route->statut }}')"
                            {{ $vue['tout_traite'] ? '' : 'disabled' }}
                            class="w-full text-[14px] font-medium px-3 py-2.5 rounded-lg bg-ink text-white disabled:opacity-40 disabled:cursor-not-allowed">
                            Livraison terminé
                        </button>
                    </div>
                @endif
                @endunless
            </div>
        @empty
            <p class="text-[14px] text-ink-muted">Aucune tournée active pour le moment.</p>
        @endforelse
    </div>

    <script>
        const csrf = document.querySelector('#csrf-holder input[name="_token"]').value;
        // Empreintes rendues avec la page, par tournée — comparées au polling.
        const SIGNATURES = @json($signatures);
        const POLL_MS = 20000;
        // true tant qu'une fenêtre (Ignorer / fin de tournée) est ouverte :
        // le polling ne recharge pas la page sous les pieds de l'utilisateur.
        let dialogueOuvert = false;

        // Appel POST commun : renvoie les données JSON en cas de succès, null
        // sinon (message d'erreur du serveur affiché en toast). L'appelant
        // décide du rechargement.
        async function poster(url, body = null) {
            const reponse = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                    ...(body ? { 'Content-Type': 'application/json' } : {}),
                },
                body: body ? JSON.stringify(body) : undefined,
            });

            if (!reponse.ok) {
                const donnees = await reponse.json().catch(() => ({}));
                const premiere = donnees.errors ? Object.values(donnees.errors).flat()[0] : null;
                window.amanaToast(premiere ?? donnees.message ?? "L'action a échoué.", 'error');
                return null;
            }

            return await reponse.json().catch(() => ({}));
        }

        async function demarrerTournee(routeId) {
            const bouton = document.getElementById(`btn-demarrer-${routeId}`);
            if (bouton) bouton.disabled = true;
            const resultat = await poster(`/livraison/benevole/routes/${routeId}/demarrer`);
            if (resultat) window.location.reload();
            else if (bouton) bouton.disabled = false;
        }

        async function confirmerEtape(id, routeId) {
            const resultat = await poster(`/livraison/benevole/etapes/${id}/confirmer`);
            if (!resultat) return;
            await apresAction(resultat, routeId);
        }

        async function signalerIgnoree(id, routeId) {
            dialogueOuvert = true;
            let notes;
            try {
                // null = annulé (Annuler, Escape, clic à côté) : ne rien faire.
                notes = await window.amanaPrompt({
                    title: 'Ignorer cette livraison',
                    label: 'Raison (optionnel)',
                    confirmLabel: 'Ignorer la livraison',
                });
            } finally {
                dialogueOuvert = false;
            }
            if (notes === null) return;

            const resultat = await poster(`/livraison/benevole/etapes/${id}/ignoree`, { notes });
            if (!resultat) return;
            await apresAction(resultat, routeId);
        }

        async function remettreEnCours(id) {
            const resultat = await poster(`/livraison/benevole/etapes/${id}/remettre-en-cours`);
            if (resultat) window.location.reload();
        }

        // « Livrée » cliqué par erreur (09/10/2026) : confirmation puis retour de l'arrêt à « en cours ».
        async function annulerLivraison(id) {
            dialogueOuvert = true;
            let confirme;
            try {
                confirme = await window.amanaConfirm({
                    title: 'Annuler la livraison ?',
                    message: 'Cet arrêt n\'est plus marqué « livré » : il repasse « en cours ».',
                    confirmLabel: 'Annuler la livraison',
                    cancelLabel: 'Garder « livrée »',
                });
            } finally {
                dialogueOuvert = false;
            }
            if (!confirme) return;

            const resultat = await poster(`/livraison/benevole/etapes/${id}/annuler-livraison`);
            if (resultat) window.location.reload();
        }

        // Dernier arrêt traité → propose tout de suite la fin de tournée.
        async function apresAction(resultat, routeId) {
            if (resultat.tout_traite) {
                await proposerFin(routeId);
                return;
            }
            window.location.reload();
        }

        // Fenêtre 1 : « tout est fait ? » ; si oui, fenêtre 2 : « retour au QG ? ».
        async function proposerFin(routeId) {
            dialogueOuvert = true;
            try {
                const fini = await window.amanaConfirm({
                    title: 'Tournée terminée ?',
                    message: 'Toutes les livraisons sont traitées (livrées ou ignorées). Confirmez-vous que la tournée est terminée ?',
                    confirmLabel: 'Oui',
                    cancelLabel: 'Non',
                });
                if (fini) {
                    const resultat = await poster(`/livraison/benevole/routes/${routeId}/livraison-terminee`);
                    if (resultat) await proposerRetourQg(routeId);
                }
            } finally {
                dialogueOuvert = false;
            }
            window.location.reload();
        }

        async function proposerRetourQg(routeId) {
            const retour = await window.amanaConfirm({
                title: 'Retour au QG',
                message: 'Revenez-vous au QG ?',
                confirmLabel: 'Oui',
                cancelLabel: 'Non',
            });
            if (retour) await poster(`/livraison/benevole/routes/${routeId}/retour-qg`);
        }

        // Bouton « Livraison terminé » : rouvre la fenêtre (ou seulement la
        // question du retour au QG si la tournée est déjà marquée terminée).
        async function finirTournee(routeId, statut) {
            if (statut === 'livraisons_terminees') {
                dialogueOuvert = true;
                try {
                    await proposerRetourQg(routeId);
                } finally {
                    dialogueOuvert = false;
                }
                window.location.reload();
                return;
            }
            await proposerFin(routeId);
        }

        // ── Polling (30/09/2026) : une action de l'admin ou un autre appareil
        // (démarrage, arrêt ignoré/rouvert…) apparaît sans rechargement manuel.
        // Même cadence (20 s) et même garde `document.hidden` que chargement.
        async function verifierEtat() {
            if (dialogueOuvert || document.hidden) return;
            for (const [routeId, signature] of Object.entries(SIGNATURES)) {
                try {
                    const reponse = await fetch(`/livraison/benevole/routes/${routeId}/etat`, { headers: { 'Accept': 'application/json' } });
                    if (!reponse.ok) continue;
                    const donnees = await reponse.json();
                    if (donnees.signature !== signature && !dialogueOuvert) {
                        window.location.reload();
                        return;
                    }
                } catch (e) {
                    // Échec réseau ponctuel : on réessaie au tick suivant.
                }
            }
        }

        setInterval(verifierEtat, POLL_MS);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) verifierEtat(); });
    </script>
@endsection
