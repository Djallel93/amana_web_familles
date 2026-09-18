<?php
// app/Http/Controllers/Admin/StatistiquesFamillesController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FamilleStatistics;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Statistiques métier des dossiers familles (section 8.2 du prompt de
 * migration) — pattern identique à Bilan/BilanController de
 * amana_web_planning : shell Blade + Vue + Chart.js, données via endpoint
 * JSON séparé. Pas de plage de dates ici (contrairement à Bilan/Activité) :
 * les stats portent sur l'état ACTUEL des dossiers, pas une période.
 */
class StatistiquesFamillesController extends Controller
{
    public function __construct(
        private readonly FamilleStatistics $stats,
    ) {
    }

    /**
     * Section E4 du refactor (16/09/2026) — page Inertia, remplace
     * resources/views/familles/statistiques.blade.php (supprimée dans ce
     * même chunk). data() reste un endpoint JSON classique, inchangé —
     * seule cette action change, comme pour toutes les conversions de ce
     * refactor. Un seul prop (dataUrl) : FamillesStatistiques.vue lisait
     * jusqu'ici cette URL depuis window.FamillesStatistiquesConfig
     * (variable globale posée par un <script> inline côté Blade, pas un
     * data-* de point de montage comme ailleurs dans l'app) — remplacée
     * ici par une prop Inertia normale.
     */
    public function index(): InertiaResponse
    {
        return Inertia::render('Familles/Statistiques', [
            'dataUrl' => route('familles.statistiques.data'),
        ]);
    }

    public function data(): JsonResponse
    {
        return response()->json($this->stats->computeAll());
    }
}
