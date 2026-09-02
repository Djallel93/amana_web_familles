<?php
// app/Services/RouteGenerationService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Models\BenevoleProfil;
use App\Models\BenevoleDisponibilite;
use App\Models\Campagne;
use App\Models\EtapeRoute;
use App\Models\Livraison;
use App\Models\RouteLivraison;
use App\Support\Creneau;
use App\Support\RouteOptimizationConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrateur du clustering/assignation/TSP — voir le prompt du
 * 30/08/2026 §3.3 pour la séquence complète. Aucune des étapes
 * elles-mêmes n'est réécrite ici (voir ClusteringService/
 * VehicleAssignmentService/ClusterSplitService/TspOptimizationService) :
 * cette classe ne fait que les enchaîner dans le bon ordre, résoudre le
 * QG/les livraisons imposées, et persister le résultat.
 *
 * Décision d'implémentation confirmée le 31/08/2026 après avoir présenté
 * les alternatives à l'association (voir échange du même jour) :
 *
 * PRIORITÉ INFLEXIBLES (§3.3 point 4) : appliquée au moment de constituer
 * le pool d'un créneau, PAS à l'intérieur du clustering lui-même (qui
 * reste purement géographique et mélange inflexibles/flexibles dans un
 * même cluster une fois le pool constitué). Concrètement : si la capacité
 * totale confirmée pour ce créneau est insuffisante pour tout le monde,
 * les familles flexibles (plusieurs créneaux confirmés) les MOINS
 * prioritaires (criticité la plus basse) sont retirées du pool en
 * premier, jusqu'à ce que le pool restant tienne dans la capacité
 * estimée — elles seront reconsidérées automatiquement aux créneaux
 * suivants (la requête les resélectionne tant qu'elles sont
 * non_assignee). C'est une priorité au niveau de la CONSTITUTION du pool
 * (mélange volontairement autorisé entre inflexibles et flexibles dans un
 * même véhicule quand la capacité le permet, pour ne pas gaspiller de
 * place — voir l'alternative "deux passes séparées" écartée le même jour
 * car elle réserve des véhicules entiers aux inflexibles même quand il
 * reste de la place pour des flexibles), pas une garantie individuelle
 * une fois le clustering géographique appliqué.
 *
 * Note : une version précédente de cette classe réduisait aussi la
 * capacité disponible d'un bénévole selon ses livraisons imposées — retiré
 * le 31/08/2026, c'était une erreur (voir vehiculesDisponiblesPour()) :
 * un colis imposé est récupéré en fin de tournée, une fois le véhicule
 * déjà vidé, jamais en concurrence avec la capacité d'une tournée créneau.
 */
class RouteGenerationService
{
    public function __construct(
        private readonly ClusteringService $clustering,
        private readonly VehicleAssignmentService $assignment,
        private readonly TspOptimizationService $tsp,
        private readonly GeoCalculationService $geo,
    ) {
    }

    /**
     * Génère les tournées d'une campagne : livraisons imposées d'abord
     * (hors créneau), puis un cycle clustering→assignation→TSP par
     * créneau, dans l'ordre chronologique de Creneau::TOUS — voir §3.3.
     *
     * @return array{routes_creees: int, imposees: int, par_creneau: array<string, array{routes_creees: int, non_couvertes: int}>}
     */
    public function genererPourCampagne(Campagne $campagne): array
    {
        $hq = RouteOptimizationConfig::coordonneesHq();

        if ($hq === null) {
            throw new \RuntimeException('Coordonnées QG non configurées — voir Paramètres avant de lancer un clustering.');
        }

        $routesImposees = $this->resoudreLivraisonsImposees($campagne, $hq);

        $parCreneau = [];
        foreach (Creneau::TOUS as $creneau) {
            $parCreneau[$creneau] = $this->genererPourCreneau($campagne, $creneau, $hq);
        }

        return [
            'routes_creees' => count($routesImposees) + array_sum(array_column($parCreneau, 'routes_creees')),
            'imposees' => count($routesImposees),
            'par_creneau' => $parCreneau,
        ];
    }

    /**
     * Livraisons confirmées, non assignées, dont le créneau confirmé n'a
     * jamais pu être couvert — à afficher tel quel sur le tableau de bord
     * admin (§3.3 point 7 : "raise a visible admin-board item"). Calculé
     * à la volée plutôt que persisté (même choix que
     * Campagne::nombre_menages, voir Patch 1) : ce n'est jamais qu'une
     * requête sur un état déjà présent (statut_contact/statut), pas un
     * fait à part qui pourrait diverger.
     */
    public function livraisonsNonCouvertes(Campagne $campagne): Collection
    {
        return Livraison::where('id_campagne', $campagne->id)
            ->where('statut', 'non_assignee')
            ->where('statut_contact', 'confirme')
            ->with('famille:id,nom,prenom,adresse')
            ->get();
    }

