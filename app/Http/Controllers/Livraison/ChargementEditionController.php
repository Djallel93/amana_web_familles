<?php
// app/Http/Controllers/Livraison/ChargementEditionController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use App\Models\EtapeRoute;
use App\Models\Livraison;
use App\Models\PersonneDesactivee;
use App\Models\RouteLivraison;
use App\Services\RouteMutationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Composition des tournées, depuis l'écran chargement (11/10/2026) : ajouter / retirer une
 * famille, changer de chauffeur, scinder, annuler. Ces gestes vivaient sur Suivi livraison
 * (LiveBoardController) ; l'équipe chargement, la plus proche de la notion de « ce qui part
 * dans quel véhicule », les porte désormais — même autorisation que le reste de l'écran
 * (can:gerer,route : équipe chargement, gestionnaire, admin).
 *
 * Règles (RouteMutationService, inchangées) :
 *   - une tournée chargée ou plus loin n'est modifiable que par un admin
 *     (refuserSiVerrouillee()), pour forcer une correction exceptionnelle ;
 *   - changer de chauffeur reste réservé aux admin/gestionnaires : la recherche de chauffeur
 *     (personnes/recherche) est une route gestionnaire, et l'équipe chargement seule n'a pas à
 *     parcourir la liste des bénévoles.
 */
class ChargementEditionController extends Controller
{
    public function __construct(private readonly RouteMutationService $mutation) {}

    public function ajouterFamille(Request $request, RouteLivraison $route): JsonResponse
    {
        $validator = Validator::make($request->all(), ['id_livraison' => 'required|integer|exists:livraisons,id']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $livraison = Livraison::findOrFail($request->integer('id_livraison'));

        if ($livraison->id_campagne !== $route->id_campagne) {
            return response()->json(['success' => false, 'message' => 'Cette famille appartient à une autre campagne.'], 422);
        }

        try {
            $this->mutation->refuserSiVerrouillee($route, Auth::user());
            $this->mutation->ajouterLivraison($route, $livraison);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    public function retirerFamille(RouteLivraison $route, EtapeRoute $etape): JsonResponse
    {
        if ($etape->id_route !== $route->id) {
            return response()->json(['success' => false, 'message' => "Cet arrêt n'appartient pas à cette tournée."], 422);
        }

        try {
            $this->mutation->refuserSiVerrouillee($route, Auth::user());
            $this->mutation->retirerLivraison($route, $etape);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    public function reassigner(Request $request, RouteLivraison $route): JsonResponse
    {
        $utilisateur = Auth::user();
        if (!$utilisateur?->isAdmin() && !$utilisateur?->isGestionnaire()) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'id_benevole' => ['required', 'integer', function (string $attribut, mixed $valeur, \Closure $echec): void {
                if (PersonneDesactivee::estDesactivee((int) $valeur)) {
                    $echec('Ce bénévole est désactivé.');
                }
            }],
            'id_vehicule_type' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $this->mutation->refuserSiVerrouillee($route, $utilisateur);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $this->mutation->reassigner($route, $request->integer('id_benevole'), $request->integer('id_vehicule_type'));

        return response()->json(['success' => true]);
    }

    public function diviser(RouteLivraison $route): JsonResponse
    {
        try {
            $this->mutation->refuserSiVerrouillee($route, Auth::user());
            $this->mutation->diviser($route);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Annule une tournée encore 'planifiee' — ou vide (plus aucune famille) et pas encore
     * chargée — pour la reconstruire ; voir RouteMutationService::supprimer().
     */
    public function supprimer(RouteLivraison $route): JsonResponse
    {
        try {
            $this->mutation->refuserSiVerrouillee($route, Auth::user());
            $this->mutation->supprimer($route);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }
}
