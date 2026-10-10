<?php
// app/Services/LivraisonChangementService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Models\BenevoleProfil;
use Amana\Shared\Services\NotificationCenterService;
use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\PersonneDesactivee;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use Illuminate\Support\Facades\DB;

/**
 * Changements EN COURS DE CAMPAGNE qui déplacent une famille entre les trois
 * modes de prise en charge (06/10/2026) :
 *
 *   - tournée classique (clustering ou tournée personnalisée) ;
 *   - retrait au QG (livraisons.se_deplace) — rendez-vous étalé sur la
 *     journée, email à la famille ;
 *   - tournée « imposée » (livraisons.id_benevole_impose) : un chauffeur
 *     s'engage à livrer cette famille quand il veut, sans créneau.
 *
 * Règles (validées le 06/10/2026) :
 *   - une famille ne peut pas être à la fois se_deplace et imposée — le
 *     cas est refusé avec un message, l'admin le règle à la main ;
 *   - une famille déjà LIVRÉE n'est plus modifiable ;
 *   - une famille dans une tournée est retirée automatiquement tant que la
 *     tournée n'est pas chargée (planifiee / chargement / packaging_annule)
 *     et que son arrêt est encore en attente ; sinon (tournée chargée ou en
 *     cours) le changement est refusé — il faut d'abord annuler le
 *     chargement ou ignorer l'arrêt. Un arrêt déjà « ignoré » reste dans
 *     l'historique de sa tournée et ne bloque pas ;
 *   - les rendez-vous QG sont recalculés pour TOUTE la journée à chaque
 *     changement ; les familles dont l'heure change (ou qui en reçoivent
 *     une) sont prévenues par email une fois la campagne démarrée, la
 *     famille qui repasse en livraison est prévenue de l'annulation.
 *
 * Toutes les méthodes lèvent une \RuntimeException au message prêt à
 * afficher quand le changement est refusé.
 */
class LivraisonChangementService
{
    /** Statuts de tournée où retirer/ajouter un arrêt reste sans risque. */
    private const STATUTS_TOURNEE_MODIFIABLES = ['planifiee', 'chargement', 'packaging_annule'];

    public function __construct(
        private readonly RouteMutationService $mutation,
        private readonly RetraitHqSchedulingService $retraitHqScheduling,
        private readonly RetraitHqNotificationService $retraitHqNotification,
        private readonly NotificationCenterService $notificationCenter,
    ) {}

    /**
     * Bascule se_deplace. Recalcule TOUJOURS le planning QG de la journée
     * (le nombre total de familles se_deplace a pu changer).
     */
    public function changerSeDeplace(Livraison $livraison, bool $seDeplace): void
    {
        if ($seDeplace) {
            if ($livraison->id_benevole_impose !== null) {
                throw new \RuntimeException(
                    'Cette famille est imposée à un chauffeur : retirez d\'abord l\'imposition avant de la marquer « se déplace au QG ».'
                );
            }

            // Déjà au QG (se_deplace déjà vrai) : rien à dégager.
            if (!$livraison->se_deplace) {
                $this->degagerDeLaTournee($livraison);
            }

            $livraison->se_deplace = true;
            $livraison->save();
            $this->replanifierJournee($livraison);

            return;
        }

        if ($livraison->se_deplace && $livraison->statut === 'livree') {
            throw new \RuntimeException('Le colis de cette famille a déjà été remis au QG : plus de changement possible.');
        }

        $avaitUnRendezVous = $livraison->heure_arrivee_prevue_hq !== null;

        $livraison->se_deplace = false;
        $livraison->heure_arrivee_prevue_hq = null;
        $livraison->statut_retrait_hq = null;
        $livraison->save();

        $this->replanifierJournee($livraison);

        // La famille attendait un rendez-vous au QG : on lui dit qu'elle
        // n'a plus à venir (sa livraison est de nouveau à planifier).
        if ($avaitUnRendezVous && $this->campagneDe($livraison)->statut === 'en_cours') {
            $this->retraitHqNotification->notifierAnnulation($livraison);
        }
    }

