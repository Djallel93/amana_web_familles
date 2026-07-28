<?php
// app/Http/Controllers/Admin/StatistiquesFamillesController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FamilleStatistics;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

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

    public function index(): View
    {
        return view('familles.statistiques');
    }

    public function data(): JsonResponse
    {
        return response()->json($this->stats->computeAll());
    }
}
