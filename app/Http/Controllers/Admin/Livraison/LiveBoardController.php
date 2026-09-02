<?php
// app/Http/Controllers/Admin/Livraison/LiveBoardController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use App\Http\Controllers\Controller;
use App\Models\Campagne;
use App\Models\EtapeRoute;
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Services\RouteGenerationService;
use App\Services\RouteMutationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
        private readonly RouteMutationService $mutationService,
    ) {
    }

    public function index(): View
    {
        $campagnes = Campagne::orderByDesc('date_livraison')->get();

        return view('livraison.tableau-de-bord', ['campagnes' => $campagnes]);
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

    /**
     * Incidents ouverts de la campagne — voir matrice §4 ("Resolve" =
     * admin/gestionnaire uniquement, couvert par le rôle de ce groupe de
     * routes).
     */
    public function incidents(Campagne $campagne): JsonResponse
    {
        $incidents = RouteIncident::whereHas('route', fn ($q) => $q->where('id_campagne', $campagne->id))
            ->where('statut', 'ouvert')
            ->with(['route.benevole', 'livraison.famille:id,nom,prenom'])
            ->get();

        return response()->json($incidents);
    }

    /**
     * Résout un incident — pour benevole_absent, déclenche EN PLUS le
     * re-clustering scopé au pool orphelin de la tournée concernée (voir
     * le prompt §3.3 point 8 et
     * RouteGenerationService::relancerPourLivraisonsOrphelines()) ; pour
     * les autres types, marque simplement l'incident résolu (l'action de
     * fond — ex : ajuster une capacité signalée — se fait ailleurs dans
     * l'app, cet écran n'automatise que le cas benevole_absent qui a une
     * action de suivi mécanique et sans ambiguïté).
     */
    public function resoudreIncident(Request $request, RouteIncident $incident): JsonResponse
    {
        if ($incident->type === 'benevole_absent') {
            $idsLivraisonsOrphelines = $incident->route->etapes()
                ->where('statut', 'en_attente')
                ->pluck('id_livraison')
                ->filter()
                ->all();

            $campagne = $incident->route->campagne;

            $resultat = $this->generationService->relancerPourLivraisonsOrphelines(
                $campagne,
                $idsLivraisonsOrphelines,
                $incident->route->id_benevole,
            );

            $incident->update([
                'statut' => 'resolu',
                'notes' => trim(($incident->notes ?? '') . "\n[Re-cluster] " . json_encode($resultat)),
            ]);

            return response()->json(['success' => true, ...$resultat]);
        }

        $incident->update(['statut' => 'resolu']);

        return response()->json(['success' => true]);
    }

    // ── Mutabilité des tournées (voir le prompt §3.3) ───────────────────

    public function ajouterLivraison(Request $request, RouteLivraison $route): JsonResponse
    {
        $validator = Validator::make($request->all(), ['id_livraison' => 'required|integer|exists:livraisons,id']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $livraison = Livraison::findOrFail($request->input('id_livraison'));

        try {
            $route = $this->mutationService->ajouterLivraison($route, $livraison);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'route' => $route]);
    }

    public function retirerLivraison(RouteLivraison $route, EtapeRoute $etape): JsonResponse
    {
        try {
            $route = $this->mutationService->retirerLivraison($route, $etape);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'route' => $route]);
    }

    public function reassignerRoute(Request $request, RouteLivraison $route): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_benevole' => 'required|integer',
            'id_vehicule_type' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $route = $this->mutationService->reassigner($route, $request->input('id_benevole'), $request->input('id_vehicule_type'));

        return response()->json(['success' => true, 'route' => $route]);
    }

    public function diviserRoute(RouteLivraison $route): JsonResponse
    {
        try {
            $nouvelleRoute = $this->mutationService->diviser($route);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'nouvelle_route' => $nouvelleRoute]);
    }

    public function construireRoutePersonnalisee(Request $request, Campagne $campagne): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_benevole' => 'required|integer',
            'id_vehicule_type' => 'required|integer',
            'ids_livraisons' => 'required|array|min:1',
            'ids_livraisons.*' => 'integer|exists:livraisons,id',
            'creneau' => 'nullable|in:' . implode(',', \App\Support\Creneau::TOUS),
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $route = $this->mutationService->construirePersonnalisee(
                $campagne,
                $request->input('id_benevole'),
                $request->input('id_vehicule_type'),
                $request->input('ids_livraisons'),
                $request->input('creneau'),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'route' => $route]);
    }
}
