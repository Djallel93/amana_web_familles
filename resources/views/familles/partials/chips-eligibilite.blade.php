{{-- resources/views/familles/partials/chips-eligibilite.blade.php --}}
{{-- Puces Zakat El Fitr / Sadaqa — extrait le 10/09/2026 (Section A2).
     Attend $famille. --}}
<div class="flex gap-1 flex-wrap{{ $wrapJustifyEnd ?? false ? ' justify-end' : '' }}">
    @if($famille->zakat_el_fitr)
        <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-accent/10 text-accent-dark">Zakat El Fitr</span>
    @endif
    @if($famille->sadaqa)
        <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-700">Sadaqa</span>
    @endif
</div>