    /**
     * Retire une famille de la campagne (09/10/2026) — « ajoutée par erreur ».
     * Mêmes garde-fous que les autres changements : refus si la famille est
     * livrée/remise, si sa tournée est chargée ou en cours (arrêt pas ignoré)
     * ou si le conditionnement a commencé (colis déjà préparés : à annuler
     * d'abord dans Packaging).
     *
     * Un arrêt IGNORÉ de la famille est supprimé avec elle : la clé étrangère
     * etapes_route.id_livraison est en nullOnDelete, l'arrêt resterait sinon
     * dans sa tournée comme un faux arrêt « retour QG ». Les incidents ouverts
     * qui la concernent sont fermés, le rendez-vous QG éventuel est annulé
     * (email) et le planning de la journée recalculé.
     */
    public function retirerDeLaCampagne(Livraison $livraison): void
    {
        $this->refuserSiConditionnementCommence($livraison, 'retirée de la campagne');

        $avaitUnRendezVous = $livraison->se_deplace && $livraison->heure_arrivee_prevue_hq !== null;

        DB::transaction(function () use ($livraison) {
            $this->degagerDeLaTournee($livraison);

            // Arrêts restants (ignorés, ou tournée annulée) : sinon nullOnDelete.
            EtapeRoute::where('id_livraison', $livraison->id)->delete();
            $this->fermerIncidentsDe($livraison);

            $livraison->delete();
        });

        if ($livraison->se_deplace) {
            $this->replanifierJournee($livraison);

            if ($avaitUnRendezVous && $this->campagneDe($livraison)->statut === 'en_cours') {
                $this->retraitHqNotification->notifierAnnulation($livraison);
            }
        }
    }

    /**
     * Remet une famille confirmée par erreur à « à contacter » (09/10/2026) :
     * créneaux, se_deplace (et son rendez-vous QG), chauffeur imposé et place
     * dans une tournée non chargée sont effacés — la famille est de nouveau
     * à joindre. Mêmes refus que retirerDeLaCampagne().
     */
    public function reinitialiserContact(Livraison $livraison): void
    {
        if ($livraison->statut_contact !== 'confirme') {
            throw new \RuntimeException('Seule une famille confirmée peut être réinitialisée.');
        }

        $this->refuserSiConditionnementCommence($livraison, 'réinitialisée');

        $avaitUnRendezVous = $livraison->se_deplace && $livraison->heure_arrivee_prevue_hq !== null;
        $etaitSeDeplace = $livraison->se_deplace;

        DB::transaction(function () use ($livraison) {
            $this->degagerDeLaTournee($livraison);

            $livraison->creneaux()->delete();
            $livraison->forceFill([
                'statut_contact' => 'a_contacter',
                'motif_statut_contact' => null,
                'statut' => 'non_assignee',
                'se_deplace' => false,
                'heure_arrivee_prevue_hq' => null,
                'statut_retrait_hq' => null,
                'id_benevole_impose' => null,
            ])->save();
        });

        if ($etaitSeDeplace) {
            $this->replanifierJournee($livraison);

            if ($avaitUnRendezVous && $this->campagneDe($livraison)->statut === 'en_cours') {
                $this->retraitHqNotification->notifierAnnulation($livraison);
            }
        }
    }

    /**
     * « Prendre en charge » : $idBenevole s'engage à livrer cette famille
     * (livraison imposée). Retire d'abord la famille de sa tournée
     * classique si besoin, puis — campagne démarrée et famille confirmée —
     * l'ajoute à la tournée imposée du chauffeur.
     */
    public function prendreEnCharge(Livraison $livraison, int $idBenevole): void
    {
        if ($livraison->se_deplace) {
            throw new \RuntimeException('Cette famille vient chercher son colis au QG : retirez d\'abord « se déplace au QG » avant de l\'imposer à un chauffeur.');
        }

        if (PersonneDesactivee::estDesactivee($idBenevole)) {
            throw new \RuntimeException('Cette personne est désactivée : elle ne peut pas prendre de livraison.');
        }

        $this->vehiculeDuChauffeur($idBenevole);

        if ($livraison->id_benevole_impose === $idBenevole) {
            $this->ajouterALaTourneeImposee($livraison);

            return;
        }

        $this->degagerDeLaTournee($livraison);

        $livraison->id_benevole_impose = $idBenevole;
        $livraison->save();

        $this->ajouterALaTourneeImposee($livraison);
    }

    /**
     * Résolution d'un incident « livraison ignorée » (09/10/2026) : la famille
     * redevient à planifier (non_assignee) — elle sera reprise à la prochaine
     * génération de routes. L'arrêt ignoré reste dans l'historique de sa tournée.
     */
    public function remettreAPlanifier(Livraison $livraison): void
    {
        $this->degagerDeLaTournee($livraison);
    }

