{{-- resources/views/familles/partials/criticite.blade.php --}}
{{-- Échelle de 5 pastilles — extrait le 10/09/2026 (Section A2), même
     logique de seuils de couleur (>=4 rouge, >=2 ambre, sinon vert) que
     l'origine dans index.blade.php. Attend $famille. --}}
<div class="flex items-center gap-1" title="Criticité {{ $famille->criticite }}/5">
    @for ($i = 1; $i <= 5; $i++)
        <span class="w-2 h-2 rounded-full {{ $i <= $famille->criticite ? ($famille->criticite >= 4 ? 'bg-rose-500' : ($famille->criticite >= 2 ? 'bg-amber-500' : 'bg-emerald-500')) : 'bg-surface-3' }}"></span>
    @endfor
</div>
