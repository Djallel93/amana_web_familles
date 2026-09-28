{{-- resources/views/livraison/a-venir.blade.php --}}
{{--
    Placeholder commun à tous les écrans du domaine livraison tant que
    leur logique métier n'est pas encore implémentée (Patch 1 = fondations
    uniquement : migrations, modèles, rôles, squelette de routes/contrôleurs
    — voir le prompt du 30/08/2026). Chaque contrôleur passe son propre
    $titre pour que la page reste identifiable pendant la transition.
--}}
@extends('layouts.app')

@section('title', ($titre ?? 'Livraison') . ' — AMANA Familles')

@section('content')
    <div class="max-w-2xl mx-auto py-16 text-center">
        <h1 class="text-xl font-semibold text-stone-700 dark:text-stone-200">
            {{ $titre ?? 'Cet écran' }}
        </h1>
        <p class="mt-2 text-stone-500 dark:text-stone-400">
            Cet écran du domaine livraison arrive dans une prochaine mise à jour.
        </p>
    </div>
@endsection
