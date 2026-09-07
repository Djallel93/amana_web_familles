<?php
// app/Http/Controllers/Livraison/ChargementController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use App\Models\Campagne;
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Services\QrCodeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Écran chargement — équipe_chargement confirme le "prêt à charger",
 * charge les véhicules, et signale les incidents (benevole_absent,
 * capacite, chargement_termine) — voir le prompt du 30/08/2026 §3.3
 * point 8 / §4 / §7. Voit les mêmes indicateurs de packaging (etudiant/
 * est_hotel/nombre_enfant) et note_besoins_speciaux que équipe_packaging,
 * en LECTURE SEULE, pour contexte uniquement.
 *
 * NE résout PAS les incidents (voir matrice §4 : "Resolve" est
 * admin/gestionnaire uniquement) — ce contrôleur ne fait que les lever.
 * La résolution (et le re-clustering scopé qui l'accompagne pour
 * benevole_absent) vit dans LiveBoardController.
 */
class ChargementController extends Controller
{
    public function __construct(
        private readonly QrCodeService $qrCode,
    ) {
    }

    /**
     * Point d'entrée sans campagne — voir le prompt du 05/09/2026 §4.1,
     * même raisonnement que ReceptionController::choisir()/PeseeController::choisir()/
     * PackagingController::choisir() : equipe_chargement n'avait aucune
     * entrée de menu vers cet écran.
     */
    public function choisir(): View
    {
        $campagnes = Campagne::whereIn('statut', ['preparation', 'en_cours'])
            ->orderByDesc('date_livraison')
            ->get();

        return view('livraison.choisir-poste', [
            'campagnes' => $campagnes,
            'titre' => 'Chargement — choisir une campagne',
            'routeIndex' => 'livraison.chargement.index',
            'avecJournee' => false,
        ]);
    }

    public function index(Campagne $campagne): View
    {
        $routes = RouteLivraison::where('id_campagne', $campagne->id)
            ->where('statut', 'chargement')
            ->with(['benevole', 'etapes.livraison.famille:id,nom,prenom,etudiant,est_hotel,nombre_enfant'])
            ->get();

        return view('livraison.chargement', [
            'campagne' => $campagne,
            'routes' => $routes,
            // Retour visible (07/09/2026, prompt §4.2) — même règle que
            // Packaging/Pesee/Réception : équipe_chargement n'a pas accès
            // à livraison.campagnes.show, repli sur le point d'entrée
            // "choisir".
            'urlRetour' => (auth()->user()->isAdmin() || auth()->user()->isGestionnaire())
                ? route('livraison.campagnes.show', $campagne)
                : route('livraison.chargement.choisir'),
        ]);
    }

    /**
     * Planche d'étiquettes QR pour TOUTES les familles confirmées de la
     * campagne, une page unique à découper (07/09/2026, prompt §4.1 :
     * "We don't print individual labels but rather a full sheet to cut
     * off each label") — remplace le bouton d'étiquette par famille
     * retiré de Packaging (§3.1). Un colis = une personne du foyer, même
     * convention que l'ancien PackagingController::etiquettes() (retiré
     * par ce même patch) dont ce code reprend la logique de génération
     * QR — verso : QR de secours vers la confirmation authentifiée du
     * bénévole quand la tournée existe déjà, sinon la mention
     * "réimprimer après" plutôt qu'un lien mort.
     *
     * Scopée aux familles confirmées (statut_contact = confirme), pas à
     * "en attente de chargement" : imprimée depuis Chargement mais pensée
     * comme la planche de LA campagne, à imprimer en une fois en amont
     * (typiquement pendant/après Packaging) plutôt que route par route.
     */
    public function etiquettesCampagne(Campagne $campagne): View
    {
        $livraisons = Livraison::where('id_campagne', $campagne->id)
            ->where('statut_contact', 'confirme')
            ->with(['famille:id,nom,prenom', 'etapesRoute'])
            ->get();

        $qrParLivraison = $livraisons->mapWithKeys(function (Livraison $livraison) {
            $etape = $livraison->etapesRoute->first();

            return [$livraison->id => $etape ? $this->qrCode->genererSvg(route('livraison.benevole.etapes.scan', $etape)) : null];
        });

        return view('livraison.etiquettes-campagne', [
            'campagne' => $campagne,
            'livraisons' => $livraisons,
            'qrParLivraison' => $qrParLivraison,
        ]);
    }

    public function confirmer(RouteLivraison $route): JsonResponse
    {
        $route->update(['statut' => 'en_cours']);

        RouteIncident::create([
            'id_route' => $route->id,
            'type' => 'chargement_termine',
            'signale_par' => auth()->id(),
            'statut' => null,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Bénévole absent — orpheline immédiatement les étapes non livrées de
     * cette tournée (remises non_assignee) et clôt la tournée elle-même
     * (statut = terminee, ce qui reste d'elle est un historique partiel) —
     * voir le prompt §3.3 point 8. Le re-clustering scopé au pool
     * orphelin n'est PAS déclenché ici automatiquement : c'est une action
     * admin/gestionnaire distincte (voir LiveBoardController), levée
     * seulement en signalant l'incident.
     */
    public function signalerBenevoleAbsent(Request $request, RouteLivraison $route): JsonResponse
    {
        $etapesNonLivrees = $route->etapes()->where('statut', 'en_attente')->with('livraison')->get();

        foreach ($etapesNonLivrees as $etape) {
            $etape->livraison?->update(['statut' => 'non_assignee']);
        }

        $route->update(['statut' => 'terminee']);

        $incident = RouteIncident::create([
            'id_route' => $route->id,
            'type' => 'benevole_absent',
            'signale_par' => auth()->id(),
            'statut' => 'ouvert',
            'notes' => $request->input('notes'),
        ]);

        return response()->json(['success' => true, 'id_incident' => $incident->id]);
    }

    public function signalerCapacite(Request $request, RouteLivraison $route): JsonResponse
    {
        $validator = Validator::make($request->all(), ['notes' => 'nullable|string|max:1000']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        RouteIncident::create([
            'id_route' => $route->id,
            'type' => 'capacite',
            'signale_par' => auth()->id(),
            'statut' => 'ouvert',
            'notes' => $request->input('notes'),
        ]);

        return response()->json(['success' => true]);
    }
}
