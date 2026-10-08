{{-- resources/views/livraison/partials/chargement-rappel.blade.php --}}
{{--
    Rappel « générez les routes » de l'écran Chargement — voir
    ChargementController::rappelRoutes(). Rendu par la page initiale ET par
    l'endpoint de polling (liste()). Affiché dès qu'il y a des familles
    confirmées à livrer et aucune tournée, quel que soit l'état du
    conditionnement (06/10/2026). Les équipes sans droit de génération voient
    le texte seul.
    Attend : $campagne, $demarree (campagne démarrée ?), $peutGenerer (admin/gestionnaire).
--}}
<div class="bg-amber-50 border border-amber-200 rounded-xl px-5 py-6 mb-4 text-center" id="rappel-routes">
    <div class="text-[36px] leading-none mb-3" aria-hidden="true">🚚</div>
    @if($demarree)
        <p class="text-[16px] text-amber-900 font-semibold mb-3">Tournées pas encore générées</p>
        <p class="text-[13.5px] text-amber-800 leading-relaxed max-w-md mx-auto mb-5">
            Des familles sont confirmées mais aucune tournée n'a encore été créée pour cette campagne.
        </p>
        @if($peutGenerer)
            <a href="{{ route('livraison.campagnes.show', $campagne) }}?generer=1"
                class="inline-flex items-center gap-2 min-h-[44px] px-5 py-2.5 rounded-lg bg-accent hover:bg-accent-dark text-white text-[14px] font-semibold no-underline transition-colors active:scale-95">
                🗺️ Générer les routes
            </a>
        @else
            <p class="text-[13px] text-amber-700">Prévenez un admin/gestionnaire pour qu'il génère les routes depuis la page de la campagne.</p>
        @endif
    @else
        <p class="text-[16px] text-amber-900 font-semibold mb-3">Campagne pas encore démarrée</p>
        <p class="text-[13.5px] text-amber-800 leading-relaxed max-w-md mx-auto mb-5">
            Les tournées se génèrent une fois la campagne démarrée.
        </p>
        @if($peutGenerer)
            <a href="{{ route('livraison.campagnes.show', $campagne) }}"
                class="inline-flex items-center gap-2 min-h-[44px] px-5 py-2.5 rounded-lg bg-accent hover:bg-accent-dark text-white text-[14px] font-semibold no-underline transition-colors active:scale-95">
                ▶️ Démarrer la campagne
            </a>
        @else
            <p class="text-[13px] text-amber-700">Prévenez un admin/gestionnaire pour qu'il démarre la campagne.</p>
        @endif
    @endif
</div>
