<?php
// app/Services/RetraitHqSchedulingService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Models\Livraison;
use App\Support\Creneau;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Planification des créneaux de retrait QG des familles se_deplace — voir
 * le prompt du 24/09/2026 §2/§4 : "arrival needs to be spread across all
 * flagged families so they don't all come at the same time".
 *
 * Une famille se_deplace n'entre jamais dans le clustering (voir
 * RouteGenerationService::genererPourCreneau()/resoudreLivraisonsImposees()
 * — exclues via ->where('se_deplace', false)) : elle reçoit à la place un
 * rendez-vous individuel (livraisons.heure_arrivee_prevue_hq) étalé sur la
 * fenêtre campagnes.heure_debut_arrivee_hq/heure_fin_arrivee_hq (defaults
 * 8h/19h, voir Campagne::heureDebutArriveeHq()/heureFinArriveeHq() — "be
 * sure to base it on current timeslots to avoid generating handouts at
 * night", mêmes bornes que App\Support\Creneau).
 *
 * Un rendez-vous toutes les (fenêtre / nombre de familles se_deplace
 * CONFIRMÉES de la journée) minutes — voir planifierPour(). Recalculée
 * pour TOUTE la journée à chaque appel (pas seulement les nouveaux
 * arrivants) : le nombre total de familles se_deplace change au fil des
 * confirmations/annulations, un étalement stable nécessite de repartir de
 * zéro plutôt que d'insérer un nouveau rendez-vous au milieu d'un
 * planning déjà posé (voir le prompt §4 : "recompute both routes and
 * handouts if flag changes for current campagne").
 *
 * Appelée depuis 2 endroits :
 *   - LiveBoardController::genererRoutes() (nominal — au moment où les
 *     tournées sont générées pour une journée, voir le prompt §Additional
 *     points 1 : "When all families are confirmed and route generation is
 *     triggered, send emails to all families with where and when to
 *     come");
 *   - App\Http\Controllers\Admin\Livraison\ContactTrackingController::
 *     mettreAJourSeDeplace() (un changement de se_deplace en cours de
 *     campagne doit ré-étaler TOUTE la journée, pas seulement la
 *     livraison modifiée).
 */
class RetraitHqSchedulingService
{
    /**
     * Recalcule et persiste heure_arrivee_prevue_hq pour toutes les
     * livraisons se_deplace CONFIRMÉES de cette journée — triées par
     * criticité décroissante (même convention que PackagingController/
     * ContactTrackingController::sortByDesc('famille.criticite')) puis
     * par id pour un ordre stable en cas d'égalité.
     *
     * @return Collection<int, Livraison> les livraisons replanifiées, dans leur nouvel ordre
     */
    public function planifierPour(Campagne $campagne, CampagneJournee $journee): Collection
    {
        $livraisons = Livraison::where('id_campagne', $campagne->id)
            ->where('id_campagne_journee', $journee->id)
            ->where('statut_contact', 'confirme')
            ->where('se_deplace', true)
            ->with('famille:id,criticite')
            ->get()
            ->sortByDesc(fn (Livraison $l) => $l->famille->criticite ?? 0)
            ->values();

        if ($livraisons->isEmpty()) {
            return $livraisons;
        }

        $debut = $this->ancrerSurJournee($journee, $campagne->heureDebutArriveeHq());
        $fin = $this->ancrerSurJournee($journee, $campagne->heureFinArriveeHq());

        // Fenêtre invalide (fin <= début, ex: saisie inversée) : repli sur
        // les bornes par défaut de la journée entière plutôt que planter
        // ou tout entasser sur un seul horodatage — mieux vaut un
        // étalement sur la plage complète qu'aucun étalement du tout.
        if ($fin->lessThanOrEqualTo($debut)) {
            $debut = $this->ancrerSurJournee($journee, '08:00:00');
            $fin = $this->ancrerSurJournee($journee, '19:00:00');
        }

        $intervalleMinutes = $livraisons->count() > 1
            ? $debut->diffInMinutes($fin) / $livraisons->count()
            : 0;

        foreach ($livraisons as $index => $livraison) {
            $livraison->update([
                'heure_arrivee_prevue_hq' => $debut->copy()->addMinutes((int) round($index * $intervalleMinutes)),
            ]);
        }

        return $livraisons;
    }

    /**
     * Combine la date de la journée avec une heure "HH:MM:SS" — et
     * clampe dans la plage opérationnelle 8h-19h (App\Support\Creneau) :
     * une fenêtre mal saisie par l'admin (ex: 6h ou 21h) ne doit jamais
     * produire un rendez-vous de nuit, voir le prompt §4.
     */
    private function ancrerSurJournee(CampagneJournee $journee, string $heure): Carbon
    {
        $horodatage = $journee->date->copy()->setTimeFromTimeString($heure);

        $borneBasse = $journee->date->copy()->setTimeFromTimeString('08:00:00');
        $borneHaute = $journee->date->copy()->setTimeFromTimeString('19:00:00');

        if ($horodatage->lessThan($borneBasse)) {
            return $borneBasse;
        }

        return $horodatage->greaterThan($borneHaute) ? $borneHaute : $horodatage;
    }
}
