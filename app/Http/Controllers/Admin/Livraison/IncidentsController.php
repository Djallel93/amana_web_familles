<?php
// app/Http/Controllers/Admin/Livraison/IncidentsController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use App\Http\Controllers\Controller;
use App\Http\Resources\CampagneResource;
use App\Models\Campagne;
use App\Models\RouteIncident;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Page « Incidents » d'une campagne (03/10/2026) — carte du hub : liste de
 * TOUS les incidents de la campagne avec leur statut (ouvert, fermé/ignoré,
 * résolu), filtre par statut (défaut : ouverts) et tri par date côté client
 * (le volume d'une campagne reste de l'ordre de quelques dizaines), clic sur
 * une ligne pour le détail. Résoudre / ignorer passent par les endpoints
 * existants de LiveBoardController (incidents.resoudre / incidents.ignorer).
 *
 * Le jalon chargement_termine (statut null, pas une alerte) n'est jamais
 * listé.
 */
class IncidentsController extends Controller
{
    public function index(Campagne $campagne): InertiaResponse
    {
        $incidents = RouteIncident::whereNotNull('statut')
            ->whereHas('route', fn ($q) => $q->where('id_campagne', $campagne->id))
            ->with(['route.benevole:id,nom,prenom', 'livraison.famille:id,nom,prenom', 'signalePar:id,nom,prenom'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (RouteIncident $i) => [
                'id' => $i->id,
                'type' => $i->type,
                'type_label' => RouteIncident::LABELS_TYPE[$i->type] ?? $i->type,
                'statut' => $i->statut,
                'description' => $i->description(),
                'guide' => $i->guide(),
                'notes' => $i->notes,
                'id_route' => $i->id_route,
                'chauffeur' => $i->route?->benevole ? trim("{$i->route->benevole->prenom} {$i->route->benevole->nom}") : null,
                'famille' => $i->livraison?->famille ? trim("{$i->livraison->famille->prenom} {$i->livraison->famille->nom}") : null,
                'signale_par' => $i->signalePar ? trim("{$i->signalePar->prenom} {$i->signalePar->nom}") : null,
                'created_at' => $i->created_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Livraison/CampagneIncidents', [
            'campagne' => new CampagneResource($campagne),
            'incidents' => $incidents,
            'retourUrl' => route('livraison.campagnes.show', $campagne),
            'resoudreUrlTemplate' => route('livraison.incidents.resoudre', ['incident' => '__ID__']),
            'ignorerUrlTemplate' => route('livraison.incidents.ignorer', ['incident' => '__ID__']),
        ]);
    }
}
