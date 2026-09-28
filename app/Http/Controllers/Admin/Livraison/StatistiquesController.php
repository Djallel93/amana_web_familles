<?php
// app/Http/Controllers/Admin/Livraison/StatistiquesController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use App\Http\Controllers\Controller;
use App\Http\Resources\CampagneResource;
use App\Models\Campagne;
use App\Models\CampagneStatsSnapshot;
use App\Services\CampagneStatsService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

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
    /**
     * Section E4 du refactor (16/09/2026, quatrième chunk du domaine
     * livraison) — page Inertia, remplace resources/views/livraison/
     * statistiques.blade.php (supprimée dans ce même chunk).
     *
     * Le tableau de comparaison historique passe en prop de page plutôt
     * qu'en composant Blade server-rendu : c'était déjà une liste
     * chargée intégralement côté serveur, sans interactivité — même
     * raisonnement que la liste campagnes (chunk précédent) — d'où le
     * formatage (date, arrondi du taux) fait ici en PHP plutôt que
     * délégué au Vue, pour rester un port fidèle ligne à ligne de
     * l'ancien tableau Blade plutôt qu'une réécriture.
     */
    public function index(?Campagne $campagne = null): InertiaResponse
    {
        $campagnes = Campagne::orderByDesc('date_livraison')->get();
        $historique = $this->statsService->comparaisonHistorique();
        $personne = auth()->user();

        return Inertia::render('Livraison/Statistiques', [
            'campagnes' => CampagneResource::collection($campagnes),
            'campagneSelectionneeId' => $campagne?->id,
            'donneesUrlTemplate' => route('livraison.statistiques.donnees', ['campagne' => '__CAMPAGNE__']),
            'snapshotUrlTemplate' => route('livraison.statistiques.snapshot', ['campagne' => '__CAMPAGNE__']),
            'peutSnapshotter' => $personne->isAdmin() || $personne->isGestionnaire(),
            'historique' => $historique->map(fn (CampagneStatsSnapshot $s) => [
                'id' => $s->id,
                'label' => $s->campagne->date_livraison->format('d/m/Y') . ' — ' . $s->campagne->type,
                'nombre_menages' => $s->donnees['nombre_menages'] ?? null,
                'poids_collecte_kg' => $s->donnees['poids_collecte_kg'] ?? null,
                'taux_livraison_pourcentage' => isset($s->donnees['taux_livraison']) ? round($s->donnees['taux_livraison'] * 100) : null,
            ])->values(),
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
