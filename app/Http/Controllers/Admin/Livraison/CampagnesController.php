<?php
// app/Http/Controllers/Admin/Livraison/CampagnesController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use App\Http\Controllers\Controller;
use App\Models\Campagne;
use App\Models\Livraison;
use App\Models\Organisation;
use App\Models\Quartier;
use App\Models\RouteLivraison;
use App\Services\BenevoleDisponibiliteService;
use App\Services\LivraisonGenerationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Création/gestion des campagnes + sélection des familles éligibles
 * (admin/gestionnaire — accès "Full" sur ces deux lignes de la matrice de
 * droits, voir le prompt du 30/08/2026 §4/§7).
 *
 * eligibles()/genererLivraisons() couvrent une étape nécessaire mais non
 * explicitement décrite comme telle dans le prompt : la confirmation
 * famille/bénévole (Patch 2) suppose que les lignes Livraison existent
 * déjà — voir échange du 31/08/2026 élargissant le périmètre du Patch 2
 * pour inclure cette génération.
 */
class CampagnesController extends Controller
{
    public function __construct(
        private readonly LivraisonGenerationService $generationService,
        private readonly BenevoleDisponibiliteService $disponibiliteService,
    ) {
    }

    public function index(): View
    {
        $campagnes = Campagne::orderByDesc('date_livraison')->get();

        return view('livraison.campagnes', ['campagnes' => $campagnes]);
    }