    /**
     * Re-clustering SCOPÉ à un pool précis de livraisons orphelines
     * (voir le prompt §3.3 point 8 : bénévole absent → les livraisons non
     * livrées de sa tournée repassent non_assignee, puis "admin manually
     * triggers a re-cluster scoped to just that pool against currently-
     * available drivers/vehicles — not a full campaign recompute") —
     * déclenché depuis ChargementController après un incident
     * benevole_absent, PAS un recalcul de toute la campagne.
     *
     * $idBenevoleExclu : le bénévole signalé absent, jamais reproposé
     * pour ce pool (ses autres tournées de la journée ne sont pas
     * remises en cause, seule cette ré-affectation l'exclut).
     *
     * @param int[] $idsLivraisons
     * @return array{routes_creees: int, non_couvertes: int}
     */
    public function relancerPourLivraisonsOrphelines(Campagne $campagne, array $idsLivraisons, int $idBenevoleExclu): array
    {
        $hq = RouteOptimizationConfig::coordonneesHq();
        if ($hq === null) {
            throw new \RuntimeException('Coordonnées QG non configurées — voir Paramètres.');
        }

        $livraisons = Livraison::whereIn('id', $idsLivraisons)
            ->where('statut', 'non_assignee')
            ->with('famille:id,latitude,longitude,id_quartier')
            ->get()
            ->filter(fn (Livraison $l) => $l->famille->latitude !== null && $l->famille->longitude !== null)
            ->values();

        if ($livraisons->isEmpty()) {
            return ['routes_creees' => 0, 'non_couvertes' => 0];
        }

        // Toutes les livraisons orphelines d'UNE MÊME tournée partagent le
        // même créneau (ou aucun, si la tournée était imposée) — voir
        // resoudreLivraisonsImposees()/genererPourCreneau(), une tournée
        // n'a jamais qu'un seul créneau. On le retrouve depuis n'importe
        // laquelle des livraisons du pool.
        $creneau = $livraisons->first()->etapesRoute()->with('route')->first()?->route?->creneau;

        $vehicules = $creneau !== null
            ? $this->vehiculesDisponiblesPour($campagne, $creneau)
            : [];
        $vehicules = array_values(array_filter($vehicules, fn (array $v) => $v['id_benevole'] !== $idBenevoleExclu));

        if (empty($vehicules)) {
            return ['routes_creees' => 0, 'non_couvertes' => $livraisons->count()];
        }

        $plafondPoids = max(array_column($vehicules, 'capacite_kg'));
        $livraisonsArray = $livraisons->map(fn (Livraison $l) => $this->versArrayClustering($l))->all();

        $clusters = $this->clustering->identifierClusters($livraisonsArray, $hq, $plafondPoids);
        $resultat = $this->assignment->assigner($clusters, $vehicules);

        $routesCreees = 0;
        foreach ($resultat['assignations'] as $assignation) {
            $ordonnees = $this->tsp->optimiser($assignation['cluster']['livraisons'], $hq);
            $this->creerRoute(
                $campagne,
                $assignation['vehicule']['id_benevole'],
                $assignation['vehicule']['id_vehicule_type'],
                $creneau,
                $ordonnees,
                $hq,
            );
            $routesCreees++;
        }

        $nonCouvertes = array_sum(array_map(fn (array $c) => count($c['livraisons']), $resultat['non_places']));

        return ['routes_creees' => $routesCreees, 'non_couvertes' => $nonCouvertes];
    }

    // ── Livraisons imposées ──────────────────────────────────────────────

    /**
     * Résout les livraisons imposées (id_benevole_impose) — retirées du
     * pool créneau, pré-assignées directement, hors correspondance
     * créneau (voir §2/§3.3 point 1). Une route SANS créneau (routes.creneau
     * = null, voir 2026_08_31_000400_make_routes_creneau_nullable.php) par
     * bénévole concerné, regroupant toutes ses livraisons imposées de
     * cette campagne.
     *
     * @return RouteLivraison[]
     */
    private function resoudreLivraisonsImposees(Campagne $campagne, array $hq): array
    {
        $livraisonsImposees = Livraison::where('id_campagne', $campagne->id)
            ->where('statut', 'non_assignee')
            ->whereNotNull('id_benevole_impose')
            ->with('famille:id,latitude,longitude,id_quartier')
            ->get()
            ->filter(fn (Livraison $l) => $l->famille->latitude !== null && $l->famille->longitude !== null)
            ->groupBy('id_benevole_impose');

        $routes = [];

        foreach ($livraisonsImposees as $idBenevole => $groupe) {
            $profil = BenevoleProfil::where('id_personne', $idBenevole)->with('vehiculeType')->first();

            if (!$profil || !$profil->vehiculeType) {
                // Pas de véhicule connu pour ce bénévole imposé — reste
                // non_assignee, remontera dans livraisonsNonCouvertes()
                // uniquement si également statut_contact=confirme.
                continue;
            }

            $livraisonsArray = $groupe->map(fn (Livraison $l) => $this->versArrayClustering($l))->all();
            $ordonnees = $this->tsp->optimiser($livraisonsArray, $hq);

            $routes[] = $this->creerRoute($campagne, $idBenevole, $profil->vehiculeType->id, null, $ordonnees, $hq);
        }

        return $routes;
    }

