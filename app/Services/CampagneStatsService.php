<?php
// app/Services/CampagneStatsService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Campagne;
use App\Models\CampagneStatsSnapshot;
use App\Models\Livraison;
use App\Models\RouteLivraison;

/**
 * Calcul et snapshot des statistiques de campagne — voir le prompt du
 * 30/08/2026 §3.5 : "must be snapshotted/stored as their own record when
 * a campaign concludes (or periodically during it)... required for the
 * historical KPI comparison across campaigns."
 *
 * calculer() lit toujours l'état ACTUEL (comme Campagne::nombre_menages/
 * poids_collecte_kg, voir Patch 1) — snapshotter() en fige une copie dans
 * campagne_stats_snapshots à un instant donné. Forme du blob JSON
 * `donnees` provisoire (voir 2026_08_31_000012_create_campagne_stats_snapshots_table.php),
 * à ajuster si le tableau de bord a besoin d'autre chose.
 */
class CampagneStatsService
{
    /**
     * @return array{
     *     nombre_menages: int, poids_collecte_kg: float,
     *     livraisons_total: int, livraisons_par_statut: array<string, int>,
     *     poids_livre_kg: float, routes_total: int, routes_par_statut: array<string, int>,
     *     distance_totale_km: float, taux_livraison: float
     * }
     */
    public function calculer(Campagne $campagne): array
    {
        $livraisons = Livraison::where('id_campagne', $campagne->id)->get();
        $routes = RouteLivraison::where('id_campagne', $campagne->id)->get();

        $livraisonsParStatut = $livraisons->countBy('statut')->all();
        $routesParStatut = $routes->countBy('statut')->all();

        $totalLivraisons = $livraisons->count();
        $livrees = $livraisonsParStatut['livree'] ?? 0;

        return [
            'nombre_menages' => $campagne->nombre_menages,
            'poids_collecte_kg' => $campagne->poids_collecte_kg,
            'livraisons_total' => $totalLivraisons,
            'livraisons_par_statut' => $livraisonsParStatut,
            'poids_livre_kg' => (float) $livraisons->where('statut', 'livree')->sum('poids_kg'),
            'routes_total' => $routes->count(),
            'routes_par_statut' => $routesParStatut,
            'distance_totale_km' => (float) $routes->sum('distance_totale_km'),
            'taux_livraison' => $totalLivraisons > 0 ? round($livrees / $totalLivraisons, 4) : 0.0,
        ];
    }

    public function snapshotter(Campagne $campagne): CampagneStatsSnapshot
    {
        return CampagneStatsSnapshot::create([
            'id_campagne' => $campagne->id,
            'snapshot_at' => now(),
            'donnees' => $this->calculer($campagne),
        ]);
    }

    /**
     * Comparaison historique entre campagnes — voir le prompt §3.5. Un
     * snapshot le plus récent par campagne (pas tout l'historique de
     * chacune, qui pourrait avoir plusieurs snapshots périodiques —
     * seul le dernier représente l'état final utile à la comparaison).
     */
    public function comparaisonHistorique(): \Illuminate\Support\Collection
    {
        return CampagneStatsSnapshot::with('campagne')
            ->orderByDesc('snapshot_at')
            ->get()
            ->unique('id_campagne')
            ->sortByDesc(fn (CampagneStatsSnapshot $s) => $s->campagne->date_livraison)
            ->values();
    }
}
