<?php
// app/Services/IncidentResolutionService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Services\NotificationCenterService;
use App\Models\Campagne;
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;

/**
 * Résolution / fermeture des incidents de tournée (03/10/2026) — logique
 * extraite de LiveBoardController::resoudreIncident() pour être partagée par
 * Suivi livraison (panneau des incidents ouverts), la page Incidents de la
 * campagne et la clôture de campagne.
 *
 * Trois sorties d'un incident ouvert, toutes retirent sa notification :
 *  - resoudre()  → 'resolu' ; pour 'benevole_absent', relance d'abord le
 *    clustering des arrêts orphelins (comportement historique) ;
 *  - ignorer()   → 'ignore' (« Fermé (ignoré) »), AUCUN effet de bord ;
 *  - forcerResolutionPourCampagne() → 'resolu' pour TOUS les incidents
 *    ouverts d'une campagne, sans effet de bord (pas de re-clustering : on
 *    ferme une campagne, on ne génère pas de nouvelles tournées).
 *
 * Réouverture (09/10/2026) : un incident résolu ou ignoré peut être rouvert
 * (rouvrir()) tant que la campagne n'est pas terminée — décision du 09/10/2026,
 * qui remplace celle du 03/10/2026 (« jamais rouvert »). Les effets de bord d'une
 * résolution passée (re-clustering d'un bénévole absent…) ne sont PAS défaits.
 *
 * « Livraison ignorée » (09/10/2026) : resoudreLivraisonIgnoree() propose, en plus
 * de la résolution simple, de remettre la famille à planifier, de l'ajouter à une
 * tournée existante, de la basculer en retrait au QG ou de l'imposer à un chauffeur.
 * « Retrait QG non livré » : resoudreRetraitNonLivre() la remet « Prête » ou la
 * repasse en livraison à domicile.
 */
class IncidentResolutionService
{
    public function __construct(
        private readonly RouteGenerationService $generationService,
        private readonly NotificationCenterService $notificationCenter,
        private readonly LivraisonChangementService $changements,
        private readonly ChauffeursConfirmesService $chauffeursConfirmes,
    ) {}

    /**
     * @return array<string, mixed> Résultat du re-clustering pour benevole_absent, [] sinon
     */
    public function resoudre(RouteIncident $incident): array
    {
        if ($incident->type === 'benevole_absent') {
            // Les arrêts encore en_attente de la tournée de ce bénévole
            // sont les livraisons « orphelines » à replacer ailleurs.
            $idsLivraisonsOrphelines = $incident->route->etapes()
                ->where('statut', 'en_attente')
                ->pluck('id_livraison')
                ->filter()
                ->all();

            $resultat = $this->generationService->relancerPourLivraisonsOrphelines(
                $incident->route->campagne,
                $idsLivraisonsOrphelines,
                $incident->route->id_benevole,
            );

            $incident->update([
                'statut' => 'resolu',
                'notes' => trim(($incident->notes ?? '') . "\n[Re-cluster] " . json_encode($resultat)),
            ]);
            $this->notificationCenter->resoudreParDonnee('id_incident', $incident->id);

            return $resultat;
        }

        $incident->update(['statut' => 'resolu']);
        $this->notificationCenter->resoudreParDonnee('id_incident', $incident->id);

        return [];
    }

    public function ignorer(RouteIncident $incident): void
    {
        $incident->update(['statut' => 'ignore']);
        $this->notificationCenter->resoudreParDonnee('id_incident', $incident->id);
    }

    /**
     * Rouvre un incident résolu ou ignoré (09/10/2026). Refusé quand la campagne
     * est terminée. La notification retirée à la fermeture est recréée.
     *
     * @return array{avertissement?: string}
     */
    public function rouvrir(RouteIncident $incident): array
    {
        if (!in_array($incident->statut, ['resolu', 'ignore'], true)) {
            throw new \RuntimeException('Seul un incident résolu ou fermé (ignoré) peut être rouvert.');
        }

        $idCampagne = $incident->idCampagneEffectif();
        if ($idCampagne !== null && Campagne::find($idCampagne)?->statut === 'terminee') {
            throw new \RuntimeException('La campagne est terminée : ses incidents ne peuvent plus être rouverts.');
        }

        $etait = $incident->statut;
        $incident->update([
            'statut' => 'ouvert',
            'notes' => trim(($incident->notes ?? '') . "\n[Rouvert — était " . ($etait === 'resolu' ? 'résolu' : 'fermé (ignoré)') . ']'),
        ]);
        $incident->notifierAdmins();

        if ($incident->type === 'benevole_absent' && $etait === 'resolu') {
            return ['avertissement' => 'Les arrêts de cette tournée ont déjà été replacés sur d\'autres tournées : rouvrir l\'incident ne défait pas ce re-clustering.'];
        }

        return [];
    }

