{{-- resources/views/livraison/suivi-livraison.blade.php --}}
{{-- Renommé depuis tableau-de-bord.blade.php (07/09/2026, prompt §6). --}}
@extends('layouts.app')

@section('title', 'Suivi livraison — AMANA Familles')

@section('content')
    <div class="max-w-5xl mx-auto py-8">
        {{--
            Retour (07/09/2026, prompt §6 : "like all other Livraison
            section add a return button, use same layout as current
            pesee") — bouton plein bg-ink, comme Pesee/Réception/Packaging/
            Chargement. Ce groupe de routes est role:gestionnaire
            uniquement (pas d'équipe_* ici, contrairement à ces écrans),
            donc pas besoin du fallback vers un point d'entrée "choisir" :
            on va directement vers la campagne si elle est connue
            (arrivée depuis CampagneDetail.vue), sinon vers la liste des
            campagnes (accès direct par la sidebar, sans campagne
            présélectionnée).
        --}}
        <a href="{{ $campagneSelectionnee ? route('livraison.campagnes.show', $campagneSelectionnee) : route('livraison.campagnes.index') }}"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
            ← Retour à la campagne
        </a>

        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Suivi livraison</h1>

        {{-- Reconstruit en Vue le 03/09/2026 (voir LiveBoard.vue et ses
             panneaux dans components/livraison/tableau-de-bord/) — seul
             écran du domaine livraison qui n'avait volontairement PAS été
             reconstruit dans les patches précédents (voir le commentaire
             qui occupait ce fichier avant ce patch) ; le reste des URLs
             est campagne-scopé ou porte sur un id connu seulement après
             chargement (route/étape/incident), donc passé sous forme de
             gabarits avec placeholder __CAMPAGNE__/__ID__/__ETAPE__ — même
             technique que urls.deleteDoc dans DetailPanel.vue — plutôt
             qu'un data-* par action.

             data-campagne-id (07/09/2026, prompt §6) : présélectionne le
             <select> de LiveBoard.vue quand la campagne est déjà connue
             (arrivée depuis CampagneDetail.vue) — voir onMounted() côté
             Vue. --}}
        <div id="vue-livraison-suivi-livraison" data-campagnes="{{ $campagnes->toJson() }}"
            data-campagne-id="{{ $campagneSelectionnee?->id }}"
            data-quartiers="{{ $quartiers->toJson() }}"
            data-villes="{{ $villes->toJson() }}"
            data-secteurs="{{ $secteurs->toJson() }}"
            data-organisations="{{ $organisations->toJson() }}"
            data-urls="{{ json_encode([
                'incidents' => route('livraison.campagnes.incidents', ['campagne' => '__CAMPAGNE__']),
                'routes' => route('livraison.campagnes.routes', ['campagne' => '__CAMPAGNE__']),
                'nonCouvertes' => route('livraison.campagnes.non-couvertes', ['campagne' => '__CAMPAGNE__']),
                // Ajoutés le 09/09/2026 (prompt de cette date §5) :
                'nonCouvertesTableau' => route('livraison.campagnes.non-couvertes-tableau', ['campagne' => '__CAMPAGNE__']),
                'statistiques' => route('livraison.campagnes.suivi-livraison-statistiques', ['campagne' => '__CAMPAGNE__']),
                'routeSupprimer' => route('livraison.routes.supprimer', ['route' => '__ID__']),
                'etapeStatut' => route('livraison.routes.etapes.statut', ['route' => '__ID__', 'etape' => '__ETAPE__']),
                'routesPersonnalisees' => route('livraison.routes.personnalisee', ['campagne' => '__CAMPAGNE__']),
                'incidentResoudre' => route('livraison.incidents.resoudre', ['incident' => '__ID__']),
                'routeAjouter' => route('livraison.routes.ajouter-livraison', ['route' => '__ID__']),
                'routeRetirer' => route('livraison.routes.retirer-livraison', ['route' => '__ID__', 'etape' => '__ETAPE__']),
                'routeReassigner' => route('livraison.routes.reassigner', ['route' => '__ID__']),
                'routeDiviser' => route('livraison.routes.diviser', ['route' => '__ID__']),
            ]) }}">
        </div>
    </div>
@endsection