    // ── Cycle par créneau ────────────────────────────────────────────────

    /**
     * @return array{routes_creees: int, non_couvertes: int}
     */
    private function genererPourCreneau(Campagne $campagne, string $creneau, array $hq): array
    {
        $pool = Livraison::where('id_campagne', $campagne->id)
            ->where('statut', 'non_assignee')
            ->where('statut_contact', 'confirme')
            ->whereNull('id_benevole_impose')
            ->whereHas('creneaux', fn ($q) => $q->where('creneau', $creneau))
            ->with(['famille:id,latitude,longitude,id_quartier', 'creneaux'])
            ->get()
            ->filter(fn (Livraison $l) => $l->famille->latitude !== null && $l->famille->longitude !== null)
            ->values();
        // Familles sans coordonnées résolues (géocodage en attente/échoué,
        // voir App\Jobs\ResoudreAdresseFamille) exclues du clustering plutôt
        // que routées vers (0, 0) — restent non_assignee, remontent dans
        // livraisonsNonCouvertes() comme n'importe quelle autre livraison
        // confirmée jamais couverte (pas de distinction de cause à ce
        // niveau, l'admin voit l'adresse et peut déclencher un
        // re-géocodage depuis l'écran famille).

        $vehicules = $this->vehiculesDisponiblesPour($campagne, $creneau);

        if ($pool->isEmpty() || empty($vehicules)) {
            return ['routes_creees' => 0, 'non_couvertes' => $pool->count()];
        }

        $poolRetenu = $this->prioriserInflexibles($pool, $vehicules);

        $plafondPoids = max(array_column($vehicules, 'capacite_kg'));

        $livraisonsArray = $poolRetenu->map(fn (Livraison $l) => $this->versArrayClustering($l))->all();

        $clusters = $this->clustering->identifierClusters($livraisonsArray, $hq, $plafondPoids);
        $resultat = $this->assignment->assigner($clusters, $vehicules);

        $routesCreees = 0;
        foreach ($resultat['assignations'] as $assignation) {
            $ordonnees = $this->tsp->optimiser($assignation['cluster']['livraisons'], $hq);
            $this->creerRoute(
                $campagne,
                $assignation['vehicule']['id_benevole'],
                $assignation['vehicule']['id_vehicule_type'],
                $creneau,
                $ordonnees,
                $hq,
            );
            $routesCreees++;
        }

        $nonCouvertes = array_sum(array_map(fn (array $c) => count($c['livraisons']), $resultat['non_places']));
        // Les livraisons écartées du pool par prioriserInflexibles() (pool
        // total moins pool retenu) restent non_assignee et seront
        // reconsidérées à un créneau ultérieur — pas comptées ici comme
        // "non couvertes" pour CE créneau, ce n'en est pas un échec.

        return ['routes_creees' => $routesCreees, 'non_couvertes' => $nonCouvertes];
    }

    /**
     * Véhicules confirmés disponibles pour ce créneau précis.
     *
     * CORRECTION du 31/08/2026 : une version précédente réduisait ici la
     * capacité disponible du montant déjà engagé sur des livraisons
     * imposées à ce même bénévole. C'était une erreur de modèle mental —
     * un bénévole récupère son colis imposé en fin de tournée/journée,
     * une fois son véhicule déjà vidé des livraisons normales de ce
     * créneau, pas en même temps. Les tournées "créneau" tournent donc
     * TOUJOURS à pleine capacité nominale du véhicule, sans lien avec ses
     * livraisons imposées éventuelles (qui restent une tournée à part,
     * voir resoudreLivraisonsImposees()).
     */
    private function vehiculesDisponiblesPour(Campagne $campagne, string $creneau): array
    {
        $disponibilites = BenevoleDisponibilite::where('id_campagne', $campagne->id)
            ->where('statut', 'confirme')
            ->whereHas('creneaux', fn ($q) => $q->where('creneau', $creneau))
            ->get();

        $vehicules = [];

        foreach ($disponibilites as $dispo) {
            $profil = BenevoleProfil::where('id_personne', $dispo->id_personne)->with('vehiculeType')->first();

            if (!$profil || !$profil->vehiculeType) {
                continue;
            }

            $vehicules[] = [
                'id_benevole' => $dispo->id_personne,
                'id_vehicule_type' => $profil->vehiculeType->id,
                'capacite_kg' => (float) $profil->vehiculeType->capacite_kg,
                'nombre_part_max' => (int) $profil->vehiculeType->nombre_part_max,
            ];
        }

        return $vehicules;
    }

