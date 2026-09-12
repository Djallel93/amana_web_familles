<?php
// app/Http/Controllers/Admin/Livraison/LiveBoardController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use Amana\Shared\Models\Secteur;
use Amana\Shared\Models\Ville;
use Amana\Shared\Services\NotificationCenterService;
use App\Http\Controllers\Controller;
use App\Http\Resources\RouteIncidentResource;
use App\Http\Resources\RouteLivraisonResource;
use App\Models\Campagne;
use App\Models\EtapeRoute;
use App\Models\Livraison;
use App\Models\Organisation;
use App\Models\Quartier;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Services\LivraisonGenerationService;
use App\Services\RouteGenerationService;
use App\Services\RouteMutationService;
use App\Support\FamilleFilters;
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
        private readonly NotificationCenterService $notificationCenter,
        private readonly LivraisonGenerationService $livraisonGenerationService,
    ) {
    }

    /**
     * {campagne} optionnel (07/09/2026, prompt §6, écran renommé
     * 'suivi-livraison') — préremplit le <select> campagne de
     * LiveBoard.vue quand on arrive depuis CampagneDetail.vue
     * (/livraison/suivi-livraison/{campagne}), sans rien changer pour
     * l'accès direct par la sidebar (aucune campagne connue à l'avance).
     */
    public function index(?Campagne $campagne = null): View
    {
        $campagnes = Campagne::orderByDesc('date_livraison')->get();

        // quartiers/villes/secteurs/organisations (09/09/2026, prompt de
        // cette date §5.1.3) : mêmes référentiels que CampagnesController::
        // show(), nécessaires ici pour FamilleFilterPanel.vue dans
        // BuildRouteFlow.vue (table "Livraisons à inclure" désormais
        // filtrable comme les familles éligibles).
        return view('livraison.suivi-livraison', [
            'campagnes' => $campagnes,
            'campagneSelectionnee' => $campagne,
            'quartiers' => Quartier::orderBy('nom')->get(['id', 'nom', 'id_secteur']),
            'villes' => Ville::orderBy('nom')->get(['id', 'nom']),
            'secteurs' => Secteur::orderBy('nom')->get(['id', 'nom', 'id_ville']),
            'organisations' => Organisation::actifs()->orderBy('nom')->get(['id', 'nom']),
        ]);
    }

    /**
     * Déclenche le cycle complet clustering→assignation→TSP pour UNE
     * journée d'une campagne — voir
     * RouteGenerationService::genererPourCampagne(). Idempotent au sens
     * où seules les livraisons encore non_assignee sont considérées à
     * chaque appel (relancer après une confirmation tardive ne recrée pas
     * les tournées déjà générées).
     *
     * id_campagne_journee requis depuis le 05/09/2026 (voir
     * RouteGenerationService) — la campagne a toujours au moins une
     * journée (CampagnesController::store()), le sélecteur de
     * CampagneDetail.vue l'envoie toujours, y compris pour une campagne
     * mono-jour (une seule option, choisie silencieusement côté Vue).
     */
    public function genererRoutes(Request $request, Campagne $campagne): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_campagne_journee' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $journee = $campagne->journees()->findOrFail($request->integer('id_campagne_journee'));

        // Ajouté le 09/09/2026 (prompt de cette date §1.5) : distinct de la
        // vérification "reste à contacter" ci-dessous — sans lui, une
        // journée sans AUCUNE famille ajoutée passait ce test par le vide
        // (aucune ligne 'a_contacter' puisqu'aucune ligne du tout) et
        // laissait genererPourCampagne() tourner pour rien
        // ("0 livraison créé", inoffensif mais confus — voir le prompt).
        // Revalidé ici côté serveur pour la même raison que le test
        // suivant : le bouton grisé côté Vue (CampagneDetail.vue) ne doit
        // pas être la seule protection.
        $aucuneLivraison = !Livraison::where('id_campagne_journee', $journee->id)->exists();
        if ($aucuneLivraison) {
            return response()->json([
                'success' => false,
                'message' => "Aucune famille n'a été ajoutée pour cette journée.",
            ], 422);
        }

        // Voir le prompt du 05/09/2026 §1.5 : le bouton de lancement a été
        // déplacé sur l'écran Suivi des contacts, avec pour condition que
        // plus aucune famille de CETTE journée ne soit encore à
        // statut_contact = 'a_contacter'. Revalidé ici côté serveur (pas
        // seulement le bouton grisé côté Vue) : un appel direct à cet
        // endpoint ne doit pas pouvoir contourner la règle.
        $resteAContacter = Livraison::where('id_campagne_journee', $journee->id)
            ->where('statut_contact', 'a_contacter')
            ->exists();
        if ($resteAContacter) {
            return response()->json([
                'success' => false,
                'message' => "Certaines familles de cette journée n'ont pas encore été contactées.",
            ], 422);
        }

        try {
            $resultat = $this->generationService->genererPourCampagne($campagne, $journee);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, ...$resultat]);
    }

    /**
     * Cartes statistiques de Suivi livraison (09/09/2026, prompt de cette
     * date §5.2.4 : "Add statistique cards at the top like the rest of the
     * pages") — même esprit que StatistiquesController/PackagingController
     * (comptages simples, pas de pagination). 'annulee' compté à part
     * (tournées actives = tout sauf annulee) pour ne pas fausser
     * l'avancement avec des tournées qui ne seront jamais exécutées — voir
     * RouteMutationService::supprimer().
     *
     * L'avancement % ne porte que sur les arrêts avec une livraison
     * associée (id_livraison non null) : un arrêt "retour QG" n'est pas
     * une famille à livrer, l'inclure fausserait le taux à la baisse.
     */
    public function statistiques(Campagne $campagne): JsonResponse
    {
        $routes = RouteLivraison::where('id_campagne', $campagne->id)->select('statut')->get();
        $routesParStatut = $routes->countBy('statut');

        $etapes = EtapeRoute::whereHas('route', fn ($q) => $q->where('id_campagne', $campagne->id))
            ->whereNotNull('id_livraison')
            ->select('statut')
            ->get();
        $etapesParStatut = $etapes->countBy('statut');
        $etapesTotal = $etapes->count();
        $etapesLivrees = $etapesParStatut['livree'] ?? 0;

        return response()->json([
            'tournees_total' => $routes->count(),
            'tournees_actives' => $routes->count() - ($routesParStatut['annulee'] ?? 0),
            'tournees_annulees' => $routesParStatut['annulee'] ?? 0,
            'tournees_terminees' => $routesParStatut['terminee'] ?? 0,
            'livraisons_restantes' => $etapesParStatut['en_attente'] ?? 0,
            'livraisons_en_cours' => $etapesParStatut['en_cours'] ?? 0,
            'livraisons_livrees' => $etapesLivrees,
            'livraisons_ignorees' => $etapesParStatut['ignoree'] ?? 0,
            'avancement_pct' => $etapesTotal > 0 ? round($etapesLivrees / $etapesTotal, 4) : 0.0,
        ]);
    }

    public function routes(Campagne $campagne): JsonResponse
    {
        $routes = RouteLivraison::where('id_campagne', $campagne->id)
            ->with(['benevole', 'vehiculeType', 'etapes.livraison.famille:id,nom,prenom,adresse'])
            ->get();

        return response()->json(RouteLivraisonResource::collection($routes));
    }

    /**
     * Livraisons confirmées jamais couvertes par aucun créneau — voir
     * RouteGenerationService::livraisonsNonCouvertes() et le prompt §3.3
     * point 7 ("do not silently drop anyone — raise a visible admin-board
     * item").
     *
     * ?id_campagne_journee (05/09/2026, optionnel) : scope l'affichage à
     * la journée en cours de sélection dans CampagneDetail.vue ; omis,
     * remonte les non-couvertes de toute la campagne (comportement
     * inchangé pour une campagne mono-jour).
     */
    public function nonCouvertes(Request $request, Campagne $campagne): JsonResponse
    {
        $journee = $request->filled('id_campagne_journee')
            ? $campagne->journees()->findOrFail($request->integer('id_campagne_journee'))
            : null;

        return response()->json($this->generationService->livraisonsNonCouvertes($campagne, $journee));
    }

    /**
     * Colonnes triables pour nonCouvertesTable() — même liste que
     * CampagnesController::COLONNES_TRIABLES_ELIGIBLES (même table, mêmes
     * colonnes de familles ; dupliquée plutôt qu'extraite en constante
     * partagée pour ne pas faire dépendre les deux contrôleurs l'un de
     * l'autre pour un simple tableau de noms de colonnes).
     */
    private const COLONNES_TRIABLES_NON_COUVERTES = ['id', 'nom', 'telephone', 'telephone_bis', 'criticite', 'nombre_adulte', 'nombre_enfant', 'derniere_livraison_le'];

    /**
     * Version tableau, filtrable et paginée de nonCouvertes() ci-dessus —
     * ajoutée le 09/09/2026 (prompt de cette date §5.1.3 : "Selecting
     * livraison should be a table [...] Use the same layout and the same
     * filter collapsable filter panel [as campagne/{id}]"). Endpoint
     * séparé plutôt que de modifier nonCouvertes() : celui-ci reste tel
     * quel pour ShortfallPanel.vue et le picker "ajouter une livraison"
     * de RoutesPanel.vue, qui n'ont besoin que d'une liste simple.
     */
    public function nonCouvertesTable(Request $request, Campagne $campagne): JsonResponse
    {
        $journee = $request->filled('id_campagne_journee')
            ? $campagne->journees()->findOrFail($request->integer('id_campagne_journee'))
            : null;

        $query = $this->livraisonGenerationService->nonCouvertesEligibles($campagne, $journee)->with('quartier.secteur.ville');
        FamilleFilters::appliquer($query, $request);

        $colonne = $request->input('tri');
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        if (in_array($colonne, self::COLONNES_TRIABLES_NON_COUVERTES, true)) {
            match ($colonne) {
                'nom' => $query->orderBy('nom', $direction)->orderBy('prenom', $direction),
                default => $query->orderBy($colonne, $direction),
            };
        } else {
            $query->orderByDesc('criticite')->orderBy('derniere_livraison_le');
        }

        // ids_only : mêmes ids/id_livraison que la liste filtrée, TOUTES
        // pages — même besoin "tout sélectionner après filtrage" que
        // CampagnesController::eligibles() (prompt §1.6.3), mais on a
        // aussi besoin de l'id_livraison associé pour construire la
        // tournée personnalisée, pas seulement l'id de la famille.
        if ($request->boolean('ids_only')) {
            return response()->json(['ids' => $query->pluck('id_livraison')]);
        }

        return response()->json($query->paginate($request->integer('per_page') ?: 50)->withQueryString());
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

        return response()->json(RouteIncidentResource::collection($incidents));
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
            $this->notificationCenter->resoudreParDonnee('id_incident', $incident->id);

            return response()->json(['success' => true, ...$resultat]);
        }

        $incident->update(['statut' => 'resolu']);
        $this->notificationCenter->resoudreParDonnee('id_incident', $incident->id);

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

    /**
     * Override manuel du statut d'un arrêt par un gestionnaire (09/09/2026,
     * prompt de cette date §5.2.3) — voir
     * RouteMutationService::changerStatutEtape().
     */
    public function changerStatutEtape(Request $request, RouteLivraison $route, EtapeRoute $etape): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'statut' => 'required|in:' . implode(',', EtapeRoute::STATUTS),
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        if ($etape->id_route !== $route->id) {
            return response()->json(['success' => false, 'message' => "Cet arrêt n'appartient pas à cette tournée."], 422);
        }

        try {
            $etape = $this->mutationService->changerStatutEtape($etape, $request->input('statut'));
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'etape' => $etape]);
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

    /**
     * Voir le prompt du 05/09/2026 §5.2 — supprime une tournée encore
     * 'planifiee' pour permettre de la reconstruire avec un poids moyen
     * mis à jour (voir RouteMutationService::supprimer()).
     */
    public function supprimerRoute(RouteLivraison $route): JsonResponse
    {
        try {
            $this->mutationService->supprimer($route);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
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
