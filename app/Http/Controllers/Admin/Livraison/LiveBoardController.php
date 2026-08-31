<?php
// app/Http/Controllers/Admin/Livraison/LiveBoardController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Tableau de bord live admin/gestionnaire : toutes les tournées, tous les
 * incidents, réassignation, résolution — voir le prompt du 30/08/2026
 * §3.3/§4/§7. Couvre aussi le déclenchement du clustering/planification
 * des routes.
 *
 * SQUELETTE (Patch 1) — le moteur de clustering/assignation/TSP porté
 * depuis amana_livraison (routeClusteringService.js,
 * routeAssignmentService.js, routeTspOptimization.js), la mutabilité des
 * tournées après création et la gestion des route_incidents sont prévus
 * pour le Patch 3.
 */
class LiveBoardController extends Controller
{
    public function index(): View
    {
        return view('livraison.a-venir', ['titre' => 'Tableau de bord livraison']);
    }
}
