<?php
// app/Services/RouteGenerationService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Models\BenevoleProfil;
use Amana\Shared\Models\Personne;
use App\Models\BenevoleDisponibilite;
use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Models\EtapeRoute;
use App\Models\Livraison;
use App\Models\PersonneDesactivee;
use App\Models\RouteLivraison;
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
 *
 * SCOPING PAR JOURNÉE (05/09/2026, suivi du patch multi-jours du
 * 03/09/2026) : genererPourCreneau() (ex-genererPourCampagne()) prend désormais une CampagneJournee
 * explicite et obligatoire — un appel génère les tournées d'UNE journée
 * à la fois (l'appelant répète l'appel par journée pour une campagne
 * multi-jours, voir CampagneDetail.vue). Ce n'est plus un cas "optionnel"
 * niveau pipeline : depuis CampagnesController::store(), toute campagne a
 * toujours au moins une CampagneJournee (voir Campagne::ajouterJournee()),
 * donc aucune régression sur les campagnes mono-jour existantes — elles
 * n'ont simplement jamais qu'une seule journée à passer.
 *
 * EXCEPTION : resoudreLivraisonsImposees() reste volontairement
 * TRANSVERSE à toute la campagne, pas filtrée par journée — une livraison
 * imposée (id_benevole_impose) est récupérable n'importe quel jour de la
 * campagne, le bénévole gérant lui-même le timing. Une route d'imposées
 * peut donc mélanger des livraisons de plusieurs journées ; dans ce cas
 * (rare en pratique — les imposées sont en général 1-2 familles en une
 * fois) sa colonne id_campagne_journee reste NULL, à titre indicatif
 * seulement (voir creerRoute()) — cette route n'entre alors dans aucun
 * bucket de CampagneStatsService::calculer()['par_journee'], seulement
 * dans les totaux globaux.
 *
 * REFONTE DU 06/10/2026 (assistant de génération du hub campagne) :
 *   - la génération se fait désormais pour UN créneau d'UNE journée avec
 *     des chauffeurs CHOISIS (genererPourCreneauEtBenevoles()) au lieu
 *     d'enchaîner tous les créneaux d'une journée ;
 *   - les livraisons imposées sortent de ce cycle : leurs tournées (sans
 *     créneau, sans fenêtre horaire — le chauffeur les fait quand il veut)
 *     sont créées au démarrage de la campagne (genererRoutesImposees(),
 *     appelée par CampagneDemarrageService), puis alimentées au fil de
 *     l'eau (LivraisonChangementService) ;
 *   - un chauffeur qui a déjà une tournée active sur ce créneau n'est plus
 *     proposé (occupe) : pour lui ajouter une famille, on passe par
 *     « ajouter une livraison » sur Suivi livraison.
 */
class RouteGenerationService
{
    public function __construct(
        private readonly ClusteringService $clustering,
        private readonly VehicleAssignmentService $assignment,
        private readonly TspOptimizationService $tsp,
        private readonly GeoCalculationService $geo,
    ) {}

    /**
     * Crée les tournées des livraisons imposées (id_benevole_impose) —
     * appelée au démarrage de la campagne et à chaque ajout tardif. Une
     * tournée SANS créneau par chauffeur, transverse à toutes les journées
     * (voir docblock de classe) : le chauffeur la réalise quand il veut.
     *
     * @return RouteLivraison[]
     */
    public function genererRoutesImposees(Campagne $campagne): array
    {
        // Aucune imposée à router : pas besoin du QG (une campagne sans
        // famille imposée doit pouvoir démarrer même avant sa configuration).
        $aRouter = Livraison::where('id_campagne', $campagne->id)
            ->where('statut', 'non_assignee')
            ->where('statut_contact', 'confirme')
            ->whereNotNull('id_benevole_impose')
            ->where('se_deplace', false)
            ->exists();

        if (!$aRouter) {
            return [];
        }

        $hq = RouteOptimizationConfig::coordonneesHqPourCampagne($campagne);

        if ($hq === null) {
            throw new \RuntimeException('Coordonnées QG non configurées — voir Paramètres avant de lancer un clustering.');
        }

        return $this->resoudreLivraisonsImposees($campagne, $hq);
    }

