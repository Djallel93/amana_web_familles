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
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Notifications\RouteChargeeNotification;
use App\Services\QrCodeService;
use App\Support\Creneau;
use App\Support\StatutChargement;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;

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
     * Ne filtre plus sur statut = 'chargement' (08/09/2026, prompt de
     * cette date §7.1/§7.2) : une tournée confirmée chargée (statut
     * bascule à 'charge', voir confirmer() plus bas — RENOMMÉ le
     * 09/09/2026 depuis 'en_cours', voir le docblock de la migration
     * routes) disparaissait intégralement de cet écran au prochain
     * chargement — corrigée en élargissant à chargement/charge/
     * packaging_annule (planifiee exclue : pas encore pertinente pour
     * cet écran, les colis ne sont pas encore tous prêts ; en_cours
     * exclue depuis le 09/09/2026 : une fois la tournée réellement
     * démarrée par le bénévole, elle ne concerne plus cet écran) et en
     * triant plutôt qu'en filtrant : charge (déjà chargée) toujours en
     * dernier (§7.2 "move row to the bottom"), et parmi le reste, les
     * plus urgentes en tête (§7.3, voir calculerUrgence() ci-dessous).
     */
    public function index(Request $request, Campagne $campagne): View
    {
        $filtreChargement = $this->filtreDepuisRequete($request);
        ['lignes' => $lignes, 'stats' => $stats] = $this->construireListe($campagne, $filtreChargement);

        return view('livraison.chargement', [
            'campagne' => $campagne,
            // Lignes déjà rendues (HTML de la carte + signature) plutôt que
            // les modèles bruts : la page initiale et l'endpoint de polling
            // liste() partent de la MÊME méthode, voir construireListe().
            'lignes' => $lignes,
            'stats' => $stats,
            'filtreChargement' => $filtreChargement,
            // Familles pas encore dans une tournée + rappel « générez les
            // routes » (06/10/2026) : désormais polled comme les tournées,
            // et affichés indépendamment du conditionnement.
            'familles' => $this->construireFamillesSansTournee($campagne, $filtreChargement),
            'rappelHtml' => $this->rappelRoutes($campagne),
            // Retour visible (07/09/2026, prompt §4.2) — même règle que
            // Packaging/Pesee/Réception : équipe_chargement n'a pas accès
            // à livraison.campagnes.show, repli sur le point d'entrée
            // "choisir" — voir UrlRetourEquipe (Section B du refactor).
            'urlRetour' => $this->urlRetourEquipe($campagne, 'livraison.chargement.choisir'),
        ]);
    }

    /**
     * Endpoint de polling de l'écran chargement (Scénario 1 du chantier
     * "polling live") — appelé toutes les 20s par le script de
     * chargement.blade.php. Renvoie exactement ce que index() rend au
     * chargement de la page (mêmes lignes, même tri, mêmes stats, même
     * filtre_chargement transmis en query string) sous forme de HTML de
     * carte par tournée + une signature (md5 du HTML) que le client compare
     * pour ne remplacer que les cartes réellement modifiées. Depuis le
     * 06/10/2026 le même appel couvre aussi les familles pas encore dans une
     * tournée et le rappel « générez les routes », dont le statut dépend du
     * packaging.
     *
     * Pas de logique métier nouvelle ici : lecture seule, même autorisation
     * (can:equipeChargement) que index().
     */
    public function liste(Request $request, Campagne $campagne): JsonResponse
    {
        $filtreChargement = $this->filtreDepuisRequete($request);
        ['lignes' => $lignes, 'stats' => $stats] = $this->construireListe($campagne, $filtreChargement);
        $rappelHtml = $this->rappelRoutes($campagne);

        return response()->json([
            'stats' => $stats,
            'routes' => $lignes,
            'familles' => $this->construireFamillesSansTournee($campagne, $filtreChargement),
            'rappel' => ['html' => $rappelHtml, 'sig' => md5($rappelHtml)],
        ]);
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
     * Requête + tri + stats + filtre + rendu des cartes, partagés par
     * index() et liste().
     *
     * 06/10/2026 : TOUTES les tournées non démarrées sont listées, y compris
     * celles dont les colis ne sont pas prêts (statut routes 'planifiee',
     * jusque-là masquées jusqu'à ce que tous les colis soient prêts). Le
     * statut affiché est dérivé du conditionnement — voir StatutChargement :
     * Restante → En préparation (au moins un colis prêt) → Prête (tous
     * prêts, bouton « Chargement confirmé ») → Chargée. packaging_annule
     * reste affiché tel quel et compte avec les restantes.
     *
     * Tri : chargée en dernier (§7.2), puis les plus urgentes en tête
     * (§7.3), puis les prêtes avant les autres (ce sont les seules
     * actionnables). Les stats sont calculées sur l'ensemble AVANT
     * application du filtre.
     *
     * @return array{lignes: list<array{id: int, statut: string, etat: string, sig: string, html: string}>, stats: array{chargees: int, restantes: int, en_preparation: int, pretes: int}}
     */
    private function construireListe(Campagne $campagne, string $filtreChargement): array
    {
        $routes = RouteLivraison::where('id_campagne', $campagne->id)
            ->whereIn('statut', ['planifiee', 'chargement', 'charge', 'packaging_annule'])
            // vehiculeType + colis (09/10/2026) : type de véhicule et nombre de colis à charger de la carte.
            ->with(['benevole', 'vehiculeType', 'etapes.livraison.famille:id,nom,prenom,etudiant,est_hotel,nombre_enfant', 'etapes.livraison.creneaux', 'etapes.livraison.colis'])
            ->get()
            // Une tournée vidée de tous ses arrêts n'a rien à charger.
            ->filter(fn(RouteLivraison $route) => $route->etapes->isNotEmpty())
            ->map(function (RouteLivraison $route) {
                $route->urgence = $this->calculerUrgence($route);
                $route->etat = StatutChargement::pourRoute($route);

                return $route;
            })
            ->sortBy([
                // charge (déjà chargée) toujours en dernier — §7.2.
                fn($route) => $route->etat === StatutChargement::CHARGEE ? 1 : 0,
                // Puis famille-urgente avant bénévole-urgent avant le
                // reste — §7.3 : "family-availability should weigh more
                // since we can replace the driver".
                fn($route) => match ($route->urgence) {
                    'famille' => 0,
                    'benevole' => 1,
                    default => 2,
                },
                // Puis les tournées prêtes à charger avant les autres.
                fn($route) => $route->etat === StatutChargement::PRETE ? 0 : 1,
            ])
            ->values();

        $stats = [
            'chargees' => $routes->where('etat', StatutChargement::CHARGEE)->count(),
            'restantes' => $routes->whereIn('etat', [StatutChargement::RESTANTE, StatutChargement::PACKAGING_ANNULE])->count(),
            'en_preparation' => $routes->where('etat', StatutChargement::EN_PREPARATION)->count(),
            'pretes' => $routes->where('etat', StatutChargement::PRETE)->count(),
        ];

        if (isset(StatutChargement::FILTRES[$filtreChargement])) {
            $cible = StatutChargement::FILTRES[$filtreChargement];
            $etats = $cible === StatutChargement::RESTANTE
                ? [StatutChargement::RESTANTE, StatutChargement::PACKAGING_ANNULE]
                : [$cible];
            $routes = $routes->whereIn('etat', $etats)->values();
        }

        $lignes = $routes->map(function (RouteLivraison $route) {
            $html = trim(view('livraison.partials.chargement-route', ['route' => $route])->render());

            return [
                'id' => $route->id,
                'statut' => $route->statut,
                'etat' => $route->etat,
                'sig' => md5($html),
                'html' => $html,
            ];
        })->all();

        return ['lignes' => $lignes, 'stats' => $stats];
    }

    /**
     * Familles confirmées (hors se_deplace : retrait QG, pas de tournée)
     * qui ne sont dans aucune tournée — avant la génération, ou famille
     * confirmée après coup. Statut affiché = leur conditionnement (06/10/2026
     * ; auparavant « En préparation » en dur et seulement tant qu'aucune
     * tournée n'existait). Lecture seule : cet écran n'a aucune action sur le
     * conditionnement, seulement Packaging.
     *
     * Suivent le filtre de l'écran comme les tournées : « Chargées » n'en
     * montre aucune (une famille sans tournée n'est pas chargée), les autres
     * filtres retiennent le même statut de conditionnement.
     *
     * @return list<array{id: int, etat: string, sig: string, html: string}>
     */
    private function construireFamillesSansTournee(Campagne $campagne, string $filtreChargement = 'toutes'): array
    {
        if ($filtreChargement === 'chargees') {
            return [];
        }

        $etatVoulu = StatutChargement::FILTRES[$filtreChargement] ?? null;

        return Livraison::where('id_campagne', $campagne->id)
            ->where('statut_contact', 'confirme')
            ->where('se_deplace', false)
            ->where('statut', 'non_assignee')
            ->with('famille:id,nom,prenom,etudiant,est_hotel,nombre_enfant')
            ->orderBy('id')
            ->get()
            ->filter(fn(Livraison $livraison) => $etatVoulu === null || StatutChargement::pourFamille($livraison) === $etatVoulu)
            ->values()
            ->map(function (Livraison $livraison) {
                $html = trim(view('livraison.partials.chargement-famille', ['livraison' => $livraison])->render());

                return [
                    'id' => $livraison->id,
                    'etat' => StatutChargement::pourFamille($livraison),
                    'sig' => md5($html),
                    'html' => $html,
                ];
            })
            ->all();
    }

    /**
     * Rappel « générez les routes » (reprise du 24/09/2026 §1.2, refait le
     * 06/10/2026) : HTML du bandeau, ou chaîne vide. Affiché dès qu'il y a
     * des familles confirmées à livrer et AUCUNE tournée (hors annulées) —
     * indépendamment du conditionnement, qui n'a pas à être terminé pour
     * générer. Deux variantes : campagne pas encore démarrée (le bouton mène
     * au hub, qui propose « Démarrer la campagne ») ou démarrée (le bouton
     * ouvre l'assistant de génération).
     */
    private function rappelRoutes(Campagne $campagne): string
    {
        $afaire = Livraison::where('id_campagne', $campagne->id)
            ->where('statut_contact', 'confirme')
            ->where('se_deplace', false)
            ->exists();

        $routesExistent = RouteLivraison::where('id_campagne', $campagne->id)
            ->where('statut', '!=', 'annulee')
            ->exists();

        if (!$afaire || $routesExistent) {
            return '';
        }

        $utilisateur = auth()->user();

        return trim(view('livraison.partials.chargement-rappel', [
            'campagne' => $campagne,
            'demarree' => in_array($campagne->statut, ['en_cours', 'terminee'], true),
            'peutGenerer' => (bool) ($utilisateur?->isAdmin() || $utilisateur?->isGestionnaire()),
        ])->render());
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
