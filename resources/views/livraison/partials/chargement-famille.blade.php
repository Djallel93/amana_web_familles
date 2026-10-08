{{-- resources/views/livraison/partials/chargement-famille.blade.php --}}
{{--
    Carte d'UNE famille confirmée qui n'est dans AUCUNE tournée (avant la
    génération, ou famille confirmée après coup) — voir
    ChargementController::construireFamillesSansTournee(). Remplace l'ancienne
    carte « En préparation » (chargement-preparation, 24/09/2026), toujours
    étiquetée « En préparation » et affichée seulement tant qu'aucune tournée
    n'existait : le statut est maintenant celui du conditionnement (Restante /
    En préparation / Prête) et la famille reste listée tant qu'elle n'a pas de
    tournée. Anonymisée comme Packaging : « Famille #{id} » (06/10/2026).
    Lecture seule : cet écran n'a aucune action sur le conditionnement.
    Attend : $livraison (avec ->famille chargée).
--}}
@php($etat = \App\Support\StatutChargement::pourFamille($livraison))
<div class="bg-surface border border-surface-border rounded-xl p-4 opacity-90" id="famille-{{ $livraison->id }}" data-etat="{{ $etat }}">
    <div class="flex items-center justify-between mb-1">
        <span class="text-[14px] font-medium text-ink">Famille #{{ $livraison->famille->id }}</span>
        <span class="text-[11px] font-medium px-2 py-0.5 rounded-full {{ \App\Support\StatutChargement::STYLES[$etat] }}">
            {{ \App\Support\StatutChargement::LIBELLES[$etat] }}
        </span>
    </div>
    <p class="text-[12px] text-ink-muted">
        @if($livraison->famille->etudiant)<span class="text-sky-600">· étudiant</span>@endif
        @if($livraison->famille->est_hotel)<span class="text-amber-600">· hôtel</span>@endif
        @if($livraison->famille->nombre_enfant > 0)<span class="text-violet-600">· {{ $livraison->famille->nombre_enfant }} enfant(s)</span>@endif
    </p>
</div>
