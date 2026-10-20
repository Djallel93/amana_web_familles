<?php
// app/Http/Controllers/Livraison/ChargementController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use Amana\Shared\Models\Personne;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Livraison\Concerns\FiltreCampagnesEquipe;
use App\Http\Controllers\Livraison\Concerns\UrlRetourEquipe;
use App\Models\BenevoleDisponibilite;
use App\Models\Campagne;
use App\Models\EtapeRoute;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Notifications\RouteChargeeNotification;
use App\Services\PreparationPrioritaireService;
use App\Services\QrCodeService;
use App\Services\RouteMutationService;
use App\Support\Creneau;
use App\Support\StatutChargement;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Écran chargement — équipe_chargement confirme le "prêt à charger",
 * charge les véhicules, et signale les incidents (benevole_absent,
 * capacite, chargement_termine) — voir le prompt du 30/08/2026 §3.3
 * point 8 / §4 / §7. Voit les mêmes indicateurs de packaging (etudiant/
 * est_hotel/nombre_enfant) et note_besoins_speciaux que équipe_packaging,
 * en LECTURE SEULE, pour contexte uniquement.
 *
 * NE résout PAS les incidents (voir matrice §4 : "Resolve" est
 * admin/gestionnaire uniquement) — ce contrôleur ne fait que les lever.
 * La résolution (et le re-clustering scopé qui l'accompagne pour
 * benevole_absent) vit dans LiveBoardController.
 */
class ChargementController extends Controller
{
    use FiltreCampagnesEquipe;
    use UrlRetourEquipe;

    public function __construct(
        private readonly QrCodeService $qrCode,
    ) {}

    /**
     * Point d'entrée sans campagne — voir le prompt du 05/09/2026 §4.1,
     * même raisonnement que PosteReleveController::choisir() (pesée/réception)/
     * PackagingController::choisir() : equipe_chargement n'avait aucune
     * entrée de menu vers cet écran. Liste restreinte aux campagnes
     * affectées (08/09/2026, voir FiltreCampagnesEquipe) — sans journées
     * (avecJournee=false, inchangé, ce poste n'a pas de sélecteur de
     * journée).
     */
    public function choisir(): View
    {
        $campagnes = $this->campagnesPourEquipe(Auth::user(), 'equipe_chargement', avecJournees: false);

        return view('livraison.choisir-poste', [
            'campagnes' => $campagnes,
            'titre' => 'Chargement — choisir une campagne',
            'routeIndex' => 'livraison.chargement.index',
            'avecJournee' => false,
        ]);
    }

    /**
     * Écran chargement — page Inertia (11/10/2026, remplace chargement.blade.php et ses
     * partials) : les tournées et familles sont des données, plus du HTML pré-rendu, pour
     * que l'équipe chargement puisse aussi modifier la composition des tournées (ajouter /
     * retirer une famille, changer de chauffeur, annuler une tournée vide) sans qu'un
     * rafraîchissement ne vienne écraser une boîte de dialogue ouverte.
     *
     * Ne filtre pas sur statut = 'chargement' (08/09/2026) : toutes les tournées sont
     * listées sauf les annulées (17/10/2026 : y compris celles déjà parties — en livraison,
     * terminées — en lecture seule), la chargée puis celles en livraison / terminées en
     * dernier (§7.2), les vides en tête (à compléter ou annuler), puis les plus urgentes
     * (§7.3, voir calculerUrgence()) et les prêtes avant les autres.
     */
    public function index(Request $request, Campagne $campagne): InertiaResponse
    {
        $filtre = $this->filtreDepuisRequete($request);
        $utilisateur = Auth::user();

        return Inertia::render('Livraison/Chargement', [
            'campagne' => ['id' => $campagne->id],
            'filtre' => $filtre,
            'urlRetour' => $this->urlRetourEquipe($campagne, 'livraison.chargement.choisir'),
            'urlEtiquettes' => route('livraison.chargement.etiquettes', $campagne),
            // Chercher un chauffeur (PersonSelect) passe par une route gestionnaire : l'équipe
            // chargement seule modifie les familles, pas le chauffeur.
            'peutReassigner' => (bool) ($utilisateur?->isAdmin() || $utilisateur?->isGestionnaire()),
            'urls' => [
                'liste' => route('livraison.chargement.liste', $campagne),
                'confirmer' => route('livraison.chargement.confirmer', ['route' => '__ID__']),
                'annulerChargement' => route('livraison.chargement.annuler-chargement', ['route' => '__ID__']),
                'benevoleAbsent' => route('livraison.chargement.benevole-absent', ['route' => '__ID__']),
                'capacite' => route('livraison.chargement.capacite', ['route' => '__ID__']),
                'prioriser' => route('livraison.chargement.preparation-prioritaire', ['route' => '__ID__']),
                'ajouterFamille' => route('livraison.chargement.routes.ajouter-famille', ['route' => '__ID__']),
                'retirerFamille' => route('livraison.chargement.routes.retirer-famille', ['route' => '__ID__', 'etape' => '__ETAPE__']),
                'reassigner' => route('livraison.chargement.routes.reassigner', ['route' => '__ID__']),
                'diviser' => route('livraison.chargement.routes.diviser', ['route' => '__ID__']),
                'supprimer' => route('livraison.chargement.routes.supprimer', ['route' => '__ID__']),
            ],
            ...$this->donneesListe($campagne, $filtre),
        ]);
    }

