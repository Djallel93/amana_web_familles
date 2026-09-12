{{-- resources/views/familles/partials/badge-statut.blade.php --}}
{{-- Badge de statut — extrait le 10/09/2026 (Section A2). Attend $famille ;
     couleur depuis Famille::ETAT_COLORS (même source que le filtre Statut,
     voir son docblock sur le modèle). --}}
<span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ \App\Models\Famille::ETAT_COLORS[$famille->etat_dossier] ?? '' }}">
    {{ $famille->etat_dossier }}
</span>
