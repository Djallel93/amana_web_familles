{{-- resources/views/familles/partials/filtres.blade.php --}}
{{--
    Point de montage du panneau de filtres partagé — voir
    resources/js/components/familles/FamilleFiltresBar.vue et
    resources/js/components/livraison/shared/FamilleFilterPanel.vue.
    Remplace le <form method="GET"> + script d'autocomplétion propres à
    familles/index.blade.php depuis le 10/09/2026 (Section A3 du
    refactor) — même composant désormais utilisé par index.blade.php,
    nouvelles.blade.php et tout le domaine livraison.

    Attend :
    - $villes, $secteurs, $quartiers, $organisations : depuis
      FamillesController::listesFiltres()
    - $valeursFiltres : depuis FamillesController::valeursFiltres()
    - $avecStatut (bool) : true sur index.blade.php uniquement
    - $avecAutocompletion (bool) : true sur index.blade.php uniquement
    - $ouvertParDefaut (bool) : true sur index.blade.php (comportement
      d'origine du formulaire Blade), false ailleurs
    - $routeIndex (string route name, sans paramètres) : familles.index
      ou familles.nouvelles
--}}
<div id="vue-familles-filtres"
     data-villes="{{ $villes->toJson() }}"
     data-secteurs="{{ $secteurs->toJson() }}"
     data-quartiers="{{ $quartiers->toJson() }}"
     data-organisations="{{ $organisations->toJson() }}"
     data-valeurs="{{ json_encode($valeursFiltres) }}"
     data-avec-statut="{{ $avecStatut ? 'true' : 'false' }}"
     data-etats-disponibles="{{ $avecStatut ? json_encode(\App\Models\Famille::ETATS_MODIFIABLES) : '[]' }}"
     data-etat-couleurs="{{ $avecStatut ? json_encode(\App\Models\Famille::ETAT_COLORS) : '{}' }}"
     data-avec-autocompletion="{{ $avecAutocompletion ? 'true' : 'false' }}"
     data-suggestions-url="{{ $avecAutocompletion ? route('familles.recherche-suggestions') : '' }}"
     data-ouvert-par-defaut="{{ $ouvertParDefaut ? 'true' : 'false' }}"
     data-route-index="{{ route($routeIndex) }}"
     data-per-page="{{ request('per_page', \App\Models\Famille::PAGINATION_PAR_PAGE_DEFAUT) }}">
</div>
