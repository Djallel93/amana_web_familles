{{-- resources/views/livraison/tableau-de-bord.blade.php --}}
@extends('layouts.app')

@section('title', 'Tableau de bord livraison — AMANA Familles')

@section('content')
    <div class="max-w-5xl mx-auto py-8">
        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Tableau de bord livraison</h1>

        {{-- Reconstruit en Vue le 03/09/2026 (voir LiveBoard.vue et ses
             panneaux dans components/livraison/tableau-de-bord/) — seul
             écran du domaine livraison qui n'avait volontairement PAS été
             reconstruit dans les patches précédents (voir le commentaire
             qui occupait ce fichier avant ce patch) ; le reste des URLs
             est campagne-scopé ou porte sur un id connu seulement après
             chargement (route/étape/incident), donc passé sous forme de
             gabarits avec placeholder __CAMPAGNE__/__ID__/__ETAPE__ — même
             technique que urls.deleteDoc dans DetailPanel.vue — plutôt
             qu'un data-* par action. --}}
        <div id="vue-livraison-tableau-de-bord" data-campagnes="{{ $campagnes->toJson() }}"
            data-urls="{{ json_encode([
                'incidents' => route('livraison.campagnes.incidents', ['campagne' => '__CAMPAGNE__']),
                'routes' => route('livraison.campagnes.routes', ['campagne' => '__CAMPAGNE__']),
                'nonCouvertes' => route('livraison.campagnes.non-couvertes', ['campagne' => '__CAMPAGNE__']),
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
