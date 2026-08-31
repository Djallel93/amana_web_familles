<?php
// app/Http/Controllers/Admin/Livraison/CampagnesController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use App\Http\Controllers\Controller;
use App\Models\Campagne;
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
        return view('livraison.a-venir', ['titre' => 'Campagnes']);
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

        return response()->json(['success' => true, 'campagne' => $campagne], 201);
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
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $resultat = $this->generationService->genererPour($campagne, $request->input('ids_familles'));

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

        return response()->json(['success' => true, ...$resultat]);
    }
}