    /**
     * Polling de l'écran (toutes les 20 s) : mêmes données que index(), même tri, même
     * filtre (filtre_chargement en query string). Lecture seule, même autorisation
     * (can:equipeChargement) que index().
     */
    public function liste(Request $request, Campagne $campagne): JsonResponse
    {
        return response()->json($this->donneesListe($campagne, $this->filtreDepuisRequete($request)));
    }

    /**
     * filtre_chargement tel que transmis en query string ('toutes' par
     * défaut, y compris pour toute valeur non-scalaire ou inconnue — un
     * ?filtre_chargement[]=x ne doit pas faire lever d'ErrorException).
     */
    private function filtreDepuisRequete(Request $request): string
    {
        $valeur = $request->input('filtre_chargement', 'toutes');

        return is_string($valeur) ? $valeur : 'toutes';
    }

    /**
     * Chauffeurs de la campagne pas (encore) de retour au QG (18/10/2026) : id_benevole => 'parti' (réponse « Non »
     * au retour) ou 'en_livraison' (tournée en cours, ou terminée sans réponse sur le retour).
     *
     * @param  \Illuminate\Support\Collection<int, RouteLivraison>  $routes
     * @return array<int, string>
     */
    private function alertesChauffeurs(\Illuminate\Support\Collection $routes): array
    {
        $alertes = [];
        foreach ($routes as $route) {
            $idBenevole = (int) $route->id_benevole;
            if ($idBenevole === 0) {
                continue;
            }
            if ($route->retour_qg === false) {
                $alertes[$idBenevole] = 'parti';
            } elseif (
                ($route->statut === 'en_cours' || ($route->statut === 'livraisons_terminees' && $route->retour_qg === null))
                && ($alertes[$idBenevole] ?? null) !== 'parti'
            ) {
                $alertes[$idBenevole] = 'en_livraison';
            }
        }

        return $alertes;
    }

