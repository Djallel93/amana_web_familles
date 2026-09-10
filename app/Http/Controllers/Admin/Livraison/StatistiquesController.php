<?php
// app/Http/Controllers/Admin/Livraison/StatistiquesController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use App\Http\Controllers\Controller;
use App\Models\Campagne;
use App\Services\CampagneStatsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Statistiques live + historiques par campagne (admin Full, gestionnaire
 * Full, benevole lecture seule — voir matrice de droits §4). Distinct de
 * App\Http\Controllers\Admin\StatistiquesFamillesController, qui couvre
 * les statistiques du domaine familles.
 */
class StatistiquesController extends Controller
{
    public function __construct(
        private readonly CampagneStatsService $statsService,
    ) {
    }

    /**
     * {campagne?} optionnel ajouté le 09/09/2026 (prompt de cette date
     * §1.3) — même raisonnement que LiveBoardController::index() (voir
     * son docblock) : présélectionne le <select> de LivraisonStatistiques.vue
     * quand on arrive depuis le nouveau bouton "📊 Statistiques" de
     * CampagneDetail.vue, sans rien changer pour l'accès direct par la
     * sidebar (aucune campagne connue à l'avance).
     */
    public function index(?Campagne $campagne = null): View
    {
        $campagnes = Campagne::orderByDesc('date_livraison')->get();
        $historique = $this->statsService->comparaisonHistorique();

        return view('livraison.statistiques', [
            'campagnes' => $campagnes,
            'campagneSelectionnee' => $campagne,
            'historique' => $historique,
        ]);
    }

    public function donnees(Campagne $campagne): JsonResponse
    {
        return response()->json($this->statsService->calculer($campagne));
    }

    /**
     * Écriture réservée admin/gestionnaire (voir matrice §4 : "Read-only"
     * pour benevole) — le groupe de routes (role:benevole, cascade
     * gestionnaire/admin) couvre les trois pour la lecture, cette
     * vérification supplémentaire couvre l'écriture, comme anticipé dans
     * routes/web.php.
     */
    public function snapshot(Campagne $campagne): JsonResponse
    {
        $personne = auth()->user();
        if (!$personne->isAdmin() && !$personne->isGestionnaire()) {
            return response()->json(['success' => false, 'message' => 'Action réservée aux gestionnaires.'], 403);
        }

        $snapshot = $this->statsService->snapshotter($campagne);

        return response()->json(['success' => true, 'snapshot' => $snapshot]);
    }
}
