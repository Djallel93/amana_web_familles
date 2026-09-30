{{-- resources/views/livraison/ma-route.blade.php --}}
@extends('layouts.app')

@section('title', ($modeAdmin ?? false) ? 'Vue chauffeur — AMANA Familles' : 'Ma tournée — AMANA Familles')

@section('content')
    {{--
        $modeAdmin (29/09/2026, prompt §6.2) : même écran que celui du
        chauffeur, ouvert par un admin/gestionnaire depuis le suivi
        livraison (MaRouteController::voirCommeChauffeur()). Seuls le titre
        et le lien de retour changent ; les actions sont les mêmes.
    --}}
    <div class="max-w-xl mx-auto py-8">
        @if($modeAdmin ?? false)
            @php $routeAdmin = $routes->first(); @endphp
            <a href="{{ route('livraison.suivi-livraison.index', $routeAdmin->id_campagne) }}"
                class="inline-block mb-3 text-[13px] text-accent underline">← Retour au suivi livraison</a>
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
            <div class="bg-surface border border-surface-border rounded-xl p-5 mb-5">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[13px] font-medium text-ink">
                        {{ $route->creneau ? \App\Support\Creneau::libelle($route->creneau) : 'Livraisons imposées' }}
                    </span>
                    <span class="text-[12px] text-ink-muted">{{ $route->statut }}</span>
                </div>

                @if($route->lien_maps)
                    <a href="{{ $route->lien_maps }}" target="_blank"
                        class="inline-block mb-4 text-[13px] text-accent underline">Ouvrir dans Google Maps</a>
                @endif

                <ol class="space-y-3">
                    @foreach($route->etapes as $etape)
                        <li class="flex items-start justify-between gap-3 border-t border-surface-border pt-3 first:border-t-0 first:pt-0">
                            <div>
                                <p class="text-[14px] font-medium text-ink">
                                    {{ $etape->ordre }}. {{ $etape->livraison->famille->prenom }} {{ $etape->livraison->famille->nom }}
                                </p>
                                <p class="text-[12px] text-ink-muted">{{ $etape->livraison->famille->adresse }}</p>
                                <p class="text-[12px] text-ink-muted">{{ $etape->livraison->famille->telephone }}</p>
                                <span class="inline-block mt-1 text-[11px] px-2 py-0.5 rounded-full
                                    @class([
                                        'bg-emerald-100 text-emerald-700' => $etape->statut === 'livree',
                                        'bg-rose-100 text-rose-700' => $etape->statut === 'ignoree',
                                        'bg-stone-100 text-stone-600' => $etape->statut === 'en_attente',
                                    ])">{{ $etape->statut }}</span>
                            </div>

                            @if($etape->statut === 'en_attente')
                                <div class="flex flex-col gap-1 shrink-0">
                                    <button type="button" onclick="confirmerEtape({{ $etape->id }})"
                                        class="text-[12px] px-3 py-1.5 rounded-lg bg-accent text-white">Livré</button>
                                    <button type="button" onclick="signalerIgnoree({{ $etape->id }})"
                                        class="text-[12px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted">Ignorer</button>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>

                @if(in_array($route->statut, ['en_cours', 'livraisons_terminees'], true))
                    {{--
                        Boutons de fin de tournée — voir le prompt du
                        03/09/2026. Grisés tant que toutes les étapes ne
                        sont pas traitées (livrée/ignorée) ; "Retour QG"
                        grisé tant que "Livraison terminé" n'a pas été
                        cliqué. Volontairement deux étapes distinctes : si
                        le bénévole ne tape jamais "Retour QG" (oubli, ou
                        il repart directement sur une autre tournée sans
                        repasser par le QG), admin/gestionnaire voit quand
                        même "livraisons_terminees" au tableau de bord.
                    --}}
                    <div class="flex gap-2 mt-4 pt-4 border-t border-surface-border">
                        <button type="button" onclick="livraisonTerminee({{ $route->id }})"
                            {{ $route->statut !== 'en_cours' || !$route->toutesEtapesTraitees() ? 'disabled' : '' }}
                            class="flex-1 text-[13px] px-3 py-2 rounded-lg bg-accent text-white disabled:opacity-40 disabled:cursor-not-allowed">
                            Livraison terminé
                        </button>
                        <button type="button" onclick="retourQg({{ $route->id }})"
                            {{ $route->statut !== 'livraisons_terminees' ? 'disabled' : '' }}
                            class="flex-1 text-[13px] px-3 py-2 rounded-lg bg-ink text-white disabled:opacity-40 disabled:cursor-not-allowed">
                            Retour QG
                        </button>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-[14px] text-ink-muted">Aucune tournée active pour le moment.</p>
        @endforelse
    </div>

    <script>
        const csrf = document.querySelector('#csrf-holder input[name="_token"]').value;

        // Appel POST commun : recharge la page en cas de succès, affiche
        // le message d'erreur du serveur (toast) sinon — avant, une erreur
        // 422 (ex. tournée pas la vôtre) rechargeait la page sans rien dire.
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
                return;
            }

            window.location.reload();
        }

        function confirmerEtape(id) {
            return poster(`/livraison/benevole/etapes/${id}/confirmer`);
        }

        async function signalerIgnoree(id) {
            // null = annulé (Annuler, Escape, clic à côté) : ne rien faire.
            const notes = await window.amanaPrompt({
                title: 'Ignorer cette livraison',
                label: 'Raison (optionnel)',
                confirmLabel: 'Ignorer la livraison',
            });
            if (notes === null) return;

            await poster(`/livraison/benevole/etapes/${id}/ignoree`, { notes });
        }

        function livraisonTerminee(routeId) {
            return poster(`/livraison/benevole/routes/${routeId}/livraison-terminee`);
        }

        function retourQg(routeId) {
            return poster(`/livraison/benevole/routes/${routeId}/retour-qg`);
        }
    </script>
@endsection