    /**
     * Données de l'écran, partagées par index() et liste().
     *
     * Statut affiché dérivé du conditionnement (06/10/2026) — voir StatutChargement :
     * Restante → En préparation → Prête → Chargée ; packaging_annule affiché tel quel et
     * compté avec les restantes. Une tournée sans aucun arrêt (11/10/2026 : toutes ses
     * familles retirées) reste listée pour pouvoir lui en rendre ou l'annuler ; elle est
     * comptée à part (« vides ») et suit son propre filtre. Les stats sont calculées AVANT
     * application du filtre.
     *
     * @return array{stats: array<string, int>, routes: list<array<string, mixed>>, familles: list<array<string, mixed>>, rappel: array<string, mixed>|null}
     */
    private function donneesListe(Campagne $campagne, string $filtre): array
    {
        $routes = RouteLivraison::where('id_campagne', $campagne->id)
            // Toutes les tournées sauf les annulées (17/10/2026) : avant, seules les tournées non partie
            // étaient listées et une tournée disparaissait dès que le chauffeur démarrait — même avec
            // le filtre « Toutes ». Celles en livraison / terminées restent visibles, en lecture seule.
            ->where('statut', '!=', 'annulee')
            // vehiculeType + colis (09/10/2026) : type de véhicule et nombre de colis à charger de la carte.
            ->with(['benevole', 'vehiculeType', 'etapes.livraison.famille:id,nom,prenom,etudiant,est_hotel,nombre_enfant', 'etapes.livraison.creneaux', 'etapes.livraison.colis'])
            ->get();

        // 18/10/2026 : chauffeurs pas encore rentrés au QG, pour avertir l'équipe chargement.
        $alertes = $this->alertesChauffeurs($routes);

        $routes = $routes
            ->map(function (RouteLivraison $route) use ($alertes) {
                // Chauffeur d'une tournée à charger encore en livraison (ou parti) : l'équipe doit le savoir.
                $route->chauffeur_alerte = in_array($route->statut, RouteLivraison::STATUTS_PAS_PARTIE, true)
                    ? ($alertes[$route->id_benevole] ?? null)
                    : null;
                // Une tournée déjà partie n'a plus rien à charger : pas d'urgence (17/10/2026).
                $route->urgence = in_array($route->statut, StatutChargement::STATUTS_ROUTE_PARTIE, true) ? null : $this->calculerUrgence($route);
                // Une tournée sans arrêt est « restante » à l'écran (StatutChargement::pourRoute()
                // la déduirait « chargée » : `every()` est vrai sur une liste vide).
                $route->etat = $route->etapes->isEmpty() && !in_array($route->statut, StatutChargement::STATUTS_ROUTE_PARTIE, true)
                    ? StatutChargement::RESTANTE
                    : StatutChargement::pourRoute($route);

                return $route;
            })
            // sort() avec un comparateur, pas sortBy([...closures]) : dans un tableau, Laravel passe
            // CHAQUE closure comme comparateur (a, b) — des closures à un argument qui renvoient
            // 0/1/2 ne trient donc rien de fiable (même remarque que MaRouteVueService).
            ->sort(fn(RouteLivraison $a, RouteLivraison $b) => $this->cleDeTri($a) <=> $this->cleDeTri($b))
            ->values();

        // « Vide » = tournée sans arrêt encore modifiable ; une tournée partie ou terminée n'est jamais « vide ».
        $vides = $routes->filter(fn(RouteLivraison $route) => $route->etapes->isEmpty() && !in_array($route->statut, StatutChargement::STATUTS_ROUTE_PARTIE, true));
        $pleines = $routes->reject(fn(RouteLivraison $route) => $vides->contains('id', $route->id));
        $stats = [
            'chargees' => $pleines->where('etat', StatutChargement::CHARGEE)->count(),
            'restantes' => $pleines->whereIn('etat', [StatutChargement::RESTANTE, StatutChargement::PACKAGING_ANNULE])->count(),
            'en_preparation' => $pleines->where('etat', StatutChargement::EN_PREPARATION)->count(),
            'pretes' => $pleines->where('etat', StatutChargement::PRETE)->count(),
            'en_livraison' => $pleines->where('etat', StatutChargement::EN_LIVRAISON)->count(),
            'terminees' => $pleines->where('etat', StatutChargement::LIVREE)->count(),
            'vides' => $vides->count(),
        ];

        if ($filtre === 'vides') {
            $routes = $vides->values();
        } elseif (isset(StatutChargement::FILTRES[$filtre])) {
            $cible = StatutChargement::FILTRES[$filtre];
            $etats = $cible === StatutChargement::RESTANTE
                ? [StatutChargement::RESTANTE, StatutChargement::PACKAGING_ANNULE]
                : [$cible];
            $routes = $pleines->whereIn('etat', $etats)->values();
        }

        $utilisateur = Auth::user();
        $familles = $this->famillesSansTournee($campagne, $filtre);

        return [
            'stats' => $stats,
            'routes' => $routes->map(fn(RouteLivraison $route) => $this->donneesRoute($route, $utilisateur))->all(),
            'familles' => $familles,
            'rappel' => $this->rappelRoutes($campagne),
        ];
    }

