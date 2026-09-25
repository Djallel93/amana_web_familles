{{-- resources/views/livraison/partials/chargement-preparation.blade.php --}}
{{--
    Carte d'UNE livraison PAS ENCORE conditionnée, affichée à la place des
    cartes de tournée quand aucune RouteLivraison n'existe pour la
    campagne — voir ChargementController::etatSansTournee() (24/09/2026,
    prompt de cette date §1.2). Lecture seule (pas de bouton) : cet écran
    n'a aucune action sur le conditionnement, seulement Packaging.
    Attend : $livraison (avec ->famille chargée).
--}}
<div class="bg-surface border border-surface-border rounded-xl p-4 opacity-80">
    <div class="flex items-center justify-between mb-1">
        <span class="text-[14px] font-medium text-ink">
            {{ $livraison->famille->prenom }} {{ $livraison->famille->nom }}
        </span>
        <span class="text-[11px] font-medium px-2 py-0.5 rounded-full bg-stone-100 text-ink-muted">En préparation</span>
    </div>
    <p class="text-[12px] text-ink-muted">
        @if($livraison->famille->etudiant)<span class="text-sky-600">· étudiant</span>@endif
        @if($livraison->famille->est_hotel)<span class="text-amber-600">· hôtel</span>@endif
        @if($livraison->famille->nombre_enfant > 0)<span class="text-violet-600">· {{ $livraison->famille->nombre_enfant }} enfant(s)</span>@endif
    </p>
</div>