    /**
     * Résolution d'un incident « livraison ignorée » : ajoute la famille à une
     * tournée EXISTANTE de la même campagne (tournée non chargée uniquement —
     * le colis de cette famille n'est de toute façon pas dans le véhicule d'une
     * tournée chargée ou en cours). Le chauffeur est prévenu par RouteMutationService.
     */
    public function ajouterALaTournee(Livraison $livraison, RouteLivraison $route): void
    {
        if ($route->id_campagne !== $livraison->id_campagne) {
            throw new \RuntimeException('Cette tournée appartient à une autre campagne.');
        }

        if (!in_array($route->statut, self::STATUTS_TOURNEE_MODIFIABLES, true)) {
            throw new \RuntimeException(
                "La tournée #{$route->id} est déjà chargée ou en cours : choisissez une tournée qui n'est pas encore partie."
            );
        }

        $famille = Famille::find($livraison->id_famille);
        if ($famille === null || $famille->latitude === null || $famille->longitude === null) {
            throw new \RuntimeException('Cette famille n\'a pas de coordonnées : impossible de l\'ajouter à une tournée.');
        }

        $this->degagerDeLaTournee($livraison);

        $this->mutation->ajouterLivraison($route, $livraison->fresh());
    }

    /**
     * Retire l'imposition : la famille quitte la tournée imposée de son
     * chauffeur (mêmes règles de blocage que ci-dessus) et redevient
     * éligible à la génération.
     */
    public function retirerImposition(Livraison $livraison): void
    {
        if ($livraison->id_benevole_impose === null) {
            return;
        }

        $this->degagerDeLaTournee($livraison);

        $livraison->id_benevole_impose = null;
        $livraison->save();
    }

    /**
     * À appeler quand une livraison PASSE à « confirmé » (appel manuel ou
     * formulaire public). Avant le démarrage de la campagne, rien à faire :
     * le démarrage s'en charge pour toutes les familles déjà confirmées.
     * Après : rendez-vous QG + email pour une famille se_deplace, ajout à
     * la tournée du chauffeur pour une famille imposée.
     */
    public function apresConfirmation(Livraison $livraison): void
    {
        if ($this->campagneDe($livraison)->statut !== 'en_cours') {
            return;
        }

        if ($livraison->se_deplace) {
            $this->replanifierJournee($livraison);

            return;
        }

        if ($livraison->id_benevole_impose !== null) {
            $this->ajouterALaTourneeImposee($livraison);
        }
    }

    // ── Internes ─────────────────────────────────────────────────────────

    /**
     * Sort la livraison de sa tournée active, ou refuse. Voir le docblock de
     * classe pour les règles. Remet toujours livraisons.statut à
     * non_assignee quand la famille n'est plus dans une tournée.
     */
    private function degagerDeLaTournee(Livraison $livraison): void
    {
        if ($livraison->statut === 'livree') {
            throw new \RuntimeException('Cette famille a déjà été livrée : plus de changement possible.');
        }

        $etape = EtapeRoute::where('id_livraison', $livraison->id)
            ->whereHas('route', fn($q) => $q->where('statut', '!=', 'annulee'))
            ->orderByDesc('id')
            ->first();

        if ($etape === null) {
            if ($livraison->statut !== 'non_assignee') {
                $livraison->update(['statut' => 'non_assignee']);
            }

            return;
        }

        if ($etape->statut === 'livree') {
            throw new \RuntimeException('Cette famille a déjà été livrée : plus de changement possible.');
        }

        // Arrêt ignoré : il reste dans l'historique de sa tournée, la
        // famille redevient simplement à planifier.
        if ($etape->statut === 'ignoree') {
            $livraison->update(['statut' => 'non_assignee']);

            return;
        }

        $route = RouteLivraison::findOrFail($etape->id_route);

        if (!in_array($route->statut, self::STATUTS_TOURNEE_MODIFIABLES, true)) {
            throw new \RuntimeException(
                "Cette famille est déjà engagée dans la tournée #{$route->id} (chargée ou en cours) : "
                . 'annulez le chargement ou ignorez l\'arrêt avant de changer son statut.'
            );
        }

        $this->mutation->retirerLivraison($route, $etape);
        $livraison->refresh();
    }