    /**
     * Résolution d'un incident « livraison ignorée » avec une suite à donner
     * à la famille (09/10/2026).
     *
     * @param  array{id_route?: int|null, id_benevole?: int|null}  $parametres
     * @param  string  $action  reinitialiser | tournee | retrait_qg | chauffeur
     *
     * @throws \RuntimeException message prêt à afficher quand l'action est refusée
     */
    public function resoudreLivraisonIgnoree(RouteIncident $incident, string $action, array $parametres = []): void
    {
        if ($incident->type !== 'livraison_ignoree' || $incident->statut !== 'ouvert') {
            throw new \RuntimeException('Cette action ne concerne qu\'un incident « livraison ignorée » ouvert.');
        }

        $livraison = $incident->id_livraison !== null ? Livraison::find($incident->id_livraison) : null;
        if ($livraison === null) {
            throw new \RuntimeException('La famille de cette livraison n\'est plus dans la campagne : résolvez simplement l\'incident.');
        }

        $note = match ($action) {
            'reinitialiser' => $this->suiteReinitialiser($livraison),
            'tournee' => $this->suiteTournee($livraison, (int) ($parametres['id_route'] ?? 0)),
            'retrait_qg' => $this->suiteRetraitQg($livraison),
            'chauffeur' => $this->suiteChauffeur($livraison, (int) ($parametres['id_benevole'] ?? 0)),
            default => throw new \RuntimeException('Action inconnue.'),
        };

        $this->cloreAvecNote($incident, $note);
    }

    /**
     * Résolution d'un incident « retrait QG non livré » (09/10/2026).
     *
     * @param  string  $action  reessayer | domicile
     */
    public function resoudreRetraitNonLivre(RouteIncident $incident, string $action): void
    {
        if ($incident->type !== 'retrait_hq_non_livre' || $incident->statut !== 'ouvert') {
            throw new \RuntimeException('Cette action ne concerne qu\'un incident « retrait QG non livré » ouvert.');
        }

        $livraison = $incident->id_livraison !== null ? Livraison::find($incident->id_livraison) : null;
        if ($livraison === null) {
            throw new \RuntimeException('La famille de cette livraison n\'est plus dans la campagne : résolvez simplement l\'incident.');
        }

        $note = match ($action) {
            'reessayer' => $this->suiteReessayerRetrait($livraison),
            'domicile' => $this->suiteLivraisonDomicile($livraison),
            default => throw new \RuntimeException('Action inconnue.'),
        };

        $this->cloreAvecNote($incident, $note);
    }

    private function cloreAvecNote(RouteIncident $incident, string $note): void
    {
        $incident->update([
            'statut' => 'resolu',
            'notes' => trim(($incident->notes ?? '') . "\n[Résolu] " . $note),
        ]);
        $this->notificationCenter->resoudreParDonnee('id_incident', $incident->id);
    }

    private function suiteReinitialiser(Livraison $livraison): string
    {
        $this->changements->remettreAPlanifier($livraison);

        return 'Famille remise à planifier (prochaine génération de routes).';
    }

    private function suiteTournee(Livraison $livraison, int $idRoute): string
    {
        $route = RouteLivraison::find($idRoute);
        if ($route === null) {
            throw new \RuntimeException('Choisissez une tournée.');
        }

        $this->changements->ajouterALaTournee($livraison, $route);

        return "Famille ajoutée à la tournée #{$route->id}.";
    }

    private function suiteRetraitQg(Livraison $livraison): string
    {
        // L'arrêt ignoré est quitté d'abord (statut ignoree → non_assignee), puis la famille
        // reçoit un rendez-vous au QG (email si la campagne est démarrée).
        $this->changements->remettreAPlanifier($livraison);
        $this->changements->changerSeDeplace($livraison->fresh(), true);

        return 'Famille basculée en retrait au QG.';
    }

    private function suiteChauffeur(Livraison $livraison, int $idBenevole): string
    {
        if ($idBenevole === 0) {
            throw new \RuntimeException('Choisissez un chauffeur.');
        }

        if (!$this->chauffeursConfirmes->estConfirme($idBenevole, $livraison->id_campagne_journee, $livraison->id_campagne)) {
            throw new \RuntimeException('Ce bénévole n\'a pas confirmé sa disponibilité (avec un véhicule) pour la journée de cette famille.');
        }

        $this->changements->remettreAPlanifier($livraison);
        $this->changements->prendreEnCharge($livraison->fresh(), $idBenevole);

        return "Famille imposée au chauffeur #{$idBenevole}.";
    }

    private function suiteReessayerRetrait(Livraison $livraison): string
    {
        if ($livraison->statut_retrait_hq === 'non_delivre') {
            $livraison->update(['statut_retrait_hq' => null]);
        }

        return 'La famille peut revenir : son retrait est de nouveau « Prête ».';
    }

    private function suiteLivraisonDomicile(Livraison $livraison): string
    {
        $this->changements->changerSeDeplace($livraison, false);

        return 'Famille repassée en livraison à domicile (à planifier).';
    }

    /**
     * @return int Nombre d'incidents fermés
     */
    public function forcerResolutionPourCampagne(Campagne $campagne): int
    {
        $incidents = RouteIncident::ouverts()
            ->deCampagne($campagne->id)
            ->get();

        foreach ($incidents as $incident) {
            $incident->update([
                'statut' => 'resolu',
                'notes' => trim(($incident->notes ?? '') . "\n[Résolu de force à la clôture de la campagne]"),
            ]);
            $this->notificationCenter->resoudreParDonnee('id_incident', $incident->id);
        }

        return $incidents->count();
    }
}