    /**
     * Clé de tri d'une tournée, comparée terme à terme : les tournées vides en tête (à compléter
     * ou annuler), la chargée puis celles en livraison / terminées en dernier (§7.2, 17/10/2026), puis les plus urgentes (§7.3, voir
     * calculerUrgence()) et les prêtes avant les autres.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function cleDeTri(RouteLivraison $route): array
    {
        return [
            $route->etapes->isEmpty() && !in_array($route->statut, StatutChargement::STATUTS_ROUTE_PARTIE, true) ? 0 : 1,
            StatutChargement::rangTri($route->etat),
            match ($route->urgence) {
                'famille' => 0,
                'benevole' => 1,
                default => 2,
            },
            $route->etat === StatutChargement::PRETE ? 0 : 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function donneesRoute(RouteLivraison $route, ?Personne $utilisateur): array
    {
        $estAdmin = (bool) $utilisateur?->isAdmin();
        $verrouillee = in_array($route->statut, RouteMutationService::STATUTS_VERROUILLES, true) && !$estAdmin;
        $modifiable = in_array($route->statut, ['planifiee', 'chargement', 'packaging_annule'], true);

        /** @var Collection<int, EtapeRoute> $toutesEtapes */
        $toutesEtapes = $route->etapes;
        $vide = $toutesEtapes->isEmpty();

        $etapes = [];
        $colis = 0;
        $priorisable = false;
        foreach ($toutesEtapes as $etape) {
            /** @var Livraison|null $livraison */
            $livraison = $etape->livraison;
            if ($livraison === null) {
                continue;
            }
            /** @var Famille $famille */
            $famille = $livraison->famille;
            $etat = StatutChargement::pourFamille($livraison);

            $colis += $livraison->colis->count();
            $priorisable = $priorisable || (
                $livraison->statut_conditionnement !== 'prete'
                && !$livraison->preparation_prioritaire
                && !in_array($etape->statut, ['livree', 'ignoree'], true)
            );

            $etapes[] = [
                'id' => $etape->id,
                'id_livraison' => (int) $etape->id_livraison,
                'famille_id' => $famille->id,
                'etat' => $etat,
                'etat_libelle' => StatutChargement::LIBELLES[$etat],
                'etat_style' => StatutChargement::STYLES[$etat],
                'etudiant' => (bool) $famille->etudiant,
                'est_hotel' => (bool) $famille->est_hotel,
                'nombre_enfant' => (int) $famille->nombre_enfant,
                'prioritaire' => (bool) $livraison->preparation_prioritaire,
                // Statut de l'arrêt (en_attente / en_cours / livree / ignoree) — affiché une fois la tournée partie.
                'statut_arret' => $etape->statut,
            ];
        }

        /** @var Personne|null $benevole */
        $benevole = $route->benevole;