    /**
     * Ajoute la famille à la tournée imposée (sans créneau) de son
     * chauffeur, ou en crée une. Sans effet tant que la campagne n'est pas
     * démarrée, que la famille n'est pas confirmée, déjà dans une tournée,
     * ou sans coordonnées (elle reste alors dans « non couvertes »).
     */
    private function ajouterALaTourneeImposee(Livraison $livraison): void
    {
        $campagne = $this->campagneDe($livraison);

        if ($campagne->statut !== 'en_cours'
            || $livraison->statut_contact !== 'confirme'
            || $livraison->statut !== 'non_assignee'
            || $livraison->id_benevole_impose === null) {
            return;
        }

        $famille = Famille::find($livraison->id_famille);
        if ($famille === null || $famille->latitude === null || $famille->longitude === null) {
            return;
        }

        $route = RouteLivraison::where('id_campagne', $campagne->id)
            ->where('id_benevole', $livraison->id_benevole_impose)
            ->whereNull('creneau')
            ->whereIn('statut', self::STATUTS_TOURNEE_MODIFIABLES)
            ->orderBy('id')
            ->first();

        if ($route !== null) {
            $this->mutation->ajouterLivraison($route, $livraison);

            return;
        }

        $vehicule = $this->vehiculeDuChauffeur($livraison->id_benevole_impose);

        $this->mutation->construirePersonnalisee(
            $campagne,
            $livraison->id_benevole_impose,
            $vehicule,
            [$livraison->id],
            null,
            null,
            autoriserImposees: true,
        );
    }

    /**
     * Ferme les incidents ouverts d'une famille retirée de la campagne : sans
     * livraison (id_livraison passe à null), ils n'identifieraient plus personne.
     * Aucun effet de bord, comme IncidentResolutionService::forcerResolutionPourCampagne().
     */
    private function fermerIncidentsDe(Livraison $livraison): void
    {
        RouteIncident::ouverts()->where('id_livraison', $livraison->id)->get()->each(function (RouteIncident $incident) {
            $incident->update([
                'statut' => 'resolu',
                'notes' => trim(($incident->notes ?? '') . "\n[Résolu : la famille a été retirée de la campagne]"),
            ]);
            $this->notificationCenter->resoudreParDonnee('id_incident', $incident->id);
        });
    }

    /** Un conditionnement entamé (colis préparés) bloque retrait et réinitialisation. */
    private function refuserSiConditionnementCommence(Livraison $livraison, string $action): void
    {
        if (in_array($livraison->statut_conditionnement, ['en_cours', 'prete'], true)) {
            throw new \RuntimeException(
                "Le conditionnement de cette famille a commencé : annulez-le d'abord dans Packaging avant qu'elle soit {$action}."
            );
        }
    }

    /** Campagne de la livraison — requête typée plutôt que la relation (typée `Model` par l'analyse statique). */
    private function campagneDe(Livraison $livraison): Campagne
    {
        return Campagne::findOrFail($livraison->id_campagne);
    }

    /** id du type de véhicule du profil du chauffeur, ou refus. */
    private function vehiculeDuChauffeur(int $idBenevole): int
    {
        $profil = BenevoleProfil::where('id_personne', $idBenevole)->with('vehiculeType')->first();

        if (!$profil || !$profil->vehiculeType || (float) $profil->vehiculeType->capacite_kg <= 0) {
            throw new \RuntimeException('Ce bénévole n\'a pas de véhicule connu : impossible de lui confier une livraison.');
        }

        return (int) $profil->vehiculeType->id;
    }

    /**
     * Recalcule les rendez-vous QG de la journée de cette livraison et
     * prévient (campagne démarrée) les familles dont l'heure a changé ou
     * qui viennent d'en recevoir une.
     */
    private function replanifierJournee(Livraison $livraison): void
    {
        if ($livraison->id_campagne_journee === null) {
            return;
        }

        $journee = CampagneJournee::find($livraison->id_campagne_journee);
        if ($journee === null) {
            return;
        }

        $campagne = $this->campagneDe($livraison);
        $avant = $this->heuresQg($livraison->id_campagne, $journee->id);

        $replanifiees = $this->retraitHqScheduling->planifierPour($campagne, $journee);

        if ($campagne->statut !== 'en_cours') {
            return;
        }

        foreach ($replanifiees as $replanifiee) {
            if (($avant[$replanifiee->id] ?? null) !== $this->formaterHeure($replanifiee->heure_arrivee_prevue_hq)) {
                $this->retraitHqNotification->notifierPour($replanifiee);
            }
        }
    }

    /** @return array<int, string|null> id livraison => heure de rendez-vous QG actuelle */
    private function heuresQg(int $idCampagne, int $idJournee): array
    {
        return Livraison::where('id_campagne', $idCampagne)
            ->where('id_campagne_journee', $idJournee)
            ->where('statut_contact', 'confirme')
            ->where('se_deplace', true)
            ->get(['id', 'heure_arrivee_prevue_hq'])
            ->mapWithKeys(fn(Livraison $l) => [$l->id => $this->formaterHeure($l->heure_arrivee_prevue_hq)])
            ->all();
    }

    private function formaterHeure(?\DateTimeInterface $heure): ?string
    {
        return $heure?->format('Y-m-d H:i:s');
    }
}