    /**
     * Génère les tournées d'UN créneau d'UNE journée avec les chauffeurs
     * choisis (assistant « Génération des routes », mode automatique) :
     * clustering → assignation → TSP, scopé à ce créneau et à ces chauffeurs.
     * Les chauffeurs non sélectionnés, non disponibles ou déjà occupés sur
     * ce créneau sont ignorés (voir vehiculesDisponiblesPour()).
     *
     * @param int[] $idsBenevoles
     * @return array{routes_creees: int, non_couvertes: int}
     */
    public function genererPourCreneauEtBenevoles(Campagne $campagne, CampagneJournee $journee, string $creneau, array $idsBenevoles): array
    {
        $hq = RouteOptimizationConfig::coordonneesHqPourCampagne($campagne);

        if ($hq === null) {
            throw new \RuntimeException('Coordonnées QG non configurées — voir Paramètres avant de lancer un clustering.');
        }

        return $this->genererPourCreneau($campagne, $journee, $creneau, $hq, $idsBenevoles);
    }

    /**
     * Aperçu (sans rien écrire) affiché avant de confirmer la génération :
     * familles éligibles pour ce créneau, leur poids, la capacité des
     * chauffeurs choisis et les familles sans coordonnées (exclues du
     * clustering, voir genererPourCreneau()).
     *
     * @param int[] $idsBenevoles
     * @return array{familles: int, poids_kg: float, capacite_kg: float, sans_coordonnees: int, chauffeurs: int}
     */
    public function apercuCreneau(Campagne $campagne, CampagneJournee $journee, string $creneau, array $idsBenevoles): array
    {
        $pool = $this->poolPour($campagne, $journee, $creneau);
        $geocodees = $this->geocodees($pool);
        $vehicules = $this->vehiculesDisponiblesPour($journee, $creneau, $idsBenevoles);

        return [
            'familles' => $geocodees->count(),
            'poids_kg' => round((float) $geocodees->sum('poids_kg'), 1),
            'capacite_kg' => round((float) array_sum(array_column($vehicules, 'capacite_kg')), 1),
            'sans_coordonnees' => $pool->count() - $geocodees->count(),
            'chauffeurs' => count($vehicules),
        ];
    }

    /**
     * Chauffeurs confirmés pour cette journée et ce créneau, avec leur
     * véhicule, et un drapeau `occupe` (déjà une tournée active sur ce
     * créneau). Source de la liste de l'assistant (modes automatique et
     * personnalisé) — mêmes règles que vehiculesDisponiblesPour().
     *
     * @return array<int, array{id_personne: int, nom: string, prenom: string, id_vehicule_type: int, vehicule: string, capacite_kg: float, ids_secteurs: int[], occupe: bool, id_route: int|null}>
     */
    public function chauffeursDisponibles(CampagneJournee $journee, string $creneau): array
    {
        $occupes = $this->routesActivesParChauffeur($journee, $creneau);

        $liste = [];
        foreach ($this->candidatsPour($journee, $creneau) as $candidat) {
            $liste[] = [
                'id_personne' => $candidat['id_benevole'],
                'nom' => (string) ($candidat['nom'] ?? ''),
                'prenom' => (string) ($candidat['prenom'] ?? ''),
                'id_vehicule_type' => $candidat['id_vehicule_type'],
                'vehicule' => $candidat['vehicule_libelle'],
                'capacite_kg' => $candidat['capacite_kg'],
                'ids_secteurs' => $candidat['ids_secteurs'],
                'occupe' => isset($occupes[$candidat['id_benevole']]),
                'id_route' => $occupes[$candidat['id_benevole']] ?? null,
            ];
        }

        usort($liste, fn($a, $b) => [$a['occupe'], $a['nom'], $a['prenom']] <=> [$b['occupe'], $b['nom'], $b['prenom']]);

        return $liste;
    }

