<?php
// app/Http/Controllers/Admin/Livraison/LiveBoardController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use App\Http\Controllers\Controller;
use App\Models\Campagne;
use App\Models\RouteLivraison;
use App\Services\RouteGenerationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Tableau de bord live admin/gestionnaire : toutes les tournées, tous les
 * incidents, réassignation, résolution — voir le prompt du 30/08/2026
 * §3.3/§4/§7. Couvre aussi le déclenchement du clustering/planification
 * des routes.
 *
 * Patch 3 : déclenchement du clustering (genererRoutes()) et lecture
 * (routes()/nonCouvertes()). La mutabilité des tournées après création
 * (ajout/retrait, redimensionnement, split/réassignation, tournée
 * personnalisée) et la gestion des route_incidents (bénévole absent,
 * capacité, chargement terminé, livraison ignorée) restent prévues pour
 * le Patch 4 — ce sont des actions qui présupposent des écrans de
 * chargement/réception déjà en place, pas seulement le moteur de
 * génération lui-même.
 */
class LiveBoardController extends Controller
{
    public function __construct(
        private readonly RouteGenerationService $generationService,
    ) {
    }

    public function index(): View
    {
        return view('livraison.a-venir', ['titre' => 'Tableau de bord livraison']);
    }

    /**
     * Déclenche le cycle complet clustering→assignation→TSP pour une
     * campagne — voir RouteGenerationService::genererPourCampagne().
     * Idempotent au sens où seules les livraisons encore non_assignee
     * sont considérées à chaque appel (relancer après une confirmation
     * tardive ne recrée pas les tournées déjà générées).
     */
    public function genererRoutes(Campagne $campagne): JsonResponse
    {
        try {
            $resultat = $this->generationService->genererPourCampagne($campagne);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, ...$resultat]);
    }

    public function routes(Campagne $campagne): JsonResponse
    {
        $routes = RouteLivraison::where('id_campagne', $campagne->id)
            ->with(['benevole', 'vehiculeType', 'etapes.livraison.famille:id,nom,prenom,adresse'])
            ->get();

        return response()->json($routes);
    }

    /**
     * Livraisons confirmées jamais couvertes par aucun créneau — voir
     * RouteGenerationService::livraisonsNonCouvertes() et le prompt §3.3
     * point 7 ("do not silently drop anyone — raise a visible admin-board
     * item").
     */
    public function nonCouvertes(Campagne $campagne): JsonResponse
    {
        return response()->json($this->generationService->livraisonsNonCouvertes($campagne));
    }
}
