<?php
// app/Services/CouvertureCollecteService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Campagne;
use App\Models\Livraison;

/**
 * Couverture de la collecte (Scénario 4 du chantier "polling live") : le
 * poids réellement collecté à la pesée (Donation, voir
 * Campagne::poids_collecte_kg) suffit-il pour conditionner ce qui reste,
 * au poids moyen actuellement configuré ? Alimente l'encart "couverture"
 * de l'écran packaging (PackagingController::poids()) — AFFICHAGE
 * PASSIF uniquement : rien ici ne modifie un poids moyen ni une livraison
 * (même principe que le recalcul manuel, voir
 * CampagnesController::recalculerPoids()).
 *
 * Portée = campagne ENTIÈRE (décision du 19/09/2026), pas la journée
 * sélectionnée sur l'écran packaging : Donation.id_campagne_journee est
 * nullable, et un don pesé un jour peut servir à conditionner le
 * lendemain.
 *
 * Définitions (mêmes livraisons que l'écran packaging : statut_contact =
 * 'confirme', hors 'ignoree') :
 *  - collecte    : somme des donations.poids_kg ;
 *  - engagé      : poids_kg des livraisons déjà livrées OU déjà
 *                  conditionnées / en cours de conditionnement — ces colis
 *                  existent physiquement à l'ancien poids, et
 *                  recalculerPoids() n'y touche jamais ;
 *  - reste       : poids des livraisons pas encore conditionnées
 *                  (statut_conditionnement = 'en_attente', ni livrées),
 *                  calculé AUX POIDS MOYENS ACTUELS via
 *                  Livraison::calculerPoidsKg() — ce que recalculerPoids()
 *                  leur donnerait — et non d'après leur poids_kg figé, qui
 *                  peut dater d'avant une modification du poids moyen ;
 *  - disponible  : collecte − engagé ;
 *  - couverture  : disponible / reste (peut dépasser 1 = surplus).
 *
 * Poids moyen réalisable : appliquer le même ratio à chaque taux fait
 * exactement coïncider besoin et disponible (reste × couverture =
 * disponible), d'où taux × couverture — seulement quand couverture < 1.
 */
class CouvertureCollecteService
{
    /**
     * @return array{
     *     collecte_demarree: bool,
     *     collecte_kg: float, engage_kg: float, reste_kg: float, disponible_kg: float,
     *     couverture: float|null,
     *     poids_moyen_actuel: array{normal: float, hotel: float|null, etudiant: float|null},
     *     poids_moyen_realisable: array{normal: float, hotel: float|null, etudiant: float|null}|null,
     *     recalcul_necessaire: bool,
     *     livraisons_exclues: int
     * }
     */
    public function calculer(Campagne $campagne): array
    {
        $collecte = (float) $campagne->poids_collecte_kg;
        // Aucune pesée enregistrée ≠ "0 % de couverture" : la collecte n'a
        // simplement pas commencé, rien à signaler.
        $collecteDemarree = $campagne->donations()->exists();

        $base = fn () => Livraison::where('id_campagne', $campagne->id)
            ->where('statut_contact', 'confirme')
            ->where('statut', '!=', 'ignoree');

        $engage = (float) $base()
            ->where(function ($q) {
                $q->where('statut', 'livree')
                    ->orWhereIn('statut_conditionnement', ['prete', 'en_cours']);
            })
            ->sum('poids_kg');

        $restantes = $base()
            ->where('statut', '!=', 'livree')
            ->where('statut_conditionnement', 'en_attente')
            ->with('famille:id,etudiant,est_hotel')
            ->select(['id', 'id_famille', 'nombre_personnes', 'poids_kg'])
            ->get();

        $resteAuTauxActuel = 0.0;
        $resteFige = 0.0;
        $exclues = 0;
        foreach ($restantes as $livraison) {
            // calculerPoidsKg() lève LogicException pour une famille à la
            // fois etudiant ET est_hotel (anomalie de données, voir son
            // docblock) : on l'écarte de l'estimation et on la compte,
            // plutôt que de faire échouer un endpoint interrogé toutes
            // les 20s ou de trancher silencieusement à sa place. Les DEUX
            // sommes ne portent que sur les livraisons calculables, pour
            // que leur comparaison (recalcul_necessaire) reste juste.
            if ($livraison->famille === null) {
                $exclues++;

                continue;
            }
            try {
                $poidsActuel = Livraison::calculerPoidsKg($livraison->famille, $campagne, (int) $livraison->nombre_personnes);
            } catch (\LogicException) {
                $exclues++;

                continue;
            }

            $resteAuTauxActuel += $poidsActuel;
            $resteFige += (float) $livraison->poids_kg;
        }

        $disponible = $collecte - $engage;
        $couverture = ($collecteDemarree && $resteAuTauxActuel > 0)
            ? round(max(0.0, $disponible) / $resteAuTauxActuel, 4)
            : null;

        $actuel = [
            'normal' => (float) $campagne->poids_moyen_kg,
            // 0 / non renseigné = repli sur le taux normal (voir
            // Livraison::calculerPoidsKg()) : rien de spécifique à suggérer.
            'hotel' => (float) $campagne->poids_moyen_hotel_kg > 0 ? (float) $campagne->poids_moyen_hotel_kg : null,
            'etudiant' => (float) $campagne->poids_moyen_etudiant_kg > 0 ? (float) $campagne->poids_moyen_etudiant_kg : null,
        ];

        $realisable = null;
        if ($couverture !== null && $couverture > 0 && $couverture < 1) {
            $realisable = array_map(
                fn (?float $taux) => $taux === null ? null : round($taux * $couverture, 2),
                $actuel,
            );
        }

        return [
            'collecte_demarree' => $collecteDemarree,
            'collecte_kg' => round($collecte, 2),
            'engage_kg' => round($engage, 2),
            'reste_kg' => round($resteAuTauxActuel, 2),
            'disponible_kg' => round($disponible, 2),
            'couverture' => $couverture,
            'poids_moyen_actuel' => $actuel,
            'poids_moyen_realisable' => $realisable,
            // poids_kg figés ≠ poids aux taux actuels : un poids moyen a été
            // modifié sans "Recalculer les livraisons non conditionnées".
            'recalcul_necessaire' => abs($resteFige - $resteAuTauxActuel) > 0.05,
            'livraisons_exclues' => $exclues,
        ];
    }
}
