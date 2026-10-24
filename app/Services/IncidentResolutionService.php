<?php
// app/Services/IncidentResolutionService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Models\Personne;
use Amana\Shared\Models\VehiculeType;
use Amana\Shared\Services\NotificationCenterService;
use App\Jobs\ResoudreAdresseFamille;
use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use Illuminate\Support\Collection;

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
 * « Chauffeur indisponible » (18/10/2026) : resoudreChauffeurIndisponible() réassigne la
 * tournée à un chauffeur au véhicule de même type ou plus grand, ou la répartit entre
 * plusieurs chauffeurs (RouteMutationService::repartir()).
 */
class IncidentResolutionService
{
    public function __construct(
        private readonly RouteGenerationService $generationService,
        private readonly NotificationCenterService $notificationCenter,
        private readonly LivraisonChangementService $changements,
        private readonly ChauffeursConfirmesService $chauffeursConfirmes,
        private readonly RouteMutationService $mutations,
        private readonly RetraitHqNotificationService $retraitHq,
        private readonly IncidentDetectionService $detection,
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

            // 23/10/2026 : des familles restées sans tournée après le re-clustering ne doivent plus se perdre
            // en silence — elles ouvrent leur propre incident, avec ses actions de résolution.
            if (($resultat['non_couvertes'] ?? 0) > 0) {
                $this->detection->lever(
                    $incident->route->id_campagne,
                    'livraisons_non_couvertes',
                    'camp' . $incident->route->id_campagne,
                    $resultat['non_couvertes'] . ' famille(s) n\'ont pas pu être replacées après l\'absence du chauffeur #' . $incident->route->id_benevole . '.',
                );
            }

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

    /**
     * Données du formulaire « Résoudre » d'un incident « chauffeur indisponible » (18/10/2026) : chauffeurs
     * confirmés sur le créneau de la tournée, hors chauffeur actuel, hors chauffeurs « partis » (réponse « Non »
     * au retour au QG) et hors chauffeurs déjà en tournée sur ce créneau. `assez_grand` = véhicule de capacité
     * (kg) ET de nombre de colis ≥ à celui de la tournée : seuls ceux-là peuvent la reprendre en entier.
     *
     * @return array{resoluble: bool, message: string|null, id_route: int|null, id_route_non_retour?: int|null, nb_arrets: int, chauffeur_actuel: string|null, vehicule_actuel: array{type: string|null, capacite_kg: float, nombre_part_max: int}|null, candidats: list<array<string, mixed>>}
     */
    public function optionsChauffeurIndisponible(RouteIncident $incident): array
    {
        // Chargée par id (et non via la relation $incident->route) pour avoir un RouteLivraison typé.
        $route = RouteLivraison::find($incident->id_route);
        $vide = ['resoluble' => false, 'message' => null, 'id_route' => $route?->id, 'nb_arrets' => 0, 'chauffeur_actuel' => null, 'vehicule_actuel' => null, 'candidats' => []];

        if ($route === null) {
            return array_merge($vide, ['message' => 'La tournée de cet incident n\'existe plus : résolvez simplement l\'incident.']);
        }

        $benevole = $route->benevole;
        $chauffeur = $benevole instanceof Personne ? trim("{$benevole->prenom} {$benevole->nom}") : null;
        $vehicule = $route->vehiculeType;
        $capaciteActuelle = $vehicule instanceof VehiculeType ? (float) $vehicule->capacite_kg : 0.0;
        $partsActuelles = $vehicule instanceof VehiculeType ? (int) $vehicule->nombre_part_max : 0;

        // Tournée où le chauffeur a répondu « Non » : cible de « Remettre dans le pool » (RetourQgService).
        $routeNon = RouteLivraison::where('id_campagne', $route->id_campagne)
            ->where('id_benevole', $route->id_benevole)
            ->where('retour_qg', false)
            ->orderByDesc('id')
            ->first();

        $base = array_merge($vide, [
            'id_route_non_retour' => $routeNon?->id,
            'nb_arrets' => $route->etapes()->whereNotNull('id_livraison')->count(),
            'chauffeur_actuel' => $chauffeur,
            'vehicule_actuel' => ['type' => $vehicule instanceof VehiculeType ? $vehicule->type : null, 'capacite_kg' => $capaciteActuelle, 'nombre_part_max' => $partsActuelles],
        ]);

        if (!in_array($route->statut, RouteLivraison::STATUTS_PAS_PARTIE, true)) {
            return array_merge($base, ['message' => 'Cette tournée est déjà partie ou annulée : réassignez-la depuis Suivi livraison, ou résolvez simplement l\'incident.']);
        }

        $journee = $route->campagneJournee;
        if (!$journee instanceof CampagneJournee || $route->creneau === null) {
            return array_merge($base, ['resoluble' => true, 'message' => 'Cette tournée n\'a pas de créneau : réassignez-la depuis Suivi livraison.']);
        }

        $candidats = [];
        foreach ($this->generationService->chauffeursDisponibles($journee, $route->creneau) as $c) {
            if ($c['id_personne'] === $route->id_benevole || $c['occupe'] || $c['parti']) {
                continue;
            }
            $candidats[] = [
                'id_personne' => $c['id_personne'],
                'nom' => $c['nom'],
                'prenom' => $c['prenom'],
                'id_vehicule_type' => $c['id_vehicule_type'],
                'vehicule' => $c['vehicule'],
                'capacite_kg' => $c['capacite_kg'],
                'nombre_part_max' => $c['nombre_part_max'],
                'en_livraison' => $c['en_livraison'],
                'assez_grand' => $c['capacite_kg'] >= $capaciteActuelle && $c['nombre_part_max'] >= $partsActuelles,
            ];
        }

        return array_merge($base, ['resoluble' => true, 'candidats' => $candidats]);
    }

    /**
     * Résolution d'un incident « chauffeur indisponible » (18/10/2026).
     *
     * @param  string  $action  reassigner (id_benevole, véhicule de même type ou plus grand) | repartir (ids_benevoles, au moins 2)
     * @param  array{id_benevole?: int|null, ids_benevoles?: int[]|null}  $parametres
     *
     * @throws \RuntimeException message prêt à afficher quand l'action est refusée
     */
    public function resoudreChauffeurIndisponible(RouteIncident $incident, string $action, array $parametres = []): void
    {
        if ($incident->type !== 'chauffeur_indisponible' || $incident->statut !== 'ouvert') {
            throw new \RuntimeException('Cette action ne concerne qu\'un incident « chauffeur indisponible » ouvert.');
        }

        $options = $this->optionsChauffeurIndisponible($incident);
        if (!$options['resoluble'] || $options['candidats'] === [] && $options['message'] !== null) {
            throw new \RuntimeException($options['message'] ?? 'Cet incident ne peut pas être résolu ainsi.');
        }

        $candidats = collect($options['candidats'])->keyBy('id_personne');
        $route = RouteLivraison::find($incident->id_route);
        if ($route === null) {
            throw new \RuntimeException('La tournée de cet incident n\'existe plus.');
        }

        $note = match ($action) {
            'reassigner' => $this->suiteReassigner($route, $candidats, (int) ($parametres['id_benevole'] ?? 0)),
            'repartir' => $this->suiteRepartir($route, $candidats, array_map('intval', $parametres['ids_benevoles'] ?? [])),
            default => throw new \RuntimeException('Action inconnue.'),
        };

        $this->cloreAvecNote($incident, $note);
    }

    private function suiteReassigner(RouteLivraison $route, Collection $candidats, int $idBenevole): string
    {
        if ($idBenevole === 0) {
            throw new \RuntimeException('Choisissez un chauffeur.');
        }

        $candidat = $candidats->get($idBenevole);
        if ($candidat === null) {
            throw new \RuntimeException('Ce chauffeur n\'est pas disponible sur le créneau de cette tournée.');
        }
        if (!$candidat['assez_grand']) {
            throw new \RuntimeException('Le véhicule de ce chauffeur est plus petit que celui de la tournée : choisissez un véhicule de même type ou plus grand, ou répartissez la tournée.');
        }

        $this->mutations->reassigner($route, $idBenevole, (int) $candidat['id_vehicule_type']);

        return "Tournée #{$route->id} réassignée au chauffeur #{$idBenevole}.";
    }

    /**
     * @param  int[]  $idsBenevoles
     */
    private function suiteRepartir(RouteLivraison $route, Collection $candidats, array $idsBenevoles): string
    {
        $idsBenevoles = array_values(array_unique($idsBenevoles));
        if (count($idsBenevoles) < 2) {
            throw new \RuntimeException('Choisissez au moins deux chauffeurs pour répartir la tournée.');
        }

        $chauffeurs = [];
        foreach ($idsBenevoles as $id) {
            $candidat = $candidats->get($id);
            if ($candidat === null) {
                throw new \RuntimeException("Le chauffeur #{$id} n'est pas disponible sur le créneau de cette tournée.");
            }
            $chauffeurs[] = [
                'id_benevole' => $id,
                'id_vehicule_type' => (int) $candidat['id_vehicule_type'],
                'capacite_kg' => (float) $candidat['capacite_kg'],
            ];
        }

        $routes = $this->mutations->repartir($route, $chauffeurs);

        return 'Tournée #' . $route->id . ' répartie entre ' . count($routes) . ' chauffeurs (tournées ' . implode(', ', array_map(fn(RouteLivraison $r) => '#' . $r->id, $routes)) . ').';
    }

    /**
     * Résolution automatique (sans action humaine) : ex. chargement_annule quand la tournée est de nouveau
     * confirmée (23/10/2026). Note posée avec le marqueur d'auto-résolution.
     */
    public function resoudreAutomatiquement(RouteIncident $incident, string $raison): void
    {
        $incident->update([
            'statut' => 'resolu',
            'notes' => trim(($incident->notes ?? '') . "\n" . IncidentDetectionService::MARQUEUR_AUTO_RESOLU . ' ' . $raison),
        ]);
        $this->notificationCenter->resoudreParDonnee('id_incident', $incident->id);
    }

    // ── Aides à la résolution des autres types (23/10/2026) ─────────────

    /**
     * Données du formulaire « Résoudre » des types sans formulaire historique : de quoi décider sans quitter l'écran.
     *
     * @return array<string, mixed>
     */
    public function optionsAutres(RouteIncident $incident): array
    {
        return match ($incident->type) {
            'capacite' => $this->optionsCapacite($incident),
            'livraisons_non_couvertes' => ['familles' => $this->famillesNonCouvertes($incident)->map(fn(Livraison $l) => [
                'id_livraison' => $l->id,
                'nom' => trim("{$l->famille?->prenom} {$l->famille?->nom}"),
            ])->values()->all()],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function optionsCapacite(RouteIncident $incident): array
    {
        $route = $incident->id_route ? RouteLivraison::with(['vehiculeType', 'etapes.livraison.famille:id,nom,prenom'])->find($incident->id_route) : null;
        if ($route === null) {
            return ['resoluble' => false, 'message' => 'La tournée de cet incident n\'existe plus : résolvez simplement l\'incident.'];
        }

        $vehicule = $route->vehiculeType;
        $base = $this->optionsChauffeurIndisponible($incident);

        return [
            'resoluble' => in_array($route->statut, RouteLivraison::STATUTS_PAS_PARTIE, true),
            'message' => in_array($route->statut, RouteLivraison::STATUTS_PAS_PARTIE, true) ? null : 'Cette tournée est déjà partie ou annulée.',
            'poids_total_kg' => (float) $route->poids_total_kg,
            'capacite_kg' => $vehicule instanceof VehiculeType ? (float) $vehicule->capacite_kg : null,
            'arrets' => $route->etapes->filter(fn($e) => $e->livraison !== null)->map(fn($e) => [
                'id_etape' => $e->id,
                'famille' => trim("{$e->livraison->famille?->prenom} {$e->livraison->famille?->nom}"),
                'poids_kg' => (float) $e->livraison->poids_kg,
            ])->values()->all(),
            // Seuls les chauffeurs dont le véhicule est assez grand pour TOUTE la tournée.
            'candidats' => array_values(array_filter($base['candidats'] ?? [], fn($c) => $c['assez_grand'])),
        ];
    }

    /**
     * Résolution des types sans suite historique.
     *
     * @param  array<string, mixed>  $parametres
     * @return string|null Message à afficher (null : rien de spécial)
     *
     * @throws \RuntimeException message prêt à afficher quand l'action est refusée
     */
    public function resoudreAutre(RouteIncident $incident, string $action, array $parametres = []): ?string
    {
        if ($incident->statut !== 'ouvert') {
            throw new \RuntimeException('Cet incident n\'est plus ouvert.');
        }

        $livraison = $incident->id_livraison !== null ? Livraison::find($incident->id_livraison) : null;

        switch ([$incident->type, $action]) {
            case ['capacite', 'retirer']:
                $note = $this->suiteCapaciteRetirer($incident, array_map('intval', $parametres['ids_etapes'] ?? []));
                break;
            case ['capacite', 'diviser']:
                $route = $this->routeOuEchoue($incident);
                $nouvelle = $this->mutations->diviser($route);
                $note = "Tournée #{$route->id} divisée : nouvelle tournée #{$nouvelle->id}.";
                break;
            case ['capacite', 'reassigner']:
                $options = $this->optionsCapacite($incident);
                $candidat = collect($options['candidats'] ?? [])->firstWhere('id_personne', (int) ($parametres['id_benevole'] ?? 0));
                if ($candidat === null) {
                    throw new \RuntimeException('Choisissez un chauffeur au véhicule assez grand pour cette tournée.');
                }
                $route = $this->routeOuEchoue($incident);
                $this->mutations->reassigner($route, (int) $candidat['id_personne'], (int) $candidat['id_vehicule_type']);
                $note = "Tournée #{$route->id} confiée au chauffeur #{$candidat['id_personne']} (véhicule plus grand).";
                break;

            case ['se_deplace_remplace', 'prevenue']:
                $note = 'Famille prévenue.';
                break;
            case ['se_deplace_remplace', 'annuler_imposition']:
                $livraison ?? throw new \RuntimeException('La famille n\'est plus dans la campagne : résolvez simplement l\'incident.');
                $this->changements->retirerImposition($livraison);
                $this->changements->changerSeDeplace($livraison->fresh(), true);
                $note = 'Livraison imposée annulée : la famille revient au QG.';
                break;

            case ['livraisons_non_couvertes', 'retrait_qg']:
                $familles = $this->famillesNonCouvertes($incident);
                if ($familles->isEmpty()) {
                    throw new \RuntimeException('Plus aucune famille sans tournée : résolvez simplement l\'incident.');
                }
                foreach ($familles as $l) {
                    $this->changements->changerSeDeplace($l, true);
                }
                $note = $familles->count() . ' famille(s) basculée(s) en retrait au QG.';
                break;

            case ['famille_non_geocodee', 'regeocoder']:
                $livraison ?? throw new \RuntimeException('La famille n\'est plus dans la campagne.');
                ResoudreAdresseFamille::dispatch($livraison->id_famille);

                // Pas de clôture : l'incident se résout seul dès que l'adresse est localisée.
                return 'Localisation relancée : l\'incident se fermera tout seul si l\'adresse est trouvée.';

            case ['famille_injoignable', 'a_recontacter']:
                $livraison ?? throw new \RuntimeException('La famille n\'est plus dans la campagne.');
                // reinitialiserContact() exige une famille confirmée : ici elle est injoignable, on la repasse
                // simplement dans le suivi des contacts.
                $livraison->update(['statut_contact' => 'a_contacter', 'motif_statut_contact' => null]);
                $note = 'Famille remise à contacter.';
                break;
            case ['famille_injoignable', 'retirer']:
                $livraison ?? throw new \RuntimeException('La famille n\'est plus dans la campagne.');
                $this->changements->retirerDeLaCampagne($livraison);
                $note = 'Famille retirée de la campagne.';
                break;

            case ['email_echec', 'renvoyer']:
                $livraison ?? throw new \RuntimeException('La famille n\'est plus dans la campagne.');
                $issue = $this->retraitHq->envoyerPour($livraison);
                if ($issue !== RetraitHqNotificationService::ENVOYE) {
                    throw new \RuntimeException(match ($issue) {
                        RetraitHqNotificationService::SANS_EMAIL => 'Cette famille n\'a pas d\'adresse email : corrigez sa fiche ou appelez-la.',
                        RetraitHqNotificationService::NON_PLANIFIE => 'Aucun rendez-vous au QG n\'est planifié pour cette famille : rien à renvoyer.',
                        default => 'L\'envoi a de nouveau échoué : voir les notes de l\'incident.',
                    });
                }
                $note = 'Email renvoyé.';
                break;

            default:
                throw new \RuntimeException('Action inconnue pour ce type d\'incident.');
        }

        $this->cloreAvecNote($incident, $note);

        return $note;
    }

    private function routeOuEchoue(RouteIncident $incident): RouteLivraison
    {
        $route = $incident->id_route ? RouteLivraison::find($incident->id_route) : null;
        if ($route === null) {
            throw new \RuntimeException('La tournée de cet incident n\'existe plus : résolvez simplement l\'incident.');
        }
        if (!in_array($route->statut, RouteLivraison::STATUTS_PAS_PARTIE, true)) {
            throw new \RuntimeException('Cette tournée est déjà partie ou annulée : on ne peut plus la modifier ainsi.');
        }

        return $route;
    }

    /** @param int[] $idsEtapes */
    private function suiteCapaciteRetirer(RouteIncident $incident, array $idsEtapes): string
    {
        if ($idsEtapes === []) {
            throw new \RuntimeException('Choisissez au moins une famille à retirer de la tournée.');
        }
        $route = $this->routeOuEchoue($incident);
        $n = 0;
        foreach ($route->etapes()->whereIn('id', $idsEtapes)->get() as $etape) {
            $this->mutations->retirerLivraison($route, $etape);
            $n++;
        }
        if ($n === 0) {
            throw new \RuntimeException('Ces familles ne sont pas dans cette tournée.');
        }

        return "{$n} famille(s) retirée(s) de la tournée #{$route->id} (à replanifier).";
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Livraison> */
    private function famillesNonCouvertes(RouteIncident $incident)
    {
        return Livraison::where('id_campagne', $incident->idCampagneEffectif())
            ->where('statut_contact', 'confirme')
            ->where('statut', 'non_assignee')
            ->where('se_deplace', false)
            ->whereNull('id_benevole_impose')
            ->with('famille:id,nom,prenom,latitude,longitude')
            ->get()
            ->filter(fn(Livraison $l) => $l->famille?->latitude !== null && $l->famille?->longitude !== null)
            ->values();
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
