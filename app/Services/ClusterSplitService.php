<?php
// app/Services/ClusterSplitService.php

declare(strict_types=1);

namespace App\Services;

use App\Support\RouteOptimizationConfig;
use Generator;

/**
 * Scission géographique d'un cluster trop lourd pour un véhicule donné —
 * remplace le split "par ordre de liste" du legacy (splitCluster(), dans
 * routeAssignmentService.js : remplit livraisonsA dans l'ordre du tableau
 * jusqu'à capacité, le reste va dans livraisonsB) — décision explicite du
 * prompt du 30/08/2026 §3.3 point 3 : "the split must be geography-aware:
 * choose the sub-group that minimizes resulting diameter, not list
 * order."
 *
 * Approche retenue le 31/08/2026 après discussion : recherche EXHAUSTIVE
 * (pas une heuristique) du sous-ensemble à retenir — parmi tous les
 * sous-ensembles qui respectent la capacité du véhicule (poids + parts),
 * on préfère d'abord ceux qui utilisent LE PLUS de livraisons (remplir le
 * véhicule plutôt que le sous-utiliser), puis parmi ceux de cette taille,
 * celui de diamètre minimum. Coût négligeable à cette échelle : les
 * clusters sont plafonnés à route_max_livraisons_par_route (~15
 * livraisons), soit au plus 2^15 sous-ensembles — largement acceptable
 * pour une action déclenchée manuellement par un admin, d'autant que les
 * scissions doivent rester rares (voir ClusteringService, désormais
 * capacity-aware dès la fusion des groupes).
 */
class ClusterSplitService
{
    public function __construct(
        private readonly GeoCalculationService $geo,
    ) {
    }

    /**
     * @param array{livraisons: array<int, array{id_livraison: int, latitude: float, longitude: float, nombre_personnes: int, poids_kg: float}>} $cluster
     * @param array{capacite_kg: float, nombre_part_max: int} $vehicule
     * @return array{retenu: array, reste: array}
     */
    public function scinder(array $cluster, array $vehicule): array
    {
        $livraisons = array_values($cluster['livraisons']);
        $n = count($livraisons);

        $tailleMax = min($n, $vehicule['nombre_part_max'] > 0 ? $n : 0, RouteOptimizationConfig::maxLivraisonsParRoute());

        for ($taille = $tailleMax; $taille >= 1; $taille--) {
            $meilleurSousEnsemble = null;
            $meilleurDiametre = null;

            foreach ($this->combinaisons($livraisons, $taille) as $sousEnsemble) {
                $poids = array_sum(array_column($sousEnsemble, 'poids_kg'));
                $parts = array_sum(array_column($sousEnsemble, 'nombre_personnes'));

                if ($poids > $vehicule['capacite_kg'] || $parts > $vehicule['nombre_part_max']) {
                    continue;
                }

                $diametre = $this->geo->diametreCluster($sousEnsemble);

                if ($meilleurDiametre === null || $diametre < $meilleurDiametre) {
                    $meilleurDiametre = $diametre;
                    $meilleurSousEnsemble = $sousEnsemble;
                }
            }

            if ($meilleurSousEnsemble !== null) {
                $idsRetenus = array_column($meilleurSousEnsemble, 'id_livraison');
                $reste = array_values(array_filter(
                    $livraisons,
                    fn (array $l) => !in_array($l['id_livraison'], $idsRetenus, true),
                ));

                return ['retenu' => $meilleurSousEnsemble, 'reste' => $reste];
            }
        }

        // Même une seule livraison du cluster ne tient pas dans ce
        // véhicule (poids/parts d'une livraison isolée dépasse déjà sa
        // capacité) — rien n'est retenu, tout repart au pool.
        return ['retenu' => [], 'reste' => $livraisons];
    }

    /**
     * Générateur de toutes les combinaisons de taille $k parmi $elements,
     * en ordre lexicographique — PHP n'a pas de fonction native pour ça.
     */
    private function combinaisons(array $elements, int $k): Generator
    {
        $n = count($elements);
        if ($k > $n || $k < 0) {
            return;
        }

        $indices = range(0, $k - 1);

        while (true) {
            yield array_map(fn (int $i) => $elements[$i], $indices);

            $i = $k - 1;
            while ($i >= 0 && $indices[$i] === $i + $n - $k) {
                $i--;
            }
            if ($i < 0) {
                break;
            }
            $indices[$i]++;
            for ($j = $i + 1; $j < $k; $j++) {
                $indices[$j] = $indices[$j - 1] + 1;
            }
        }
    }
}
