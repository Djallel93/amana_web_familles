<?php
// app/Http/Controllers/Admin/Livraison/StatistiquesController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Statistiques live + historiques par campagne (admin Full, gestionnaire
 * Full, benevole lecture seule — voir matrice de droits §4). Distinct de
 * App\Http\Controllers\Admin\StatistiquesFamillesController, qui couvre
 * les statistiques du domaine familles.
 *
 * SQUELETTE (Patch 1) — le calcul des métriques et le snapshot à la
 * conclusion d'une campagne (campagne_stats_snapshots, voir §3.5) sont
 * prévus pour le Patch 5.
 */
class StatistiquesController extends Controller
{
    public function index(): View
    {
        return view('livraison.a-venir', ['titre' => 'Statistiques livraison']);
    }
}
