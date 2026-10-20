<?php
// app/Services/RouteMutationService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Models\Personne;
use App\Models\Campagne;
use App\Models\EtapeRoute;
use App\Models\Livraison;
use App\Models\RouteLivraison;
use App\Notifications\RouteModifieeNotification;
use App\Support\RouteOptimizationConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mutabilité des tournées après génération — ajout/retrait d'une
 * livraison, redimensionnement (réassignation véhicule/bénévole),
 * scission, tournée entièrement personnalisée. Voir le prompt du
 * 30/08/2026 §3.3 : "Routes must remain mutable after creation... Any
 * such change must notify the affected driver(s) and admin/gestionnaire."
 *
 * Notification : voir RouteModifieeNotification, envoyée depuis chaque
 * méthode qui change la composition/l'affectation d'une tournée.
 *
 * Réutilise TspOptimizationService/GeoCalculationService (mêmes calculs
 * qu'à la génération, voir RouteGenerationService) — jamais de jambe de
 * retour au QG ici non plus, cohérence avec le reste du domaine.
 */
class RouteMutationService
{
    public function __construct(
        private readonly TspOptimizationService $tsp,
        private readonly GeoCalculationService $geo,
    ) {}

    /**
     * Ajoute une livraison à une tournée existante — ré-ordonne
     * l'ensemble via TSP plutôt que d'ajouter simplement au bout, pour
     * rester géographiquement cohérent.
     */
    public function ajouterLivraison(RouteLivraison $route, Livraison $livraison): RouteLivraison
    {
        $hq = $this->hqOuEchoue($route->campagne);

        $etapesActuelles = $route->etapes()->with('livraison.famille')->orderBy('ordre')->get();
        $livraisonsArray = $etapesActuelles->map(fn(EtapeRoute $e) => $this->versArray($e->livraison))->all();
        $livraisonsArray[] = $this->versArray($livraison);

        $ordonnees = $this->tsp->optimiser($livraisonsArray, $hq);

        DB::transaction(function () use ($route, $ordonnees, $livraison) {
            $route->etapes()->delete();

            foreach ($ordonnees as $index => $l) {
                EtapeRoute::create([
                    'id_route' => $route->id,
                    'id_livraison' => $l['id_livraison'],
                    'ordre' => $index + 1,
                    'statut' => 'en_attente',
                ]);
            }

            $livraison->update(['statut' => 'assignee']);

            // Une tournée « prête à charger » ne l'est plus si on lui ajoute
            // une famille dont le colis n'est pas prêt (06/10/2026) : elle
            // repasse en 'planifiee' et sera promue de nouveau au dernier colis.
            if ($route->statut === 'chargement' && $livraison->statut_conditionnement !== 'prete') {
                $route->update(['statut' => 'planifiee']);
            }
        });

        $this->recalculerMetriques($route, $ordonnees, $hq);
        $this->notifierBenevole($route, 'Une livraison a été ajoutée à votre tournée.');

        return $route->fresh();
    }

    /** Statuts où les colis sont dans le véhicule ou la tournée est partie (11/10/2026). */
    public const STATUTS_VERROUILLES = ['charge', 'en_cours', 'livraisons_terminees', 'terminee'];

    /**
     * Garde des modifications de composition (ajout/retrait de famille, changement de chauffeur,
     * scission), 11/10/2026 : une tournée chargée (ou plus loin) n'est modifiable que par un
     * admin, pour forcer une correction exceptionnelle. À appeler depuis les endpoints d'édition
     * — pas depuis les flux internes, qui ont leurs propres règles (LivraisonChangementService).
     *
     * @throws \RuntimeException
     */
    public function refuserSiVerrouillee(RouteLivraison $route, ?Personne $acteur): void
    {
        if (in_array($route->statut, self::STATUTS_VERROUILLES, true) && !($acteur?->isAdmin() ?? false)) {
            throw new \RuntimeException(
                "La tournée #{$route->id} est déjà chargée ou en cours : seul un admin peut encore la modifier. "
                . 'Annulez d\'abord le chargement si c\'est une erreur.'
            );
        }
    }

    /**
     * Retire une livraison d'une tournée — remet la livraison
     * non_assignee, renumérote les arrêts restants.
     */
    public function retirerLivraison(RouteLivraison $route, EtapeRoute $etape): RouteLivraison
    {
        $hq = $this->hqOuEchoue($route->campagne);

        DB::transaction(function () use ($route, $etape) {
            $etape->livraison?->update(['statut' => 'non_assignee']);
            $etape->delete();

            $restantes = $route->etapes()->orderBy('ordre')->get();
            foreach ($restantes as $index => $e) {
                $e->update(['ordre' => $index + 1]);
            }
        });

        $ordonnees = $route->etapes()->with('livraison.famille')->orderBy('ordre')->get()
            ->map(fn(EtapeRoute $e) => $this->versArray($e->livraison))->all();
        $this->recalculerMetriques($route, $ordonnees, $hq);

        $this->notifierBenevole($route, 'Une livraison a été retirée de votre tournée.');

        return $route->fresh();
    }

    /**
     * Change manuellement le statut d'un arrêt — ajouté le 09/09/2026
     * (prompt de cette date §5.2.3 : "User needs to be able to manually
     * change [family statuses] in case driver does not") : le parcours
     * bénévole normal (MaRouteController::confirmerEtape()/
     * signalerIgnoree()) ne pose que en_attente→livree/ignoree, ce
     * endpoint permet à un gestionnaire de corriger/compléter le suivi
     * terrain sur n'importe quel arrêt de la tournée, y compris pour poser
     * 'en_cours' (jamais posé par le bénévole lui-même). Aucune
     * vérification de statut de la tournée ici : une correction manuelle
     * doit rester possible quel que soit l'état d'avancement affiché.
     */
    public function changerStatutEtape(EtapeRoute $etape, string $statut): EtapeRoute
    {
        if (!in_array($statut, EtapeRoute::STATUTS, true)) {
            throw new \RuntimeException("Statut d'arrêt invalide : {$statut}.");
        }

        $etape->update(['statut' => $statut]);

        return $etape->fresh();
    }

    /**
     * Réassigne une tournée à un autre bénévole/véhicule — utile pour le
     * redimensionnement ("resize per actual vehicle capacity discovered
     * at load time", voir le prompt §3.3) : ne vérifie PAS que la
     * nouvelle capacité suffit, c'est une action manuelle déclarée par un
     * admin/gestionnaire qui a déjà cette information sous les yeux.
     */
    public function reassigner(RouteLivraison $route, int $idBenevole, int $idVehiculeType): RouteLivraison
    {
        $ancienBenevole = $route->id_benevole;

        $route->update(['id_benevole' => $idBenevole, 'id_vehicule_type' => $idVehiculeType]);

        $route = $route->fresh();
        $this->notifierBenevole($route, 'Cette tournée vous a été assignée.');
        if ($ancienBenevole !== $idBenevole) {
            $this->notifierPersonne($ancienBenevole, 'Cette tournée ne vous est plus assignée.');
        }

        return $route;
    }

    /**
     * Scinde une tournée en deux au milieu de sa liste d'arrêts déjà
     * ordonnée par TSP — décision du 31/08/2026 : une tournée TSP-ordonnée
     * est déjà géographiquement cohérente par construction, un split au
     * milieu produit donc deux moitiés cohérentes sans avoir besoin de
     * relancer une recherche de diamètre minimum (ClusterSplitService,
     * qui répond à un besoin différent : choisir QUOI garder pour un
     * véhicule à capacité donnée — ici il n'y a pas de contrainte de
     * capacité à respecter, juste "couper en deux").
     *
     * La seconde moitié devient une nouvelle tournée, avec le MÊME
     * bénévole que l'originale par défaut (à réaffecter ensuite via
     * reassigner() si besoin) — la première conserve tout le reste.
     */
    public function diviser(RouteLivraison $route): RouteLivraison
    {
        $hq = $this->hqOuEchoue($route->campagne);

        $etapes = $route->etapes()->with('livraison.famille')->orderBy('ordre')->get();

        if ($etapes->count() < 2) {
            throw new \RuntimeException('Cette tournée a trop peu d\'arrêts pour être scindée.');
        }

        $milieu = intdiv($etapes->count(), 2);
        $secondeMoitie = $etapes->slice($milieu)->values();

        $nouvelleRoute = DB::transaction(function () use ($route, $secondeMoitie) {
            $nouvelleRoute = RouteLivraison::create([
                'id_campagne' => $route->id_campagne,
                'id_benevole' => $route->id_benevole,
                'id_vehicule_type' => $route->id_vehicule_type,
                'creneau' => $route->creneau,
                'statut' => 'planifiee',
            ]);

            foreach ($secondeMoitie as $index => $etape) {
                EtapeRoute::create([
                    'id_route' => $nouvelleRoute->id,
                    'id_livraison' => $etape->id_livraison,
                    'ordre' => $index + 1,
                    'statut' => 'en_attente',
                ]);
                $etape->delete();
            }

            return $nouvelleRoute;
        });

        $premiereMoitieArray = $route->etapes()->with('livraison.famille')->orderBy('ordre')->get()
            ->map(fn(EtapeRoute $e) => $this->versArray($e->livraison))->all();
        $secondeMoitieArray = $secondeMoitie->map(fn(EtapeRoute $e) => $this->versArray($e->livraison))->all();

        $this->recalculerMetriques($route, $premiereMoitieArray, $hq);
        $this->recalculerMetriques($nouvelleRoute, $secondeMoitieArray, $hq);

        $this->notifierBenevole($route->fresh(), 'Votre tournée a été scindée en deux — vous avez maintenant deux tournées distinctes.');

        return $nouvelleRoute->fresh();
    }

    /**
     * Répartit une tournée pas encore partie entre plusieurs chauffeurs (18/10/2026) — résolution d'un incident
     * « chauffeur indisponible » quand aucun véhicule de même type ou plus grand n'est disponible. La tournée
     * existante (arrêts les plus proches du début, déjà ordonnés par TSP) revient au premier chauffeur, les autres
     * parts deviennent de nouvelles tournées du même créneau. Les arrêts restent contigus (chaque part est un
     * tronçon cohérent de la tournée TSP, comme diviser()) et chaque part est dimensionnée au prorata de la
     * capacité (kg) du véhicule de son chauffeur.
     *
     * @param  array<int, array{id_benevole: int, id_vehicule_type: int, capacite_kg: float}>  $chauffeurs  au moins 2
     * @return list<RouteLivraison> la tournée d'origine puis les nouvelles, dans l'ordre des chauffeurs
     *
     * @throws \RuntimeException message prêt à afficher
     */
    public function repartir(RouteLivraison $route, array $chauffeurs): array
    {
        $chauffeurs = array_values($chauffeurs);
        if (count($chauffeurs) < 2) {
            throw new \RuntimeException('Choisissez au moins deux chauffeurs pour répartir la tournée.');
        }
        if (!in_array($route->statut, RouteLivraison::STATUTS_PAS_PARTIE, true)) {
            throw new \RuntimeException('Cette tournée est déjà partie ou annulée : elle ne peut plus être répartie.');
        }

        $campagne = Campagne::findOrFail($route->id_campagne);
        $hq = $this->hqOuEchoue($campagne);

        /** @var Collection<int, EtapeRoute> $etapes */
        $etapes = $route->etapes()->with('livraison.famille')->orderBy('ordre')->get();
        $etapes = $etapes->values();

        if ($etapes->count() < count($chauffeurs)) {
            throw new \RuntimeException('Cette tournée a trop peu d\'arrêts (' . $etapes->count() . ') pour être répartie entre ' . count($chauffeurs) . ' chauffeurs.');
        }

        // Poids des arrêts lus sur des Livraison chargées par id (et non via $etape->livraison, typé Model).
        $poidsParLivraison = Livraison::whereIn('id', $etapes->pluck('id_livraison')->filter()->all())->get()->keyBy('id');

        $groupes = self::partitionnerPoids(
            $etapes->map(fn(EtapeRoute $e) => (float) ($poidsParLivraison->get($e->id_livraison)->poids_kg ?? 0))->all(),
            array_map(fn(array $c) => (float) $c['capacite_kg'], $chauffeurs),
        );

        $ancienBenevole = $route->id_benevole;

        $routes = DB::transaction(function () use ($route, $chauffeurs, $etapes, $groupes) {
            $routes = [];
            foreach ($groupes as $indexGroupe => $indexesEtapes) {
                $chauffeur = $chauffeurs[$indexGroupe];

                if ($indexGroupe === 0) {
                    $cible = $route;
                    $cible->update(['id_benevole' => $chauffeur['id_benevole'], 'id_vehicule_type' => $chauffeur['id_vehicule_type']]);
                } else {
                    $cible = RouteLivraison::create([
                        'id_campagne' => $route->id_campagne,
                        'id_campagne_journee' => $route->id_campagne_journee,
                        'id_benevole' => $chauffeur['id_benevole'],
                        'id_vehicule_type' => $chauffeur['id_vehicule_type'],
                        'creneau' => $route->creneau,
                        'statut' => 'planifiee',
                    ]);

                    foreach ($indexesEtapes as $position => $indexEtape) {
                        $etape = $etapes[$indexEtape];
                        EtapeRoute::create([
                            'id_route' => $cible->id,
                            'id_livraison' => $etape->id_livraison,
                            'ordre' => $position + 1,
                            'statut' => 'en_attente',
                        ]);
                        $etape->delete();
                    }
                }

                $routes[] = $cible;
            }

            return $routes;
        });

        foreach ($routes as $cible) {
            $this->recalculerMetriques($cible, $this->livraisonsOrdonnees($cible), $hq);
            $this->notifierBenevole($cible->fresh(), 'Une tournée vous a été assignée (répartition d\'une tournée dont le chauffeur n\'est plus disponible).');
        }

        if (!in_array($ancienBenevole, array_column($chauffeurs, 'id_benevole'), true)) {
            $this->notifierPersonne($ancienBenevole, 'Votre tournée a été répartie entre d\'autres chauffeurs.');
        }

        return array_map(fn(RouteLivraison $r) => $r->fresh(), $routes);
    }

    /**
     * Livraisons d'une tournée, dans l'ordre de ses arrêts, sous la forme attendue par recalculerMetriques()
     * (les arrêts sans livraison — non concernés ici — sont ignorés).
     *
     * @return array<int, array<string, mixed>>
     */
    private function livraisonsOrdonnees(RouteLivraison $route): array
    {
        /** @var Collection<int, EtapeRoute> $etapes */
        $etapes = $route->etapes()->orderBy('ordre')->get();
        $livraisons = Livraison::with('famille')->whereIn('id', $etapes->pluck('id_livraison')->filter()->all())->get()->keyBy('id');

        $resultat = [];
        foreach ($etapes as $etape) {
            $livraison = $livraisons->get($etape->id_livraison);
            if ($livraison instanceof Livraison) {
                $resultat[] = $this->versArray($livraison);
            }
        }

        return $resultat;
    }

    /**
     * Découpe une liste d'arrêts ordonnée en tronçons contigus, un par chauffeur, au prorata de leur capacité
     * (18/10/2026). Chaque tronçon contient au moins un arrêt (le nombre d'arrêts doit être ≥ au nombre de
     * chauffeurs). Pure : ne touche à rien, utilisée par repartir().
     *
     * @param  float[]  $poids  poids de chaque arrêt, dans l'ordre de la tournée
     * @param  float[]  $capacites  capacité de chaque chauffeur, dans l'ordre des tronçons
     * @return array<int, int[]> index du tronçon => index des arrêts
     */
    public static function partitionnerPoids(array $poids, array $capacites): array
    {
        $poids = array_values($poids);
        $capacites = array_values($capacites);
        $n = count($capacites);
        $m = count($poids);

        if ($n === 0 || $m < $n) {
            throw new \InvalidArgumentException('Il faut au moins autant d\'arrêts que de chauffeurs.');
        }

        $total = array_sum($poids);
        $sommeCap = array_sum($capacites) > 0 ? array_sum($capacites) : (float) $n;
        $groupes = array_fill(0, $n, []);
        $g = 0;
        $cumul = 0.0;

        foreach ($poids as $i => $p) {
            $groupes[$g][] = $i;
            $cumul += $p;

            $apres = $m - $i - 1;
            $groupesRestants = $n - $g - 1;
            if ($groupesRestants === 0) {
                continue;
            }

            $seuil = $total * (array_sum(array_slice($capacites, 0, $g + 1)) / $sommeCap);
            // On change de tronçon quand sa part est atteinte, ou quand il ne reste qu'un arrêt par
            // chauffeur restant ; jamais si cela priverait un chauffeur suivant de tout arrêt.
            if ($apres >= $groupesRestants && ($cumul >= $seuil || $apres === $groupesRestants)) {
                $g++;
            }
        }

        return $groupes;
    }

    /**
     * Tournée entièrement personnalisée — l'admin choisit directement un
     * bénévole et un ensemble de livraisons non assignées (voir le prompt
     * §3.3 : "build a fully custom route for a driver who wants specific
     * secteurs"). Pas de clustering/assignation (déjà fait manuellement
     * par le choix de l'admin) — seulement le TSP pour l'ordre des arrêts.
     *
     * Depuis le 06/10/2026 (assistant « Génération des routes », mode
     * personnalisé) : $idCampagneJournee est posé sur la tournée (il
     * manquait — l'urgence chargement et les stats par journée en
     * dépendent), les familles se_deplace (pickup QG) ne sont jamais
     * routées, et les familles imposées à un autre chauffeur non plus —
     * sauf $autoriserImposees, utilisé pour la tournée du chauffeur imposé
     * lui-même (LivraisonChangementService).
     *
     * @param int[] $idsLivraisons
     */
    public function construirePersonnalisee(
        Campagne $campagne,
        int $idBenevole,
        int $idVehiculeType,
        array $idsLivraisons,
        ?string $creneau,
        ?int $idCampagneJournee = null,
        bool $autoriserImposees = false,
    ): RouteLivraison {
        $hq = $this->hqOuEchoue($campagne);

        $livraisons = Livraison::whereIn('id', $idsLivraisons)
            ->where('statut', 'non_assignee')
            ->where('se_deplace', false)
            ->when(!$autoriserImposees, fn($q) => $q->whereNull('id_benevole_impose'))
            ->with('famille:id,latitude,longitude,id_quartier')
            ->get();

        if ($livraisons->isEmpty()) {
            throw new \RuntimeException('Aucune des livraisons sélectionnées n\'est disponible.');
        }

        $livraisonsArray = $livraisons->map(fn(Livraison $l) => $this->versArray($l))->all();
        $ordonnees = $this->tsp->optimiser($livraisonsArray, $hq);

        $route = DB::transaction(function () use ($campagne, $idBenevole, $idVehiculeType, $creneau, $ordonnees, $idCampagneJournee) {
            $route = RouteLivraison::create([
                'id_campagne' => $campagne->id,
                'id_campagne_journee' => $idCampagneJournee,
                'id_benevole' => $idBenevole,
                'id_vehicule_type' => $idVehiculeType,
                'creneau' => $creneau,
                'statut' => 'planifiee',
            ]);

            foreach ($ordonnees as $index => $l) {
                EtapeRoute::create([
                    'id_route' => $route->id,
                    'id_livraison' => $l['id_livraison'],
                    'ordre' => $index + 1,
                    'statut' => 'en_attente',
                ]);
            }

            Livraison::whereIn('id', array_column($ordonnees, 'id_livraison'))->update(['statut' => 'assignee']);

            return $route;
        });

        $this->recalculerMetriques($route, $ordonnees, $hq);
        $route = $route->fresh();
        $this->notifierBenevole($route, 'Une nouvelle tournée personnalisée vous a été assignée.');

        // Colis déjà tous prêts : bascule immédiate en 'chargement' (voir
        // RouteChargementService, 01/10/2026). app() : ce service est
        // instancié à la main dans les tests.
        app(RouteChargementService::class)->promouvoirSiPrete($route);

        return $route->fresh();
    }

    /**
     * Voir le prompt du 05/09/2026 §1.2 : $campagne permet de retomber sur
     * son HQ propre s'il est renseigné (voir
     * RouteOptimizationConfig::coordonneesHqPourCampagne()) plutôt que
     * systématiquement le réglage global.
     */
    /**
     * Supprime une tournée pour permettre de la reconstruire avec des
     * paramètres différents — cas d'usage principal du 05/09/2026 §5.2 :
     * poids_kg des livraisons a été recalculé après ajustement du poids
     * moyen de la campagne, et les tournées déjà construites portent des
     * poids_total_kg désormais obsolètes (voir
     * CampagnesController::recalculerPoids() : le recalcul lui-même ne
     * touche jamais une livraison déjà rattachée à une tournée existante,
     * cette suppression est le geste explicite qui la libère pour que la
     * prochaine génération de tournées la reprenne avec le nouveau poids).
     *
     * Volontairement restreint à statut = 'planifiee' : au-delà (une fois
     * en 'chargement' ou plus loin), des colis peuvent déjà être
     * physiquement préparés/chargés sous l'ancien poids — supprimer la
     * tournée à ce stade serait une opération bien plus risquée qu'un
     * simple "refaire avant le début du chargement".
     *
     * Remet chaque livraison de la tournée à statut = 'non_assignee' (pas
     * à statut_conditionnement, indépendant) puis supprime les étapes et
     * la tournée elle-même.
     */
    /**
     * Annule une tournée — soft-cancel (statut 'annulee') depuis le
     * 09/09/2026 (prompt de cette date §5.2.2 : "After I delete a tournee
     * it's still displayed which is good but no status indicates that
     * it's no longer used"), remplace le hard delete d'origine du
     * 31/08/2026. La tournée et ses étapes restent en base pour rester
     * visibles en historique sur Suivi livraison (voir RoutesPanel.vue) ;
     * chaque livraison couverte redevient 'non_assignee' comme avant, pour
     * être re-proposée par Clustering / génération des routes ou une
     * tournée personnalisée.
     */
    public function supprimer(RouteLivraison $route): void
    {
        // Une tournée vidée de ses familles (11/10/2026) peut aussi être annulée tant qu'elle n'est
        // pas chargée : elle n'a plus rien à charger et ne doit pas rester bloquée.
        $viderModifiable = in_array($route->statut, ['chargement', 'packaging_annule'], true)
            && !$route->etapes()->exists();

        if ($route->statut !== 'planifiee' && !$viderModifiable) {
            throw new \RuntimeException("Seule une tournée encore 'planifiee' (ou vide, pas encore chargée) peut être supprimée (statut actuel : {$route->statut}).");
        }

        DB::transaction(function () use ($route) {
            foreach ($route->etapes as $etape) {
                $etape->livraison?->update(['statut' => 'non_assignee']);
            }
            $route->update(['statut' => 'annulee']);
        });
    }

    private function hqOuEchoue(Campagne $campagne): array
    {
        $hq = RouteOptimizationConfig::coordonneesHqPourCampagne($campagne);
        if ($hq === null) {
            throw new \RuntimeException('Coordonnées QG non configurées — voir Paramètres.');
        }

        return $hq;
    }

    private function recalculerMetriques(RouteLivraison $route, array $livraisonsOrdonnees, array $hq): void
    {
        $route->update([
            'distance_totale_km' => $this->geo->distanceTotaleRoute($livraisonsOrdonnees, $hq),
            'poids_total_kg' => array_sum(array_column($livraisonsOrdonnees, 'poids_kg')),
            'lien_maps' => $this->geo->construireLienMaps($livraisonsOrdonnees, $hq),
        ]);
    }

    private function versArray(Livraison $livraison): array
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

    private function notifierBenevole(RouteLivraison $route, string $message): void
    {
        $this->notifierPersonne($route->id_benevole, $message);
    }

    private function notifierPersonne(int $idPersonne, string $message): void
    {
        $personne = Personne::find($idPersonne);
        $personne?->notify(new RouteModifieeNotification($message));
    }
}