    /**
     * Retire du pool, par ordre de criticité CROISSANTE, les livraisons de
     * familles FLEXIBLES (plusieurs créneaux confirmés) jusqu'à ce que le
     * pool restant tienne dans la capacité totale estimée des véhicules
     * disponibles — voir docblock de classe, point 1. Les familles
     * inflexibles (un seul créneau confirmé, forcément celui-ci) ne sont
     * jamais retirées.
     *
     * @param Collection<int, Livraison> $pool
     * @param array<int, array{capacite_kg: float}> $vehicules
     * @return Collection<int, Livraison>
     */
    private function prioriserInflexibles(Collection $pool, array $vehicules): Collection
    {
        $capaciteDisponible = array_sum(array_column($vehicules, 'capacite_kg'));

        $poidsTotal = (float) $pool->sum('poids_kg');
        if ($poidsTotal <= $capaciteDisponible) {
            return $pool; // Tout tient, rien à retirer.
        }

        [$inflexibles, $flexibles] = $pool->partition(fn (Livraison $l) => $l->creneaux->count() <= 1);

        // CORRECTION du 31/08/2026 : sortByDesc, pas sortBy — les familles
        // flexibles les plus PRIORITAIRES (criticité la plus haute) doivent
        // être servies/retenues en premier ; l'ordre croissant précédent
        // retenait par erreur les moins urgentes et excluait les plus
        // urgentes en cas de pool trop grand pour la capacité disponible.
        $flexibles = $flexibles->sortByDesc(fn (Livraison $l) => $l->famille->criticite ?? 0)->values();

        $poidsRetenu = (float) $inflexibles->sum('poids_kg');
        $retenues = $inflexibles;

        foreach ($flexibles as $livraison) {
            if ($poidsRetenu + $livraison->poids_kg > $capaciteDisponible) {
                continue; // Reste non_assignee, reconsidérée à un créneau ultérieur.
            }
            $retenues->push($livraison);
            $poidsRetenu += $livraison->poids_kg;
        }

        return $retenues->values();
    }

    // ── Construction/persistance des tournées ───────────────────────────

    /**
     * @param array{id_livraison: int, latitude: float, longitude: float}[] $livraisonsOrdonnees Sortie de TspOptimizationService::optimiser()
     */
    private function creerRoute(
        Campagne $campagne,
        int $idBenevole,
        int $idVehiculeType,
        ?string $creneau,
        array $livraisonsOrdonnees,
        array $hq,
    ): RouteLivraison {
        return DB::transaction(function () use ($campagne, $idBenevole, $idVehiculeType, $creneau, $livraisonsOrdonnees, $hq) {
            $poidsTotal = array_sum(array_column($livraisonsOrdonnees, 'poids_kg'));
            $distanceTotale = $this->geo->distanceTotaleRoute($livraisonsOrdonnees, $hq);

            $route = RouteLivraison::create([
                'id_campagne' => $campagne->id,
                'id_benevole' => $idBenevole,
                'id_vehicule_type' => $idVehiculeType,
                'creneau' => $creneau,
                'statut' => 'planifiee',
                'distance_totale_km' => $distanceTotale,
                'poids_total_kg' => $poidsTotal,
                'lien_maps' => $this->geo->construireLienMaps($livraisonsOrdonnees, $hq),
            ]);

            foreach ($livraisonsOrdonnees as $index => $livraison) {
                EtapeRoute::create([
                    'id_route' => $route->id,
                    'id_livraison' => $livraison['id_livraison'],
                    'ordre' => $index + 1,
                    'statut' => 'en_attente',
                ]);
            }

            Livraison::whereIn('id', array_column($livraisonsOrdonnees, 'id_livraison'))
                ->update(['statut' => 'assignee']);

            return $route;
        });
    }

    /**
     * Convertit une Livraison Eloquent (+ famille chargée) vers la forme
     * array attendue par tout le pipeline de clustering — voir
     * ClusteringService/VehicleAssignmentService/TspOptimizationService.
     * Coordonnées lues depuis famille.latitude/longitude, JAMAIS
     * dupliquées au niveau livraison (source de vérité unique — voir
     * FamilleConfirmationSyncService).
     */
    private function versArrayClustering(Livraison $livraison): array
    {
        return [
            'id_livraison' => $livraison->id,
            'latitude' => (float) $livraison->famille->latitude,
            'longitude' => (float) $livraison->famille->longitude,
            'nombre_personnes' => $livraison->nombre_personnes,
            'poids_kg' => (float) $livraison->poids_kg,
            'id_quartier' => $livraison->famille->id_quartier,
        ];
    }
}
