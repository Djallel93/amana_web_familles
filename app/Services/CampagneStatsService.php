<?php
// app/Services/CampagneStatsService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Campagne;
use App\Models\CampagneStatsSnapshot;
use App\Models\Livraison;
use App\Models\RouteLivraison;
use Illuminate\Support\Collection;

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
 *
 * VENTILATION PAR JOURNÉE (05/09/2026, suivi du patch multi-jours du
 * 03/09/2026) : calculer() gagne une clé `par_journee` en plus des
 * totaux globaux existants (gardés tels quels — utiles pour la vue
 * d'ensemble, la ventilation vient en complément, pas en remplacement).
 * Les routes d'imposées transverses (id_campagne_journee NULL, voir
 * RouteGenerationService) n'apparaissent dans aucun bucket `par_journee`
 * — seulement dans les totaux globaux ci-dessus, à titre indicatif.
 * comparaisonHistorique() n'a pas besoin d'ajustement : elle compare des
 * totaux de campagne à campagne, jamais une ventilation intra-campagne.
 */
class CampagneStatsService
{
    /**
     * @return array{
     *     nombre_menages: int, poids_collecte_kg: float,
     *     livraisons_total: int, livraisons_par_statut: array<string, int>,
     *     poids_livre_kg: float, routes_total: int, routes_par_statut: array<string, int>,
     *     distance_totale_km: float, taux_livraison: float,
     *     par_journee: array<int, array{
     *         label: string|null, date: string,
     *         livraisons_total: int, livraisons_par_statut: array<string, int>,
     *         poids_livre_kg: float, routes_total: int, routes_par_statut: array<string, int>,
     *         distance_totale_km: float, taux_livraison: float
     *     }>
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
            'par_journee' => $this->calculerParJournee($campagne, $livraisons, $routes),
        ];
    }

    /**
     * Même forme que les totaux ci-dessus (moins nombre_menages/
     * poids_collecte_kg, qui vivent au niveau Donation/CampagneArrivee et
     * n'ont pas de dimension journée), un bucket par CampagneJournee. Les
     * routes transverses (id_campagne_journee NULL) sont exclues de tous
     * les buckets — voir docblock de classe.
     *
     * @param Collection<int, Livraison> $livraisons
     * @param Collection<int, RouteLivraison> $routes
     * @return array<int, array{
     *     label: string|null, date: string,
     *     livraisons_total: int, livraisons_par_statut: array<string, int>,
     *     poids_livre_kg: float, routes_total: int, routes_par_statut: array<string, int>,
     *     distance_totale_km: float, taux_livraison: float
     * }>
     */
    private function calculerParJournee(Campagne $campagne, Collection $livraisons, Collection $routes): array
    {
        $livraisonsParJournee = $livraisons->groupBy('id_campagne_journee');
        $routesParJournee = $routes->groupBy('id_campagne_journee');

        $parJournee = [];

        foreach ($campagne->journees as $journee) {
            $livraisonsJournee = $livraisonsParJournee->get($journee->id, collect());
            $routesJournee = $routesParJournee->get($journee->id, collect());

            $statutsLivraisons = $livraisonsJournee->countBy('statut')->all();
            $totalLivraisonsJournee = $livraisonsJournee->count();
            $livreesJournee = $statutsLivraisons['livree'] ?? 0;

            $parJournee[$journee->id] = [
                'label' => $journee->label,
                'date' => $journee->date->toDateString(),
                'livraisons_total' => $totalLivraisonsJournee,
                'livraisons_par_statut' => $statutsLivraisons,
                'poids_livre_kg' => (float) $livraisonsJournee->where('statut', 'livree')->sum('poids_kg'),
                'routes_total' => $routesJournee->count(),
                'routes_par_statut' => $routesJournee->countBy('statut')->all(),
                'distance_totale_km' => (float) $routesJournee->sum('distance_totale_km'),
                'taux_livraison' => $totalLivraisonsJournee > 0 ? round($livreesJournee / $totalLivraisonsJournee, 4) : 0.0,
            ];
        }

        return $parJournee;
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
