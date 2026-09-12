{{-- resources/views/familles/partials/avatar.blade.php --}}
{{--
    Cercle d'initiales — extrait le 10/09/2026 (Section A2 du refactor) de
    index.blade.php, qui le dupliquait déjà lui-même entre la ligne du
    tableau desktop et la carte mobile. Attend $famille et $taille
    ('normal' pour le tableau desktop, 'grande' pour la carte mobile — seule
    différence entre les deux usages d'origine était la taille du cercle et
    du texte). Couleur dérivée de Famille::avatarStyle().
--}}
@php $avatarStyle = \App\Models\Famille::avatarStyle($famille->id); @endphp
<div class="{{ $taille === 'grande' ? 'w-9 h-9 text-[12px]' : 'w-8 h-8 text-[11px]' }} rounded-full {{ $avatarStyle['bg'] }} {{ $avatarStyle['text'] }} flex items-center justify-center font-bold flex-shrink-0">
    {{ strtoupper(mb_substr($famille->prenom, 0, 1) . mb_substr($famille->nom, 0, 1)) }}
</div>