    public function show(Campagne $campagne): View
    {
        // quartiers/organisations passés ici (ajouté le 03/09/2026) pour
        // les selects du filtre d'éligibilité côté Vue — même requête et
        // même ordre que FamillesController::index() pour son propre
        // filtre quartier/organisation, réutilisés tels quels plutôt que
        // d'ajouter un endpoint JSON dédié pour un référentiel déjà
        // disponible en lecture partout ailleurs dans l'app.
        return view('livraison.campagne-detail', [
            // journees chargées (05/09/2026) pour alimenter le sélecteur de
            // journée de CampagneDetail.vue avant génération — masqué côté
            // Vue quand il n'y en a qu'une (cas mono-jour).
            'campagne' => $campagne->load('journees'),
            'quartiers' => Quartier::orderBy('nom')->get(['id', 'nom']),
            'organisations' => Organisation::actifs()->orderBy('nom')->get(['id', 'nom']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:' . implode(',', Campagne::TYPES),
            'date_livraison' => 'required|date',
            'poids_moyen_kg' => 'required|numeric|min:0',
            'poids_moyen_hotel_kg' => 'nullable|numeric|min:0',
            'poids_moyen_etudiant_kg' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $campagne = Campagne::create([
            ...$validator->validated(),
            'statut' => 'preparation',
        ]);

        // Depuis le 05/09/2026 : une CampagneJournee est toujours créée dès
        // la création de la campagne, y compris pour une campagne
        // "classique" à une seule journée — voir Campagne::ajouterJournee()
        // (qui synchronise déjà date_livraison sur cette première journée).
        // Sans ça, id_campagne_journee resterait NULL sur toutes les
        // disponibilités/livraisons/routes d'une campagne mono-jour, alors
        // que RouteGenerationService/BenevoleDisponibilite/
        // CampagneStatsService raisonnent désormais tous par journée.
        $campagne->ajouterJournee($campagne->date_livraison);

        return response()->json(['success' => true, 'campagne' => $campagne->load('journees')], 201);
    }

    /**
     * Ajoute une journée à une campagne existante — voir le prompt du
     * 03/09/2026 §1 (campagnes multi-jours) et Campagne::ajouterJournee().
     * Couvre le cas "on vient de décider d'un jour de collecte/livraison
     * en plus" (ex: zakat el-fitr) sans créer une nouvelle campagne.
     */
    public function ajouterJournee(Request $request, Campagne $campagne): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'label' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $journee = $campagne->ajouterJournee($request->input('date'), $request->input('label'));

        return response()->json(['success' => true, 'journee' => $journee], 201);
    }

    /**
     * Liste des familles éligibles à une campagne, filtrable par
     * criticité/quartier/organisation — voir
     * LivraisonGenerationService::eligibles(). Consommée par l'écran de
     * sélection (île Vue, voir app.ts) pour construire la liste de
     * familles à cocher avant génération. Exclut les familles déjà
     * pourvues d'une Livraison pour CETTE campagne (voir le service).
     */
    public function eligibles(Request $request, Campagne $campagne): JsonResponse
    {
        $familles = $this->generationService->eligibles([
            'criticite_min' => $request->integer('criticite_min') ?: null,
            'id_quartier' => $request->integer('id_quartier') ?: null,
            'id_organisation' => $request->integer('id_organisation') ?: null,
        ], $campagne)->paginate(50);

        return response()->json($familles);
    }

    /**
     * Génère les lignes Livraison pour les familles sélectionnées — voir
     * LivraisonGenerationService::genererPour(). Renvoie séparément les
     * conflits etudiant/est_hotel (jamais résolus silencieusement, voir
     * ce service) pour que l'écran affiche clairement lesquelles n'ont
     * pas été traitées et pourquoi.
     */
    public function genererLivraisons(Request $request, Campagne $campagne): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids_familles' => 'required|array|min:1',
            'ids_familles.*' => 'integer|exists:familles,id',
            // Requis depuis le 05/09/2026 : toute campagne a désormais au
            // moins une CampagneJournee (voir store()), donc les
            // livraisons générées doivent toujours être rattachées à l'une
            // d'elles — plus de génération "orpheline" de journée.
            'id_campagne_journee' => 'required|integer|exists:campagne_journees,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $journee = $campagne->journees()->findOrFail($request->integer('id_campagne_journee'));

        $resultat = $this->generationService->genererPour($campagne, $request->input('ids_familles'), $journee);

        return response()->json([
            'success' => true,
            'generees' => $resultat['livraisons']->count(),
            'deja_existantes' => $resultat['deja_existantes'],
            'conflits' => $resultat['conflits']->map(fn ($f) => [
                'id' => $f->id,
                'nom' => "{$f->prenom} {$f->nom}",
                'raison' => 'etudiant_et_est_hotel',
            ])->values(),
        ]);
    }

    /**
     * Déclenche l'envoi de l'email de disponibilité à tous les bénévoles
     * validés — voir le prompt §3.2. Action explicite déclenchée par
     * l'admin/gestionnaire (un bouton), pas un effet de bord automatique
     * d'un changement de statut de campagne : le prompt ne précise pas à
     * quel changement de statut précis rattacher ce "lancement", une
     * action explicite évite d'inventer une règle non demandée — décision
     * du 31/08/2026.
     */
    public function notifierBenevoles(Campagne $campagne): JsonResponse
    {
        $resultat = $this->disponibiliteService->notifierCampagne($campagne);

        // Voir create_campagnes_table.php (colonne ajoutée le 03/09/2026) :
        // seule trace persistée que cette étape a eu lieu, pour
        // CampagneProgressBar.vue — indépendante du nombre d'envois
        // réussis/échoués, l'étape "notifier" est considérée franchie dès
        // qu'on a tenté, pas seulement si 100% des emails sont partis.
        $campagne->update(['benevoles_notifies_le' => now()]);

        return response()->json(['success' => true, ...$resultat]);
    }

    /**
     * Résumé d'avancement de la campagne à travers les étapes du workflow
     * livraison — voir le prompt du 03/09/2026 (checklist de progression
     * CampagneProgressBar.vue, amana_shared_ui n'a pas cette pièce, elle
     * est propre au domaine livraison donc reste locale à cette app).
     * Un seul aller-retour plutôt que le front ne recalcule depuis
     * plusieurs endpoints déjà existants (eligibles/non-couvertes...) qui
     * ne portent chacun qu'un fragment de l'image d'ensemble.
     */
    public function avancement(Campagne $campagne): JsonResponse
    {
        $livraisons = Livraison::where('id_campagne', $campagne->id)
            ->select('statut_contact', 'statut_conditionnement')
            ->get();

        $livraisonsTotal = $livraisons->count();
        $livraisonsAConfirmer = $livraisons->whereIn('statut_contact', ['a_contacter', 'contacte'])->count();
        $livraisonsConfirmees = $livraisons->where('statut_contact', 'confirme')->count();
        $livraisonsPretes = $livraisons->where('statut_conditionnement', 'prete')->count();

        $routes = RouteLivraison::where('id_campagne', $campagne->id)->select('statut')->get();
        $routesTotal = $routes->count();
        $routesChargees = $routes->whereIn('statut', ['chargement', 'en_cours', 'livraisons_terminees', 'terminee'])->count();
        $routesEnLivraison = $routes->whereIn('statut', ['en_cours', 'livraisons_terminees', 'terminee'])->count();
        $routesTerminees = $routes->where('statut', 'terminee')->count();

        return response()->json([
            'livraisons_generees' => $livraisonsTotal > 0,
            'contacts_termines' => $livraisonsTotal > 0 && $livraisonsAConfirmer === 0,
            'contacts_en_cours' => $livraisonsTotal > 0 && $livraisonsAConfirmer > 0 && $livraisonsAConfirmer < $livraisonsTotal,
            'benevoles_notifies' => $campagne->benevoles_notifies_le !== null,
            'routes_generees' => $routesTotal > 0,
            'pesee_demarree' => $campagne->donations()->exists(),
            'packaging_termine' => $livraisonsConfirmees > 0 && $livraisonsPretes >= $livraisonsConfirmees,
            'chargement_termine' => $routesTotal > 0 && $routesChargees === $routesTotal,
            'livraison_en_cours' => $routesTotal > 0 && $routesEnLivraison > 0,
            'terminee' => $routesTotal > 0 && $routesTerminees === $routesTotal,
            'compteurs' => [
                'livraisons_total' => $livraisonsTotal,
                'livraisons_confirmees' => $livraisonsConfirmees,
                'routes_total' => $routesTotal,
                'routes_terminees' => $routesTerminees,
            ],
        ]);
    }
}