    /**
     * Livraisons de la journée candidates à ce créneau : confirmées, non
     * assignées, non se_deplace, sans chauffeur imposé, et dont ce créneau
     * fait partie des créneaux confirmés. Coordonnées PAS filtrées ici
     * (voir apercuCreneau() / genererPourCreneau()).
     *
     * @return Collection<int, Livraison>
     */
    private function poolPour(Campagne $campagne, CampagneJournee $journee, string $creneau): Collection
    {
        return Livraison::where('id_campagne', $campagne->id)
            ->where('id_campagne_journee', $journee->id)
            ->where('statut', 'non_assignee')
            ->where('statut_contact', 'confirme')
            ->whereNull('id_benevole_impose')
            ->whereHas('creneaux', fn($q) => $q->where('creneau', $creneau))
            // Familles se_deplace : viennent chercher leur colis au QG,
            // jamais livrées — voir RetraitHqSchedulingService pour leur
            // propre planification (rendez-vous, PAS un RouteLivraison).
            ->where('se_deplace', false)
            ->with(['famille:id,latitude,longitude,id_quartier', 'creneaux'])
            ->get();
    }

    /**
     * Livraisons dont la famille a des coordonnées résolues — les autres sont
     * exclues du clustering (voir genererPourCreneau()) et comptées à part par
     * apercuCreneau().
     *
     * @param Collection<int, Livraison> $pool
     * @return Collection<int, Livraison>
     */
    private function geocodees(Collection $pool): Collection
    {
        return $pool->filter(fn(Livraison $l) => $l->famille->latitude !== null && $l->famille->longitude !== null)->values();
    }

    /**
     * Chauffeurs ayant déjà une tournée non annulée sur ce créneau de cette
     * journée : id_benevole => id de la tournée.
     *
     * @return array<int, int>
     */
    private function routesActivesParChauffeur(CampagneJournee $journee, string $creneau): array
    {
        return RouteLivraison::where('id_campagne_journee', $journee->id)
            ->where('creneau', $creneau)
            ->where('statut', '!=', 'annulee')
            ->whereNotNull('id_benevole')
            ->pluck('id', 'id_benevole')
            ->all();
    }