        return [
            'id' => $route->id,
            'statut' => $route->statut,
            'etat' => $route->etat,
            'etat_libelle' => StatutChargement::LIBELLES[$route->etat],
            'etat_style' => StatutChargement::STYLES[$route->etat],
            'urgence' => $route->urgence,
            // « en_livraison » : le chauffeur n'est pas encore rentré ; « parti » : il a répondu « Non » au retour au QG.
            'chauffeur_alerte' => $route->chauffeur_alerte ?? null,
            'vide' => $vide && !in_array($route->statut, StatutChargement::STATUTS_ROUTE_PARTIE, true),
            // Route déjà partie (en livraison / terminée) : affichée en lecture seule (17/10/2026).
            'partie' => in_array($route->statut, StatutChargement::STATUTS_ROUTE_PARTIE, true),
            'creneau_libelle' => $route->creneau ? Creneau::libelle($route->creneau) : 'Imposée',
            'benevole' => $benevole ? [
                'id' => $benevole->getKey(),
                'prenom' => $benevole->getAttribute('prenom'),
                'nom' => $benevole->getAttribute('nom'),
                'telephone' => $benevole->getAttribute('telephone'),
            ] : null,
            'vehicule' => $route->vehiculeType?->getAttribute('type'),
            'colis' => $colis,
            // Edition de la composition : bloquée une fois chargée (sauf admin) — voir
            // RouteMutationService::refuserSiVerrouillee().
            'verrouillee' => $verrouillee,
            'peut_confirmer' => $route->statut === 'chargement' && !$vide,
            'peut_annuler_chargement' => $route->statut === 'charge',
            'peut_prioriser' => $modifiable && $priorisable,
            'peut_supprimer' => !$verrouillee && ($route->statut === 'planifiee' || ($vide && $modifiable)),
            'etapes' => $etapes,
        ];
    }

    /**
     * Familles confirmées (hors se_deplace : retrait QG, pas de tournée) qui ne sont dans
     * aucune tournée — avant la génération, ou famille confirmée après coup. Statut affiché =
     * leur conditionnement (06/10/2026). Elles servent aussi de réservoir à « Ajouter une
     * famille » d'une tournée (11/10/2026). Suivent le filtre comme les tournées : « Chargées »
     * et « Vides » n'en montrent aucune.
     *
     * @return list<array<string, mixed>>
     */
    private function famillesSansTournee(Campagne $campagne, string $filtre = 'toutes'): array
    {
        if (in_array($filtre, ['chargees', 'vides', 'en_livraison', 'terminees'], true)) {
            return [];
        }

        $etatVoulu = StatutChargement::FILTRES[$filtre] ?? null;

        return Livraison::where('id_campagne', $campagne->id)
            ->where('statut_contact', 'confirme')
            ->where('se_deplace', false)
            ->where('statut', 'non_assignee')
            ->with('famille:id,nom,prenom,etudiant,est_hotel,nombre_enfant')
            ->orderBy('id')
            ->get()
            ->filter(fn(Livraison $livraison) => $etatVoulu === null || StatutChargement::pourFamille($livraison) === $etatVoulu)
            ->values()
            ->map(function (Livraison $livraison): array {
                /** @var Famille $famille */
                $famille = $livraison->famille;
                $etat = StatutChargement::pourFamille($livraison);

                return [
                    'id' => $livraison->id,
                    'famille_id' => $famille->id,
                    'etat' => $etat,
                    'etat_libelle' => StatutChargement::LIBELLES[$etat],
                    'etat_style' => StatutChargement::STYLES[$etat],
                    'etudiant' => (bool) $famille->etudiant,
                    'est_hotel' => (bool) $famille->est_hotel,
                    'nombre_enfant' => (int) $famille->nombre_enfant,
                ];
            })
            ->all();
    }

    /**
     * Rappel « générez les routes » (24/09/2026, refait le 06/10/2026), ou null : affiché
     * dès qu'il y a des familles confirmées à livrer et AUCUNE tournée (hors annulées),
     * indépendamment du conditionnement. Deux variantes : campagne pas encore démarrée (le
     * bouton mène au hub, qui propose « Démarrer la campagne ») ou démarrée (le bouton ouvre
     * l'assistant de génération). Les équipes sans droit de génération voient le texte seul.
     *
     * @return array{demarree: bool, peut_generer: bool, url_generer: string, url_campagne: string}|null
     */
    private function rappelRoutes(Campagne $campagne): ?array
    {
        $afaire = Livraison::where('id_campagne', $campagne->id)
            ->where('statut_contact', 'confirme')
            ->where('se_deplace', false)
            ->exists();

        $routesExistent = RouteLivraison::where('id_campagne', $campagne->id)
            ->where('statut', '!=', 'annulee')
            ->exists();

        if (!$afaire || $routesExistent) {
            return null;
        }

        $utilisateur = auth()->user();

        return [
            'demarree' => in_array($campagne->statut, ['en_cours', 'terminee'], true),
            'peut_generer' => (bool) ($utilisateur?->isAdmin() || $utilisateur?->isGestionnaire()),
            'url_generer' => route('livraison.campagnes.show', $campagne) . '?generer=1',
            'url_campagne' => route('livraison.campagnes.show', $campagne),
        ];
    }

    /**
     * Urgence d'une tournée (08/09/2026, prompt de cette date §7.3) : une
     * famille de la tournée n'a confirmé QUE le créneau en cours (pas de
     * repli possible si cette tournée n'est pas chargée maintenant), ou à
     * défaut le chauffeur n'est disponible QUE sur ce créneau (repli
     * possible : "we can replace the driver", d'où la priorité famille >
     * bénévole ci-dessus). Retourne null en dehors des heures de créneau
     * (avant 8h/après 19h) ou si rien ne correspond.
     */
    private function calculerUrgence(RouteLivraison $route): ?string
    {
        $creneauActuel = Creneau::actuel();
        if ($creneauActuel === null) {
            return null;
        }

        $familleUrgente = $route->etapes->contains(function ($etape) use ($creneauActuel) {
            $creneauxFamille = $etape->livraison?->creneaux->pluck('creneau') ?? collect();

            return $creneauxFamille->count() === 1 && $creneauxFamille->first() === $creneauActuel;
        });
        if ($familleUrgente) {
            return 'famille';
        }

        if ($route->id_benevole) {
            $disponibilite = BenevoleDisponibilite::where('id_personne', $route->id_benevole)
                ->where('id_campagne_journee', $route->id_campagne_journee)
                ->with('creneaux')
                ->first();
            $creneauxBenevole = $disponibilite?->creneaux->pluck('creneau') ?? collect();
            if ($creneauxBenevole->count() === 1 && $creneauxBenevole->first() === $creneauActuel) {
                return 'benevole';
            }
        }

        return null;
    }

    /**
     * Planche d'étiquettes QR pour TOUTES les familles confirmées de la
     * campagne, une page unique à découper (07/09/2026, prompt §4.1 :
     * "We don't print individual labels but rather a full sheet to cut
     * off each label") — remplace le bouton d'étiquette par famille
     * retiré de Packaging (§3.1). Un colis = une personne du foyer, même
     * convention que l'ancien PackagingController::etiquettes() (retiré
     * par ce même patch) dont ce code reprend la logique de génération
     * QR — verso : QR de secours vers la confirmation authentifiée du
     * bénévole quand la tournée existe déjà, sinon la mention
     * "réimprimer après" plutôt qu'un lien mort.
     *
     * Scopée aux familles confirmées (statut_contact = confirme), pas à
     * "en attente de chargement" : imprimée depuis Chargement mais pensée
     * comme la planche de LA campagne, à imprimer en une fois en amont
     * (typiquement pendant/après Packaging) plutôt que route par route.
     */
    public function etiquettesCampagne(Campagne $campagne): View
    {
        $livraisons = Livraison::where('id_campagne', $campagne->id)
            ->where('statut_contact', 'confirme')
            ->with(['famille:id,nom,prenom', 'etapesRoute'])
            ->get();

        $qrParLivraison = $livraisons->mapWithKeys(function (Livraison $livraison) {
            $etape = $livraison->etapesRoute->first();

            return [$livraison->id => $etape ? $this->qrCode->genererSvg(route('livraison.benevole.etapes.scan', $etape)) : null];
        });

        return view('livraison.etiquettes-campagne', [
            'campagne' => $campagne,
            'livraisons' => $livraisons,
            'qrParLivraison' => $qrParLivraison,
        ]);
    }

    /**
     * Bascule sur 'charge' (RENOMMÉ le 09/09/2026, prompt de cette date
     * §4 — c'était 'en_cours' jusque-là) : "l'équipe chargement a fini de
     * charger le véhicule" n'est PAS la même chose que "le bénévole a
     * démarré sa tournée" — un chauffeur peut légitimement s'attarder au
     * QG un moment avant de partir. Le passage à 'en_cours' proprement
     * dit vit désormais côté MaRouteController, déclenché par le
     * bénévole lui-même.
     *
     * Notifie aussi le chauffeur (09/09/2026, prompt de cette date §2.5) :
     * jusque-là, rien n'était envoyé au-delà du RouteIncident
     * 'chargement_termine' ci-dessous (traçabilité seulement). Chauffeur
     * uniquement, voir RouteChargeeNotification — silencieux si la
     * tournée n'a pas (encore) de chauffeur assigné (id_benevole NULL,
     * cas d'une tournée purement imposée par exemple).
     */
    public function confirmer(RouteLivraison $route): JsonResponse
    {
        // Seule une tournée « prête à charger » (tous les colis prêts) se
        // charge — le double-clic ou un onglet périmé ne doivent pas
        // renotifier le chauffeur (06/10/2026).
        if ($route->statut !== 'chargement') {
            return response()->json(['success' => false, 'message' => "Cette tournée n'est pas (ou plus) prête à charger."], 422);
        }

        $route->update(['statut' => 'charge']);

        RouteIncident::create([
            'id_route' => $route->id,
            'type' => 'chargement_termine',
            'signale_par' => auth()->id(),
            'statut' => null,
        ]);

        if ($route->id_benevole) {
            $chauffeur = Personne::find($route->id_benevole);
            if ($chauffeur) {
                Notification::send($chauffeur, new RouteChargeeNotification($route));
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * « Préparation prioritaire » d'une tournée (11/10/2026) : demande à Packaging de préparer en
     * premier toutes les familles de la tournée qui ne sont pas encore prêtes — voir
     * PreparationPrioritaireService. Ne se retire pas.
     */
    public function preparationPrioritaire(RouteLivraison $route, PreparationPrioritaireService $service): JsonResponse
    {
        try {
            $familles = $service->prioriserTournee($route);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'familles' => $familles]);
    }

    /**
     * Annule un chargement confirmé par erreur (06/10/2026) : la tournée
     * repasse de 'charge' à 'chargement' (prête à charger) et un incident
     * 'chargement_annule' est ouvert pour l'admin/gestionnaire. Refusé dès que
     * le chauffeur a démarré sa tournée (statut 'en_cours' et suivants) :
     * seule une tournée encore 'charge' peut être annulée. Pas de
     * notification au chauffeur à ce stade (volontairement minimal).
     */
    public function annulerChargement(RouteLivraison $route): JsonResponse
    {
        if ($route->statut !== 'charge') {
            return response()->json([
                'success' => false,
                'message' => $route->statut === 'chargement'
                    ? "Cette tournée n'est pas chargée."
                    : 'Le chauffeur a déjà démarré sa tournée : le chargement ne peut plus être annulé.',
            ], 422);
        }

        $route->update(['statut' => 'chargement']);

        $incident = RouteIncident::create([
            'id_route' => $route->id,
            'type' => 'chargement_annule',
            'signale_par' => auth()->id(),
            'statut' => 'ouvert',
        ]);

        return response()->json(['success' => true, 'id_incident' => $incident->id]);
    }

    /**
     * Bénévole absent — orpheline immédiatement les étapes non livrées de
     * cette tournée (remises non_assignee) et clôt la tournée elle-même
     * (statut = terminee, ce qui reste d'elle est un historique partiel) —
     * voir le prompt §3.3 point 8. Le re-clustering scopé au pool
     * orphelin n'est PAS déclenché ici automatiquement : c'est une action
     * admin/gestionnaire distincte (voir LiveBoardController), levée
     * seulement en signalant l'incident.
     */
    public function signalerBenevoleAbsent(Request $request, RouteLivraison $route): JsonResponse
    {
        $etapesNonLivrees = $route->etapes()->where('statut', 'en_attente')->with('livraison')->get();

        foreach ($etapesNonLivrees as $etape) {
            $etape->livraison?->update(['statut' => 'non_assignee']);
        }

        $route->update(['statut' => 'terminee']);

        $incident = RouteIncident::create([
            'id_route' => $route->id,
            'type' => 'benevole_absent',
            'signale_par' => auth()->id(),
            'statut' => 'ouvert',
            'notes' => $request->input('notes'),
        ]);

        return response()->json(['success' => true, 'id_incident' => $incident->id]);
    }

    public function signalerCapacite(Request $request, RouteLivraison $route): JsonResponse
    {
        $validator = Validator::make($request->all(), ['notes' => 'nullable|string|max:1000']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        RouteIncident::create([
            'id_route' => $route->id,
            'type' => 'capacite',
            'signale_par' => auth()->id(),
            'statut' => 'ouvert',
            'notes' => $request->input('notes'),
        ]);

        return response()->json(['success' => true]);
    }
}