    /**
     * Livraisons confirmées, non assignées, dont le créneau confirmé n'a
     * jamais pu être couvert — à afficher tel quel sur le tableau de bord
     * admin (§3.3 point 7 : "raise a visible admin-board item"). Calculé
     * à la volée plutôt que persisté (même choix que
     * Campagne::nombre_menages, voir Patch 1) : ce n'est jamais qu'une
     * requête sur un état déjà présent (statut_contact/statut), pas un
     * fait à part qui pourrait diverger.
     *
     * $journee optionnelle (05/09/2026) : scope l'affichage à une journée
     * précise depuis l'écran de génération (utile pour une campagne
     * multi-jours, où l'admin regarde une journée à la fois) — laissée
     * null, remonte les non-couvertes de TOUTE la campagne (ex: vue
     * d'ensemble/tableau de bord qui ne raisonne pas par journée).
     */
    public function livraisonsNonCouvertes(Campagne $campagne, ?CampagneJournee $journee = null): Collection
    {
        return Livraison::where('id_campagne', $campagne->id)
            ->when($journee !== null, fn($q) => $q->where('id_campagne_journee', $journee->id))
            ->where('statut', 'non_assignee')
            ->where('statut_contact', 'confirme')
            ->with('famille:id,nom,prenom,adresse')
            // se_deplace en dernier (30/09/2026) : ces familles n'ont pas de
            // tournée, elles restent en bas de liste au cas où une tournée
            // personnalisée deviendrait nécessaire.
            ->orderBy('se_deplace')
            ->orderBy('id')
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
        $hq = RouteOptimizationConfig::coordonneesHqPourCampagne($campagne);
        if ($hq === null) {
            throw new \RuntimeException('Coordonnées QG non configurées — voir Paramètres.');
        }

        $livraisons = Livraison::whereIn('id', $idsLivraisons)
            ->where('statut', 'non_assignee')
            ->with('famille:id,latitude,longitude,id_quartier')
            ->get()
            ->filter(fn(Livraison $l) => $l->famille->latitude !== null && $l->famille->longitude !== null)
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

        // Journée dérivée directement depuis la livraison (05/09/2026) —
        // même principe que le créneau ci-dessus : les orphelines d'une
        // même tournée créneau partagent forcément la même
        // id_campagne_journee (voir genererPourCreneau()).
        $idCampagneJournee = $livraisons->first()->id_campagne_journee;
        $journee = $idCampagneJournee !== null ? CampagneJournee::find($idCampagneJournee) : null;

        $vehicules = ($creneau !== null && $journee !== null)
            ? $this->vehiculesDisponiblesPour($journee, $creneau)
            : [];
        $vehicules = array_values(array_filter($vehicules, fn(array $v) => $v['id_benevole'] !== $idBenevoleExclu));

        if (empty($vehicules)) {
            return ['routes_creees' => 0, 'non_couvertes' => $livraisons->count()];
        }

        $plafondPoids = max(array_column($vehicules, 'capacite_kg'));
        $livraisonsArray = $livraisons->map(fn(Livraison $l) => $this->versArrayClustering($l))->all();

        $clusters = $this->clustering->identifierClusters($livraisonsArray, $hq, $plafondPoids);
        $resultat = $this->assignment->assigner($clusters, $vehicules, RouteOptimizationConfig::maxLivraisonsParRoutePourCampagne($campagne));

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
                idCampagneJournee: $idCampagneJournee,
            );
            $routesCreees++;
        }

        $nonCouvertes = array_sum(array_map(fn(array $c) => count($c['livraisons']), $resultat['non_places']));

        return ['routes_creees' => $routesCreees, 'non_couvertes' => $nonCouvertes];
    }

    // ── Livraisons imposées ──────────────────────────────────────────────

    /**
     * Résout les livraisons imposées (id_benevole_impose) — retirées du
     * pool créneau, pré-assignées directement, hors correspondance
     * créneau (voir §2/§3.3 point 1). Une route SANS créneau (routes.creneau
     * = null, voir 2026_08_31_000009_create_livraison_routing_tables.php) par
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
            // Confirmées seulement (06/10/2026) : une imposée encore à
            // contacter rejoint la tournée du chauffeur à sa confirmation
            // (LivraisonChangementService::apresConfirmation()).
            ->where('statut_contact', 'confirme')
            // Voir la même exclusion sur genererPourCreneau() ci-dessous —
            // une famille se_deplace n'a rien à faire dans une tournée,
            // imposée ou non.
            ->where('se_deplace', false)
            ->with('famille:id,latitude,longitude,id_quartier')
            ->get()
            ->filter(fn(Livraison $l) => $l->famille->latitude !== null && $l->famille->longitude !== null)
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

            $livraisonsArray = $groupe->map(fn(Livraison $l) => $this->versArrayClustering($l))->all();
            $ordonnees = $this->tsp->optimiser($livraisonsArray, $hq);

            // idCampagneJournee: null volontaire — transverse, voir
            // docblock de classe (une route d'imposées peut mélanger
            // plusieurs journées, cas rare traité comme indicatif).
            $routes[] = $this->creerRoute($campagne, $idBenevole, $profil->vehiculeType->id, null, $ordonnees, $hq, idCampagneJournee: null);
        }

        return $routes;
    }

    // ── Cycle par créneau ────────────────────────────────────────────────

    /**
     * @return array{routes_creees: int, non_couvertes: int}
     */
    private function genererPourCreneau(Campagne $campagne, CampagneJournee $journee, string $creneau, array $hq, ?array $idsBenevoles = null): array
    {
        $pool = $this->geocodees($this->poolPour($campagne, $journee, $creneau));
        // Familles sans coordonnées résolues (géocodage en attente/échoué,
        // voir App\Jobs\ResoudreAdresseFamille) exclues du clustering plutôt
        // que routées vers (0, 0) — restent non_assignee, remontent dans
        // livraisonsNonCouvertes() comme n'importe quelle autre livraison
        // confirmée jamais couverte (pas de distinction de cause à ce
        // niveau, l'admin voit l'adresse et peut déclencher un
        // re-géocodage depuis l'écran famille).

        $vehicules = $this->vehiculesDisponiblesPour($journee, $creneau, $idsBenevoles);

        if ($pool->isEmpty() || empty($vehicules)) {
            return ['routes_creees' => 0, 'non_couvertes' => $pool->count()];
        }

        $poolRetenu = $this->prioriserInflexibles($pool, $vehicules);

        $plafondPoids = max(array_column($vehicules, 'capacite_kg'));

        $livraisonsArray = $poolRetenu->map(fn(Livraison $l) => $this->versArrayClustering($l))->all();

        $clusters = $this->clustering->identifierClusters($livraisonsArray, $hq, $plafondPoids);
        $resultat = $this->assignment->assigner($clusters, $vehicules, RouteOptimizationConfig::maxLivraisonsParRoutePourCampagne($campagne));

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
                idCampagneJournee: $journee->id,
            );
            $routesCreees++;
        }

        $nonCouvertes = array_sum(array_map(fn(array $c) => count($c['livraisons']), $resultat['non_places']));
        // Les livraisons écartées du pool par prioriserInflexibles() (pool
        // total moins pool retenu) restent non_assignee et seront
        // reconsidérées à un créneau ultérieur — pas comptées ici comme
        // "non couvertes" pour CE créneau, ce n'en est pas un échec.

        return ['routes_creees' => $routesCreees, 'non_couvertes' => $nonCouvertes];
    }

    /**
     * Véhicules confirmés disponibles pour cette journée et ce créneau
     * précis.
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
     *
     * Scopée à CampagneJournee le 05/09/2026 (au lieu de Campagne) : voir
     * BenevoleDisponibilite, rescopée le même jour — un bénévole confirme
     * désormais séparément pour chaque journée.
     */
    private function vehiculesDisponiblesPour(CampagneJournee $journee, string $creneau, ?array $idsBenevoles = null): array
    {
        $occupes = $this->routesActivesParChauffeur($journee, $creneau);
        $vehicules = [];

        foreach ($this->candidatsPour($journee, $creneau) as $candidat) {
            // Chauffeur déjà en tournée sur ce créneau : jamais une seconde
            // tournée au même moment (06/10/2026).
            if (isset($occupes[$candidat['id_benevole']])) {
                continue;
            }

            // Sélection explicite de l'assistant : seuls les chauffeurs
            // cochés comptent (null = tous, comportement historique).
            if ($idsBenevoles !== null && !in_array($candidat['id_benevole'], $idsBenevoles, true)) {
                continue;
            }

            $vehicules[] = [
                'id_benevole' => $candidat['id_benevole'],
                'id_vehicule_type' => $candidat['id_vehicule_type'],
                'capacite_kg' => $candidat['capacite_kg'],
                'nombre_part_max' => $candidat['nombre_part_max'],
            ];
        }

        return $vehicules;
    }

    /**
     * Chauffeurs confirmés pour cette journée et ce créneau, avec leur
     * véhicule effectif — AVANT filtrage « occupé »/sélection. Une
     * personne désactivée pour Familles (03/10/2026) n'est jamais
     * candidate ; capacité nulle (« Sans permis », « Non véhiculé ») :
     * jamais proposé, il alimenterait le pool avec un véhicule de 0 kg.
     *
     * @return array<int, array{id_benevole: int, nom: string|null, prenom: string|null, id_vehicule_type: int, vehicule_libelle: string, capacite_kg: float, nombre_part_max: int, ids_secteurs: int[]}>
     */
    private function candidatsPour(CampagneJournee $journee, string $creneau): array
    {
        // Une personne désactivée pour Familles (03/10/2026) après avoir
        // confirmé sa disponibilité n'est plus proposée comme chauffeur :
        // sa disponibilité reste en base (historique) mais est ignorée ici.
        $disponibilites = BenevoleDisponibilite::where('id_campagne_journee', $journee->id)
            ->whereNotIn('id_personne', PersonneDesactivee::ids())
            ->where('statut', 'confirme')
            ->whereHas('creneaux', fn($q) => $q->where('creneau', $creneau))
            ->with('secteurs')
            ->get();

        // Noms lus par une requête typée plutôt que par la relation `personne`
        // (typée `Model` par l'analyse statique).
        $personnes = Personne::whereIn('id', $disponibilites->pluck('id_personne')->all())
            ->get(['id', 'nom', 'prenom'])
            ->keyBy('id');

        $candidats = [];

        foreach ($disponibilites as $dispo) {
            $profil = BenevoleProfil::where('id_personne', $dispo->id_personne)->with('vehiculeType')->first();

            // Véhicule PAR JOURNÉE (01/10/2026) : celui que le bénévole a
            // déclaré pour cette journée, sinon celui de son profil.
            $vehiculeType = $dispo->vehiculeEffectif($profil);

            if (!$vehiculeType || (float) $vehiculeType->capacite_kg <= 0) {
                continue;
            }

            $candidats[] = [
                'id_benevole' => $dispo->id_personne,
                'nom' => $personnes->get($dispo->id_personne)?->nom,
                'prenom' => $personnes->get($dispo->id_personne)?->prenom,
                'id_vehicule_type' => $vehiculeType->id,
                'vehicule_libelle' => (string) $vehiculeType->type,
                'capacite_kg' => (float) $vehiculeType->capacite_kg,
                'nombre_part_max' => (int) $vehiculeType->nombre_part_max,
                'ids_secteurs' => $dispo->secteurs->pluck('id_secteur')->map(fn($id) => (int) $id)->all(),
            ];
        }

        return $candidats;
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

        [$inflexibles, $flexibles] = $pool->partition(fn(Livraison $l) => $l->creneaux->count() <= 1);

        // CORRECTION du 31/08/2026 : sortByDesc, pas sortBy — les familles
        // flexibles les plus PRIORITAIRES (criticité la plus haute) doivent
        // être servies/retenues en premier ; l'ordre croissant précédent
        // retenait par erreur les moins urgentes et excluait les plus
        // urgentes en cas de pool trop grand pour la capacité disponible.
        $flexibles = $flexibles->sortByDesc(fn(Livraison $l) => $l->famille->criticite ?? 0)->values();

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
     * $idCampagneJournee (05/09/2026) : journée à laquelle rattacher cette
     * route — null pour les routes d'imposées (transverses, voir
     * resoudreLivraisonsImposees() et docblock de classe), sinon toujours
     * renseigné par l'appelant (genererPourCreneau()/
     * relancerPourLivraisonsOrphelines()).
     *
     * @param array{id_livraison: int, latitude: float, longitude: float}[] $livraisonsOrdonnees Sortie de TspOptimizationService::optimiser()
     */
    private function creerRoute(
        Campagne $campagne,
        int $idBenevole,
        int $idVehiculeType,
        ?string $creneau,
        array $livraisonsOrdonnees,
        array $hq,
        ?int $idCampagneJournee = null,
    ): RouteLivraison {
        $route = DB::transaction(function () use ($campagne, $idBenevole, $idVehiculeType, $creneau, $livraisonsOrdonnees, $hq, $idCampagneJournee) {
            $poidsTotal = array_sum(array_column($livraisonsOrdonnees, 'poids_kg'));
            $distanceTotale = $this->geo->distanceTotaleRoute($livraisonsOrdonnees, $hq);

            $route = RouteLivraison::create([
                'id_campagne' => $campagne->id,
                'id_campagne_journee' => $idCampagneJournee,
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

        // Colis déjà tous prêts avant la génération (conditionnement
        // découplé des tournées) : bascule immédiate en 'chargement',
        // sinon plus rien ne la déclencherait (01/10/2026). Résolu via
        // app() plutôt que le constructeur — ce service est instancié à la
        // main avec des dépendances explicites dans les tests.
        app(RouteChargementService::class)->promouvoirSiPrete($route);

        return $route;
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
