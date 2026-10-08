<?php
// app/Http/Controllers/Admin/Livraison/CampagnesController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use Amana\Shared\Models\Secteur;
use Amana\Shared\Models\Ville;
use App\Http\Controllers\Controller;
use App\Http\Resources\CampagneJourneeResource;
use App\Http\Resources\CampagnePoidsMoyenHistoriqueResource;
use App\Http\Resources\CampagneResource;
use App\Http\Resources\FamilleEligibleResource;
use App\Models\BenevoleDisponibilite;
use App\Models\Campagne;
use App\Models\CampagnePoidsMoyenHistorique;
use App\Models\Livraison;
use App\Models\Organisation;
use App\Models\PersonneDesactivee;
use App\Models\Quartier;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Services\BenevoleDisponibiliteService;
use App\Services\CampagneDemarrageService;
use App\Services\IncidentResolutionService;
use App\Services\LivraisonGenerationService;
use App\Services\RetraitHqSchedulingService;
use App\Support\RouteOptimizationConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Création/gestion des campagnes + sélection des familles éligibles
 * (admin/gestionnaire — accès "Full" sur ces deux lignes de la matrice de
 * droits, voir le prompt du 30/08/2026 §4/§7).
 *
 * eligibles()/genererLivraisons() couvrent une étape nécessaire mais non
 * explicitement décrite comme telle dans le prompt : la confirmation
 * famille/bénévole (Patch 2) suppose que les lignes Livraison existent
 * déjà — voir échange du 31/08/2026 élargissant le périmètre du Patch 2
 * pour inclure cette génération.
 */
class CampagnesController extends Controller
{
    public function __construct(
        private readonly LivraisonGenerationService $generationService,
        private readonly BenevoleDisponibiliteService $disponibiliteService,
    ) {}

    /**
     * Section E4 du refactor (16/09/2026, troisième chunk du domaine
     * livraison) — page Inertia, remplace resources/views/livraison/
     * campagnes.blade.php (supprimée dans ce même chunk). show() est
     * passé à Inertia dans le chunk suivant (campagne-detail.blade.php).
     *
     * La liste passe en prop de page plutôt qu'en XHR au montage — cas B
     * de la décision du 16/09/2026 (comme Livraison/Equipes) : elle
     * n'est ni paginée ni filtrée côté serveur (voir CampagnesIndex.vue,
     * filtre type/date appliqué en Vue sur la liste déjà en main), et
     * cette action la produisait déjà telle quelle avant ce chunk — la
     * Blade se contentait déjà de la sérialiser une fois pour toutes
     * (voir campagnes.blade.php avant suppression, "la Blade ne fait
     * plus que passer la liste déjà chargée").
     */
    public function index(): InertiaResponse
    {
        $campagnes = Campagne::orderByDesc('date_livraison')->get();

        // Liste seule depuis le 03/10/2026 : le formulaire de création a
        // déménagé sur sa propre page (creer() ci-dessous, bouton « Nouvelle
        // campagne » en haut à droite) — ne restent ici que les URLs de la
        // liste : aperçu (stats d'une ligne dépliée, chargées à la demande
        // comme resumeSuppression), suppression et création.
        return Inertia::render('Livraison/Campagnes', [
            'campagnes' => CampagneResource::collection($campagnes),
            'creerUrl' => route('livraison.campagnes.creer'),
            'apercuUrlTemplate' => route('livraison.campagnes.apercu', ['campagne' => '__CAMPAGNE__']),
            'resumeSuppressionUrlTemplate' => route('livraison.campagnes.resume-suppression', ['campagne' => '__CAMPAGNE__']),
            'destroyUrlTemplate' => route('livraison.campagnes.destroy', ['campagne' => '__CAMPAGNE__']),
        ]);
    }

    /**
     * Page « Nouvelle campagne » (03/10/2026) — le formulaire qui vivait
     * sous la liste (CampagnesIndex.vue), à part entière : mêmes champs,
     * même POST store(), mais type sans valeur par défaut et obligatoire,
     * au moins une date, sections toutes visibles (seul « Paramètres
     * avancés » reste repliable) et récapitulatif de confirmation avant
     * l'envoi — voir CampagneCreer.vue.
     */
    public function creer(): InertiaResponse
    {
        return Inertia::render('Livraison/CampagneCreer', [
            'storeUrl' => route('livraison.campagnes.store'),
            'retourUrl' => route('livraison.campagnes.index'),
            // Préremplissage visible (prompt du 08/09/2026 §2.2.3) — même
            // valeur que celle appliquée si le champ est laissé vide (voir
            // store()), affichée pour que l'admin la voie AVANT de créer.
            'livraisonsMaxParTourneeDefaut' => RouteOptimizationConfig::maxLivraisonsParRoute(),
            'googlePlacesKey' => config('services.google.maps.places_api_key'),
            // Idem pour le HQ (prompt du 09/09/2026 §2.1) : laissé vide, la
            // campagne retombe sur le réglage global.
            'hqGlobalDefaut' => RouteOptimizationConfig::coordonneesHq(),
        ]);
    }

    /**
     * Statistiques d'une campagne pour la ligne dépliée de la liste
     * (03/10/2026) — demandées à la première ouverture de la ligne, pas
     * précalculées pour toute la liste (même principe que
     * resumeSuppression()). Choix de l'utilisateur : familles, contacts,
     * « se déplace au QG », bénévoles, poids estimé/collecté, tournées par
     * statut, taux de packaging, livraisons livrées/ignorées/en attente.
     *
     * Définitions :
     *  - « confirmées » = livraisons dont statut_contact = 'confirme' ;
     *    base du taux de packaging et du suivi livré/ignoré/en attente (une
     *    famille rejetée/archivée ne sera jamais livrée) ;
     *  - poids estimé = somme de livraisons.poids_kg hors rejetées/archivées ;
     *    poids collecté = somme des donations (Campagne::poids_collecte_kg) ;
     *  - bénévoles « disponibles » = personnes distinctes ayant confirmé au
     *    moins une journée de la campagne, hors comptes désactivés ;
     *    « en attente » = personnes ayant une disponibilité non confirmée et
     *    aucune confirmée. L'envoi des invitations n'étant pas journalisé
     *    (simple email), « notifiés » n'est pas calculable ici.
     */
    public function apercu(Campagne $campagne): JsonResponse
    {
        $livraisons = $campagne->livraisons();
        $confirmees = (clone $livraisons)->where('statut_contact', 'confirme');

        $parContact = (clone $livraisons)->selectRaw('statut_contact, COUNT(*) as total')
            ->groupBy('statut_contact')->pluck('total', 'statut_contact');

        $parStatutRoute = $campagne->routes()->selectRaw('statut, COUNT(*) as total')
            ->groupBy('statut')->pluck('total', 'statut');

        $nbConfirmees = (clone $confirmees)->count();
        $nbPretes = (clone $confirmees)->where('statut_conditionnement', 'prete')->count();

        $parLivraison = (clone $confirmees)->selectRaw('statut, COUNT(*) as total')
            ->groupBy('statut')->pluck('total', 'statut');

        $disponibilites = BenevoleDisponibilite::whereIn('id_campagne_journee', $campagne->journees()->pluck('id'))
            ->whereNotIn('id_personne', PersonneDesactivee::ids())
            ->get(['id_personne', 'statut']);
        $disponibles = $disponibilites->where('statut', 'confirme')->pluck('id_personne')->unique();
        $enAttente = $disponibilites->where('statut', '!=', 'confirme')->pluck('id_personne')->unique()->diff($disponibles);

        return response()->json([
            'familles' => [
                'total' => (clone $livraisons)->count(),
                'confirmees' => $nbConfirmees,
                'se_deplacent' => (clone $livraisons)->where('se_deplace', true)->count(),
            ],
            'contacts' => collect(Livraison::STATUTS_CONTACT)
                ->mapWithKeys(fn(string $statut) => [$statut => (int) ($parContact[$statut] ?? 0)])->all(),
            'benevoles' => [
                'disponibles' => $disponibles->count(),
                'en_attente' => $enAttente->count(),
            ],
            'poids' => [
                'estime_kg' => round((float) (clone $livraisons)->whereNotIn('statut_contact', ['rejetee', 'archive'])->sum('poids_kg'), 1),
                'collecte_kg' => round($campagne->poids_collecte_kg, 1),
            ],
            'tournees' => [
                'total' => (int) $parStatutRoute->sum(),
                'par_statut' => collect(RouteLivraison::STATUTS)
                    ->mapWithKeys(fn(string $statut) => [$statut => (int) ($parStatutRoute[$statut] ?? 0)])
                    ->filter(fn(int $n) => $n > 0)->all(),
            ],
            'packaging' => [
                'pretes' => $nbPretes,
                'confirmees' => $nbConfirmees,
                'taux' => $nbConfirmees > 0 ? (int) round($nbPretes * 100 / $nbConfirmees) : null,
            ],
            'livraisons' => [
                'livrees' => (int) ($parLivraison['livree'] ?? 0),
                'ignorees' => (int) ($parLivraison['ignoree'] ?? 0),
                'en_attente' => (int) ($parLivraison['non_assignee'] ?? 0) + (int) ($parLivraison['assignee'] ?? 0) + (int) ($parLivraison['en_cours'] ?? 0),
            ],
        ]);
    }

    /**
     * Hub de la campagne (03/10/2026) — page d'ensemble des processus en
     * cours, plus une surface d'édition : cartes groupées par phase (avant
     * la campagne / préparation / livraison) + incidents, boutons
     * Statistiques, Paramètres et Terminer/Rouvrir en haut à droite.
     * L'édition HQ/commentaire, l'ajout de journée et les équipes vivent
     * sur la page Paramètres (parametres()), la sélection des familles sur
     * sa page dédiée (familles()), la génération des routes sur Suivi
     * livraison (LiveBoardController).
     */
    public function show(Campagne $campagne): InertiaResponse
    {
        return Inertia::render('Livraison/CampagneDetail', [
            // journees chargées (06/10/2026) : l'assistant « Génération des
            // routes » choisit la journée à traiter.
            'campagne' => new CampagneResource($campagne->load('journees')),
            'retourUrl' => route('livraison.campagnes.index'),
            'avancementUrl' => route('livraison.campagnes.avancement', $campagne),
            // « Démarrer la campagne » (06/10/2026) — voir CampagneDemarrageService.
            'demarrerUrl' => route('livraison.campagnes.demarrer', $campagne),
            // Référentiels du tableau de familles du mode personnalisé de
            // l'assistant (mêmes que l'ancien constructeur de tournée de
            // Suivi livraison, dont c'est désormais le seul emplacement).
            'quartiers' => Quartier::orderBy('nom')->get(['id', 'nom', 'id_secteur']),
            'villes' => Ville::orderBy('nom')->get(['id', 'nom']),
            'secteurs' => Secteur::orderBy('nom')->get(['id', 'nom', 'id_ville']),
            'organisations' => Organisation::actifs()->orderBy('nom')->get(['id', 'nom']),
            'generationUrls' => [
                'chauffeurs' => route('livraison.campagnes.chauffeurs-disponibles', $campagne),
                'apercu' => route('livraison.campagnes.apercu-generation', $campagne),
                'generer' => route('livraison.campagnes.generer-routes', $campagne),
                'personnalisee' => route('livraison.routes.personnalisee', $campagne),
                'nonCouvertesTableau' => route('livraison.campagnes.non-couvertes-tableau', $campagne),
            ],
            // Section Incidents repliable du hub (06/10/2026) — remplace la
            // page dédiée.
            'incidentsUrls' => [
                'liste' => route('livraison.campagnes.incidents-liste', $campagne),
                'resoudre' => route('livraison.incidents.resoudre', ['incident' => '__ID__']),
                'ignorer' => route('livraison.incidents.ignorer', ['incident' => '__ID__']),
            ],
            'clotureUrl' => route('livraison.campagnes.cloture', $campagne),
            'terminerUrl' => route('livraison.campagnes.terminer', $campagne),
            'rouvrirUrl' => route('livraison.campagnes.rouvrir', $campagne),
            'forcerIncidentsUrl' => route('livraison.campagnes.incidents.forcer-resolution', $campagne),
            'urls' => [
                'statistiques' => route('livraison.statistiques.index', $campagne),
                'parametres' => route('livraison.campagnes.parametres', $campagne),
                'familles' => route('livraison.familles-eligibles.index', $campagne),
                'contacts' => route('livraison.contacts.index', ['id_campagne' => $campagne->id]),
                'benevoles' => route('livraison.campagnes.benevoles.index', $campagne),
                'reception' => route('livraison.reception.show', $campagne),
                'pesee' => route('livraison.pesee.show', $campagne),
                'packaging' => route('livraison.packaging.index', $campagne),
                'chargement' => route('livraison.chargement.index', $campagne),
                'retraitHq' => route('livraison.retrait-hq.index', $campagne),
                'suiviLivraison' => route('livraison.suivi-livraison.index', $campagne),
            ],
        ]);
    }

    /**
     * Page dédiée « Sélection des familles éligibles » (03/10/2026) — sortie
     * du hub, avec accès depuis la barre latérale (campagne optionnelle,
     * même patron que Suivi livraison : sans campagne, la page propose d'en
     * choisir une) et depuis la carte du hub.
     */
    public function familles(?Campagne $campagne = null): InertiaResponse
    {
        return Inertia::render('Livraison/CampagneFamilles', [
            'campagne' => $campagne ? new CampagneResource($campagne->load('journees')) : null,
            'campagnes' => CampagneResource::collection(Campagne::orderByDesc('date_livraison')->get()),
            'quartiers' => Quartier::orderBy('nom')->get(['id', 'nom', 'id_secteur']),
            'villes' => Ville::orderBy('nom')->get(['id', 'nom']),
            'secteurs' => Secteur::orderBy('nom')->get(['id', 'nom', 'id_ville']),
            'organisations' => Organisation::actifs()->orderBy('nom')->get(['id', 'nom']),
            'retourUrl' => $campagne
                ? route('livraison.campagnes.show', $campagne)
                : route('livraison.campagnes.index'),
            'urlsCampagne' => $campagne ? [
                'eligibles' => route('livraison.campagnes.eligibles', $campagne),
                'genererLivraisons' => route('livraison.campagnes.generer-livraisons', $campagne),
            ] : null,
            'choisirUrlTemplate' => route('livraison.familles-eligibles.index', ['campagne' => '__CAMPAGNE__']),
        ]);
    }

    /**
     * Page Paramètres de la campagne (03/10/2026) : HQ & commentaire,
     * ajout de journée, équipes — ce qui vivait sur l'ancienne page détail
     * (et sur /equipes, désormais redirigée ici). ?onglet=hq|journees|equipes.
     */
    public function parametres(Request $request, Campagne $campagne, EquipeMembresController $equipes): InertiaResponse
    {
        $onglet = in_array($request->query('onglet'), ['hq', 'journees', 'equipes'], true)
            ? $request->query('onglet')
            : 'hq';

        return Inertia::render('Livraison/CampagneParametres', [
            'campagne' => new CampagneResource($campagne->load(['journees', 'poidsMoyenHistorique.loggePar:id,nom,prenom'])),
            'onglet' => $onglet,
            'googlePlacesKey' => config('services.google.maps.places_api_key'),
            'updateUrl' => route('livraison.campagnes.update', $campagne),
            'ajouterJourneeUrl' => route('livraison.campagnes.journees.store', $campagne),
            'lignesEquipe' => $equipes->lignesEquipe($campagne),
            'ajouterEquipeUrl' => route('livraison.campagnes.equipes.ajouter', $campagne),
            'retirerEquipeUrlTemplate' => route('livraison.campagnes.equipes.retirer', [$campagne, '__ID__', '__ROLE__']),
            'retourUrl' => route('livraison.campagnes.show', $campagne),
        ]);
    }

    /**
     * $request->journees : un tableau de journées à créer d'un coup dès
     * la création de la campagne (05/09/2026, suivi du prompt §3.1/§3.2)
     * — au lieu d'un unique date_livraison direct. Chaque élément devient
     * une CampagneJournee via Campagne::ajouterJournee(), y compris le
     * premier (qui synchronise aussi date_livraison — voir cette
     * méthode). Le cas mono-jour (le plus courant) est simplement un
     * tableau à un seul élément, aucune régression de comportement par
     * rapport à avant cette évolution.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:' . implode(',', Campagne::TYPES),
            'journees' => 'required|array|min:1',
            'journees.*.date' => 'required|date',
            'journees.*.label' => 'nullable|string|max:100',
            'poids_moyen_kg' => 'required|numeric|min:0',
            'poids_moyen_hotel_kg' => 'nullable|numeric|min:0',
            'poids_moyen_etudiant_kg' => 'nullable|numeric|min:0',
            // Ajoutés le 05/09/2026 (prompt §1.2/§1.3).
            'commentaire' => 'nullable|string|max:5000',
            'hq_adresse' => 'nullable|string|max:255',
            // Ajoutés le 09/09/2026 (prompt §2.1) : le formulaire "Nouvelle
            // campagne" expose désormais le même champ HQ optionnel que la
            // page détail (auparavant saisissable seulement après coup) —
            // voir plus bas : si absents, la valeur reste le réglage
            // global recopié, comme avant cette évolution.
            'hq_latitude' => 'nullable|numeric|between:-90,90',
            'hq_longitude' => 'nullable|numeric|between:-180,180',
            // Ajouté le 08/09/2026 (prompt §2.2.3) — même statut que hq_adresse
            // ci-dessus : optionnel ici, préremplie automatiquement plus bas
            // si absente (voir $livraisonsMaxParTournee).
            'livraisons_max_par_tournee' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $donnees = $validator->validated();
        $journeesDemandees = $donnees['journees'];
        unset($donnees['journees']);

        // HQ préremplie depuis le réglage global à la création (prompt du
        // 05/09/2026 §1.2 : "always prefill with it") — copiée une fois
        // pour toutes, PAS relue dynamiquement ensuite (voir docblock de
        // create_campagnes_domain_tables.php). L'adresse n'a pas d'équivalent
        // global (settings ne stocke que lat/lng) : seule celle saisie ici
        // (le cas échéant) est conservée. Depuis le 09/09/2026 (prompt
        // §2.1), l'admin peut aussi saisir explicitement lat/lng dès la
        // création (nouveau champ optionnel du formulaire) — dans ce cas
        // c'est cette valeur qui prime, le réglage global ne servant plus
        // que de repli si le champ est laissé vide, exactement comme pour
        // livraisons_max_par_tournee juste en dessous.
        $hqGlobal = RouteOptimizationConfig::coordonneesHq();
        $donnees['hq_latitude'] ??= $hqGlobal['lat'] ?? null;
        $donnees['hq_longitude'] ??= $hqGlobal['lng'] ?? null;

        // Même logique que hq_* ci-dessus (prompt du 08/09/2026 §2.2.3) :
        // préremplie depuis le réglage global si l'admin ne l'a pas saisie
        // explicitement, une fois pour toutes à la création — voir
        // RouteOptimizationConfig::maxLivraisonsParRoutePourCampagne().
        $donnees['livraisons_max_par_tournee'] ??= RouteOptimizationConfig::maxLivraisonsParRoute();

        // date_livraison (colonne NOT NULL, voir create_campagnes_domain_tables.php)
        // déduite de la première journée saisie — ajouterJournee()
        // resynchronisera la même valeur juste après, sans effet
        // supplémentaire (voir docblock de cette méthode).
        $campagne = Campagne::create([
            ...$donnees,
            'date_livraison' => $journeesDemandees[0]['date'],
            'statut' => 'preparation',
        ]);

        foreach ($journeesDemandees as $journeeDemandee) {
            $campagne->ajouterJournee($journeeDemandee['date'], $journeeDemandee['label'] ?? null);
        }

        return response()->json(['success' => true, 'campagne' => new CampagneResource($campagne->load('journees'))], 201);
    }

    /**
     * Édition de la campagne (commentaire + HQ propre) — voir le prompt
     * du 05/09/2026 §1.2/§1.3. Pas de page d'édition dédiée dans cette
     * app : c'est la page détail elle-même (CampagneDetail.vue) qui sert
     * de surface d'édition, comme pour le reste de la campagne.
     * Commentaire : dernière valeur seulement (pas d'historique, décision
     * explicite) — un update() écrase simplement l'ancien.
     *
     * hq_confirmee_le (09/09/2026, prompt de cette date §3.2) : posé à
     * now() à CHAQUE appel de ce endpoint, quels que soient les champs
     * effectivement modifiés — c'est justement le bouton "Confirmer" (
     * renommé depuis "Enregistrer" ce même jour) de la section HQ &
     * commentaire de CampagneDetail.vue qui appelle update(), donc
     * l'atteindre EST l'action de confirmation ; pas de logique
     * conditionnelle à dupliquer côté serveur pour deviner si le HQ a
     * "vraiment" changé.
     */
    public function update(Request $request, Campagne $campagne): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'commentaire' => 'nullable|string|max:5000',
            'hq_adresse' => 'nullable|string|max:255',
            'hq_latitude' => 'nullable|numeric|between:-90,90',
            'hq_longitude' => 'nullable|numeric|between:-180,180',
            // Éditable au cas par cas comme hq_* (prompt du 08/09/2026 §2.2.3).
            'livraisons_max_par_tournee' => 'nullable|integer|min:1',
            // Ajoutés le 24/09/2026 (prompt de cette date §2/§4) — bornées
            // à 08h-19h (même plage que App\Support\Creneau) : "be sure to
            // base it on current timeslots to avoid generating handouts
            // at night". before_or_equal plutôt que before : une fenêtre
            // d'une minute reste valide (étalement dégénéré géré par
            // RetraitHqSchedulingService, pas une erreur de validation).
            'heure_debut_arrivee_hq' => 'nullable|date_format:H:i|after_or_equal:08:00',
            'heure_fin_arrivee_hq' => 'nullable|date_format:H:i|before_or_equal:19:00|after:heure_debut_arrivee_hq',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $donnees = $validator->validated();
        // Fenêtre HQ modifiée : ré-étale IMMÉDIATEMENT toutes les
        // journées déjà planifiées de cette campagne (prompt §4 :
        // "recompute both routes and handouts if flag changes for
        // current campagne" — même principe appliqué ici à un changement
        // de fenêtre plutôt que de flag individuel). Pas de recompute des
        // TOURNÉES ici : la fenêtre HQ ne concerne que les familles
        // se_deplace, jamais le clustering.
        $fenetreModifiee = array_key_exists('heure_debut_arrivee_hq', $donnees) || array_key_exists('heure_fin_arrivee_hq', $donnees);

        $campagne->update([...$donnees, 'hq_confirmee_le' => now()]);

        if ($fenetreModifiee) {
            $campagne = $campagne->fresh();
            foreach ($campagne->journees as $journee) {
                app(RetraitHqSchedulingService::class)->planifierPour($campagne, $journee);
            }
        }

        return response()->json(['success' => true, 'campagne' => new CampagneResource($campagne->fresh())]);
    }

    /**
     * Aperçu des répercussions AVANT suppression — alimente l'écran de
     * confirmation de CampagnesIndex.vue (prompt du 08/09/2026 §2.1/§2.2 :
     * "confirmation screen explaining repercussions"). Comptages seulement,
     * aucune écriture ici.
     */
    public function resumeSuppression(Campagne $campagne): JsonResponse
    {
        return response()->json([
            'journees' => $campagne->journees()->count(),
            'livraisons' => $campagne->livraisons()->count(),
            'routes' => $campagne->routes()->count(),
            'donations' => $campagne->donations()->count(),
            'arrivees' => $campagne->arrivees()->count(),
            'equipe_membres' => $campagne->equipeMembres()->count(),
        ]);
    }

    /**
     * Suppression définitive d'une campagne — prompt du 08/09/2026
     * §2.1/§2.2 : "Yes I want to always be able to delete. The delete
     * trigger a cascade delete." Toujours autorisée, y compris sur une
     * campagne avec de l'activité réelle (app encore en dev — décision
     * explicite, pas de blocage "campagne non vide").
     *
     * Aucune suppression manuelle des tables enfants ici : routes,
     * livraisons, campagne_journees, donations, campagne_arrivees,
     * campagne_stats_snapshots, benevole_retours_qg,
     * campagne_poids_moyen_historiques et campagne_equipe_membres portent
     * TOUS un ->cascadeOnDelete() vers campagnes (voir chaque migration
     * create_*_table.php), et leurs propres enfants (etapes_route,
     * route_incidents, livraison_colis, livraison_creneaux,
     * benevole_disponibilites → benevole_disponibilite_creneaux) cascadent
     * de la même façon en chaîne — $campagne->delete() suffit, la
     * contrainte FK fait le reste au niveau base de données plutôt que de
     * dupliquer cette liste ici en PHP (qui se désynchroniserait au
     * premier oubli lors d'un futur ajout de table).
     */
    public function destroy(Campagne $campagne): JsonResponse
    {
        $campagne->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Modifie poids_moyen_kg/hotel/etudiant et journalise chaque
     * changement effectif dans campagne_poids_moyen_historiques — voir le
     * prompt du 05/09/2026 §5.2. N'écrit une ligne d'historique QUE si la
     * valeur soumise diffère réellement de l'existante (évite de polluer
     * le journal avec des re-soumissions identiques du formulaire).
     *
     * Ne touche JAMAIS livraisons.poids_kg ici (voir recalculerPoids() —
     * action séparée, volontairement manuelle) : les tournées déjà
     * construites reposent sur les poids figés à la génération, les
     * modifier silencieusement ici désynchroniserait routes.poids_total_kg
     * sans que personne ne le sache.
     */
    public function mettreAJourPoidsMoyen(Request $request, Campagne $campagne): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'poids_moyen_kg' => 'nullable|numeric|min:0',
            'poids_moyen_hotel_kg' => 'nullable|numeric|min:0',
            'poids_moyen_etudiant_kg' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $correspondance = [
            'poids_moyen_kg' => 'normal',
            'poids_moyen_hotel_kg' => 'hotel',
            'poids_moyen_etudiant_kg' => 'etudiant',
        ];

        foreach ($validator->validated() as $colonne => $nouvelleValeur) {
            if ($nouvelleValeur === null) {
                continue;
            }
            $ancienneValeur = (float) $campagne->{$colonne};
            if ((float) $nouvelleValeur === $ancienneValeur) {
                continue;
            }

            CampagnePoidsMoyenHistorique::create([
                'id_campagne' => $campagne->id,
                'type' => $correspondance[$colonne],
                'ancienne_valeur' => $ancienneValeur,
                'nouvelle_valeur' => $nouvelleValeur,
                'logge_par' => auth()->id(),
            ]);
            $campagne->{$colonne} = $nouvelleValeur;
        }

        $campagne->save();

        // 'campagne' retiré de la réponse le 12/09/2026 (Section E3 du
        // refactor, suite) : jamais lu par enregistrerPoidsMoyen()
        // (packaging.blade.php, seul appelant de cet endpoint) — seul
        // 'historique' y est consommé, voir CampagnePoidsMoyenHistoriqueResource.
        return response()->json([
            'success' => true,
            'historique' => CampagnePoidsMoyenHistoriqueResource::collection($campagne->poidsMoyenHistorique()->with('loggePar:id,nom,prenom')->get()),
        ]);
    }

    /**
     * Recalcule livraisons.poids_kg avec les poids moyens ACTUELS de la
     * campagne — action manuelle distincte de mettreAJourPoidsMoyen() (voir
     * le prompt du 05/09/2026 §5.2 : "auto-track history + opt-in manual
     * recalc, never touching already-packaged ones").
     *
     * Scopée aux seules livraisons statut_conditionnement = 'en_attente' :
     * une livraison déjà conditionnée ('prete') OU déjà partiellement
     * conditionnée ('en_cours', ajouté le 09/09/2026 — prompt de cette
     * date §2.1) peut correspondre à des colis physiquement déjà préparés
     * sous l'ancien poids — la toucher ici romprait silencieusement la
     * cohérence entre poids_kg et ce qui a réellement été pesé/emballé. Si la livraison appartient déjà à une
     * tournée 'planifiee', son poids_kg change ici mais
     * routes.poids_total_kg de cette tournée reste, lui, inchangé —
     * supprimer la tournée (LiveBoardController::supprimerRoute(), qui
     * remet ses livraisons à 'non_assignee') puis relancer le clustering
     * est le geste explicite qui la reconstruit avec un total à jour.
     */
    public function recalculerPoids(Campagne $campagne): JsonResponse
    {
        $livraisons = Livraison::where('id_campagne', $campagne->id)
            ->where('statut_conditionnement', 'en_attente')
            ->with('famille')
            ->get();

        $misesAJour = 0;
        foreach ($livraisons as $livraison) {
            if (!$livraison->famille) {
                continue;
            }
            $nouveauPoids = Livraison::calculerPoidsKg($livraison->famille, $campagne, $livraison->nombre_personnes);
            if ((float) $nouveauPoids !== (float) $livraison->poids_kg) {
                $livraison->update(['poids_kg' => $nouveauPoids]);
                $misesAJour++;
            }
        }

        return response()->json(['success' => true, 'nombre_livraisons_recalculees' => $misesAJour]);
    }

    /**
     * Ajoute une journée à une campagne existante, à tout moment — avant
     * comme après le démarrage de l'opération (voir Campagne::ajouterJournee() :
     * "couvre à la fois la planification initiale... et le cas 'on vient
     * de décider d'un jour de collecte/livraison en plus'"). Distinct de
     * store() (§3.1/§3.2 du 05/09/2026) : store() prend plusieurs
     * journées EN UNE FOIS à la création, cet endpoint en ajoute UNE
     * SEULE sur une campagne déjà créée (voir le bouton "+ Ajouter une
     * journée" de CampagneDetail.vue).
     */
    public function ajouterJournee(Request $request, Campagne $campagne): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'label' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $journee = $campagne->ajouterJournee($request->input('date'), $request->input('label'));

        return response()->json(['success' => true, 'journee' => new CampagneJourneeResource($journee)], 201);
    }

    /**
     * Liste des familles éligibles à une campagne, filtrable par
     * criticité/quartier/organisation — voir
     * LivraisonGenerationService::eligibles(). Consommée par l'écran de
     * sélection (île Vue, voir app.ts) pour construire la liste de
     * familles à cocher avant génération. Exclut les familles déjà
     * pourvues d'une Livraison pour CETTE campagne (voir le service).
     */
    /**
     * Liste des familles éligibles à une campagne — voir
     * LivraisonGenerationService::eligibles(). Filtres alignés le
     * 05/09/2026 (prompt §1.6) sur EXACTEMENT ceux de Dossier Familles
     * (App\Support\FamilleFilters), plus les 3 critères historiques
     * (criticite_min/id_quartier/id_organisation) pour ne rien casser côté
     * appelants existants. quartier chargé pour l'affichage du nom de
     * quartier en colonne du tableau (CampagneDetail.vue) — secteur/ville
     * ne sont plus chargés depuis le 12/09/2026 (Section E3 du refactor,
     * suite) : le template ne les lit pas (voir FamilleEligibleResource).
     */
    /**
     * Colonnes triables de la table éligibilité (05/09/2026, prompt
     * §1.2.3) — même principe de whitelist que
     * FamillesController::COLONNES_TRIABLES (pas de colonne arbitraire
     * passée telle quelle à orderBy()).
     */
    private const COLONNES_TRIABLES_ELIGIBLES = ['id', 'nom', 'telephone', 'telephone_bis', 'criticite', 'nombre_adulte', 'nombre_enfant', 'derniere_livraison_le'];

    private function appliquerTriEligibles($query, Request $request): void
    {
        $colonne = $request->input('tri');
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';

        if (!in_array($colonne, self::COLONNES_TRIABLES_ELIGIBLES, true)) {
            $query->orderByDesc('criticite')->orderBy('derniere_livraison_le');

            return;
        }

        match ($colonne) {
            'nom' => $query->orderBy('nom', $direction)->orderBy('prenom', $direction),
            default => $query->orderBy($colonne, $direction),
        };
    }

    public function eligibles(Request $request, Campagne $campagne): JsonResponse
    {
        // ->with('quartier') seul (pas 'quartier.secteur.ville') depuis le
        // 12/09/2026 (Section E3 du refactor, suite) : ni
        // CampagneDetail.vue ni BuildRouteFlow.vue (qui partage désormais
        // FamilleEligibleResource, voir LiveBoardController::
        // nonCouvertesTable()) ne lisent .secteur/.ville sur cette ligne —
        // voir le docblock de ce resource.
        $query = $this->generationService->eligibles([
            'criticite_min' => $request->integer('criticite_min') ?: null,
            'id_quartier' => $request->integer('id_quartier') ?: null,
            'id_organisation' => $request->integer('id_organisation') ?: null,
        ], $campagne, $request, appliquerTriParDefaut: false)->with('quartier');

        $this->appliquerTriEligibles($query, $request);

        // ids_only (05/09/2026, prompt §1.6.3 : "option to select/deselect
        // all after filtering") — mêmes ids que la liste filtrée, TOUTES
        // pages, pour que "tout sélectionner" côté Vue n'ait pas besoin de
        // paginer manuellement pour les récupérer.
        if ($request->boolean('ids_only')) {
            return response()->json(['ids' => $query->pluck('familles.id')]);
        }

        // FamilleEligibleResource appliqué directement sur la collection du
        // paginator plutôt que XResource::collection($paginator) (Section
        // E3 du refactor, 12/09/2026) : préserve la forme JSON plate
        // actuelle (current_page/data/... à la racine, voir
        // RawLaravelPaginator côté TS) — changer cette forme est un sujet à
        // part, volontairement pas traité ici.
        $paginateur = $query->paginate($request->integer('per_page') ?: 50)->withQueryString();
        $paginateur->getCollection()->transform(fn($famille) => new FamilleEligibleResource($famille));

        return response()->json($paginateur);
    }

    /**
     * Génère les lignes Livraison pour les familles sélectionnées — voir
     * LivraisonGenerationService::genererPour(). Renvoie séparément les
     * conflits etudiant/est_hotel (jamais résolus silencieusement, voir
     * ce service) pour que l'écran affiche clairement lesquelles n'ont
     * pas été traitées et pourquoi.
     */
    public function genererLivraisons(Request $request, Campagne $campagne): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids_familles' => 'required|array|min:1',
            'ids_familles.*' => 'integer|exists:familles,id',
            // Requis depuis le 05/09/2026 : toute campagne a désormais au
            // moins une CampagneJournee (voir store()), donc les
            // livraisons générées doivent toujours être rattachées à l'une
            // d'elles — plus de génération "orpheline" de journée.
            'id_campagne_journee' => 'required|integer|exists:campagne_journees,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $journee = $campagne->journees()->findOrFail($request->integer('id_campagne_journee'));

        $resultat = $this->generationService->genererPour($campagne, $request->input('ids_familles'), $journee);

        return response()->json([
            'success' => true,
            'generees' => $resultat['livraisons']->count(),
            'deja_existantes' => $resultat['deja_existantes'],
            'conflits' => $resultat['conflits']->map(fn($f) => [
                'id' => $f->id,
                'nom' => "{$f->prenom} {$f->nom}",
                'raison' => 'etudiant_et_est_hotel',
            ])->values(),
        ]);
    }

    /**
     * Déclenche l'envoi de l'email de disponibilité à tous les bénévoles
     * validés — voir le prompt §3.2. Action explicite déclenchée par
     * l'admin/gestionnaire (un bouton), pas un effet de bord automatique
     * d'un changement de statut de campagne : le prompt ne précise pas à
     * quel changement de statut précis rattacher ce "lancement", une
     * action explicite évite d'inventer une règle non demandée — décision
     * du 31/08/2026.
     */
    public function notifierBenevoles(Campagne $campagne): JsonResponse
    {
        $resultat = $this->disponibiliteService->notifierCampagne($campagne);

        // Voir create_campagnes_domain_tables.php (colonne ajoutée le 03/09/2026) :
        // seule trace persistée que cette étape a eu lieu, pour
        // CampagneProgressBar.vue — indépendante du nombre d'envois
        // réussis/échoués, l'étape "notifier" est considérée franchie dès
        // qu'on a tenté, pas seulement si 100% des emails sont partis.
        $campagne->update(['benevoles_notifies_le' => now()]);

        return response()->json(['success' => true, ...$resultat]);
    }

    /**
     * Résumé d'avancement de la campagne à travers les étapes du workflow
     * livraison — voir le prompt du 03/09/2026 (checklist de progression
     * CampagneProgressBar.vue, amana_shared_ui n'a pas cette pièce, elle
     * est propre au domaine livraison donc reste locale à cette app).
     * Un seul aller-retour plutôt que le front ne recalcule depuis
     * plusieurs endpoints déjà existants (eligibles/non-couvertes...) qui
     * ne portent chacun qu'un fragment de l'image d'ensemble.
     */
    public function avancement(Campagne $campagne, CampagneDemarrageService $demarrage): JsonResponse
    {
        $livraisons = Livraison::where('id_campagne', $campagne->id)
            ->select('statut_contact', 'statut_conditionnement')
            ->get();

        $livraisonsTotal = $livraisons->count();
        $livraisonsAConfirmer = $livraisons->whereIn('statut_contact', ['a_contacter', 'contacte'])->count();
        $livraisonsConfirmees = $livraisons->where('statut_contact', 'confirme')->count();
        $livraisonsPretes = $livraisons->where('statut_conditionnement', 'prete')->count();

        $routes = RouteLivraison::where('id_campagne', $campagne->id)->select('statut')->get();
        $routesTotal = $routes->count();
        // 'charge' ajouté le 09/09/2026 (prompt §4) : nouveau statut
        // intercalé entre 'chargement' et 'en_cours' (voir docblock de la
        // migration routes) — doit rester compté ici au même titre que
        // les deux, cette ligne mesure "route sortie de planifiee", pas
        // "route effectivement chargée".
        $routesChargees = $routes->whereIn('statut', ['chargement', 'charge', 'en_cours', 'livraisons_terminees', 'terminee'])->count();
        $routesEnLivraison = $routes->whereIn('statut', ['en_cours', 'livraisons_terminees', 'terminee'])->count();
        $routesTerminees = $routes->where('statut', 'terminee')->count();

        return response()->json([
            // statut + demarrage_bloque (06/10/2026) : le hub bascule du
            // bouton « Démarrer la campagne » à « Génération des routes »
            // sans rechargement, et grise le bouton avec la raison du refus.
            'statut' => $campagne->statut,
            'demarrage_bloque' => $demarrage->raisonDeRefus($campagne),
            'livraisons_generees' => $livraisonsTotal > 0,
            'contacts_termines' => $livraisonsTotal > 0 && $livraisonsAConfirmer === 0,
            'contacts_en_cours' => $livraisonsTotal > 0 && $livraisonsAConfirmer > 0 && $livraisonsAConfirmer < $livraisonsTotal,
            'benevoles_notifies' => $campagne->benevoles_notifies_le !== null,
            'routes_generees' => $routesTotal > 0,
            // Ajouté le 09/09/2026 (prompt de cette date §3.3) : aucune
            // pilule ne représentait le poste réception sur cet écran —
            // même raisonnement/étape facultative que pesee_demarree
            // ci-dessous, voir PosteReleveController::show() (type 'reception').
            'reception_demarree' => $campagne->arrivees()->exists(),
            'pesee_demarree' => $campagne->donations()->exists(),
            'packaging_termine' => $livraisonsConfirmees > 0 && $livraisonsPretes >= $livraisonsConfirmees,
            'chargement_termine' => $routesTotal > 0 && $routesChargees === $routesTotal,
            'livraison_en_cours' => $routesTotal > 0 && $routesEnLivraison > 0,
            'terminee' => $routesTotal > 0 && $routesTerminees === $routesTotal,
            'compteurs' => [
                'livraisons_total' => $livraisonsTotal,
                'livraisons_confirmees' => $livraisonsConfirmees,
                'routes_total' => $routesTotal,
                'routes_terminees' => $routesTerminees,
                // Carte « Incidents » du hub (03/10/2026) : nombre d'incidents
                // ouverts (statut null — jalon chargement_termine — exclu par
                // le where).
                'incidents_ouverts' => $this->incidentsOuverts($campagne),
            ],
        ]);
    }

    private function incidentsOuverts(Campagne $campagne): int
    {
        return RouteIncident::ouverts()
            ->whereHas('route', fn($q) => $q->where('id_campagne', $campagne->id))
            ->count();
    }

    /**
     * Pré-contrôle de la clôture (03/10/2026) — alimente la fenêtre
     * « Terminer la campagne » du hub : tournées non terminées (BLOQUANT),
     * incidents ouverts et livraisons confirmées pas encore livrées/ignorées
     * (simples avertissements). « Non terminée » = ni 'terminee' ni
     * 'annulee' (packaging_annule compte : sa tournée n'est pas finie).
     */
    public function cloture(Campagne $campagne): JsonResponse
    {
        $routes = $campagne->routes()
            ->whereNotIn('statut', ['terminee', 'annulee'])
            ->with('benevole:id,nom,prenom')
            ->orderBy('id')
            ->get()
            ->map(fn(RouteLivraison $r) => [
                'id' => $r->id,
                'statut' => $r->statut,
                'benevole' => $r->benevole ? trim("{$r->benevole->prenom} {$r->benevole->nom}") : null,
            ])->all();

        return response()->json([
            'statut' => $campagne->statut,
            'routes_non_terminees' => $routes,
            'incidents_ouverts' => $this->incidentsOuverts($campagne),
            'livraisons_en_attente' => $campagne->livraisons()
                ->where('statut_contact', 'confirme')
                ->whereIn('statut', ['non_assignee', 'assignee', 'en_cours'])
                ->count(),
        ]);
    }

    /**
     * Marque la campagne terminée (03/10/2026). Refusée (422) tant qu'une
     * tournée n'est pas terminée/annulée — revérifié ici, l'interface n'est
     * pas la seule garde. Les incidents ouverts ne bloquent pas : la fenêtre
     * propose de les résoudre de force (forcerResolutionIncidents()) mais
     * c'est un choix de l'utilisateur. 'terminee' retire la campagne des
     * listes « campagnes en cours » (équipes) et de la règle de verrou de
     * désactivation d'une personne (PersonneActivationService).
     */
    public function terminer(Campagne $campagne): JsonResponse
    {
        if ($campagne->statut === 'terminee') {
            return response()->json(['success' => false, 'message' => 'Cette campagne est déjà terminée.'], 422);
        }

        $routes = $campagne->routes()->whereNotIn('statut', ['terminee', 'annulee'])->count();
        if ($routes > 0) {
            return response()->json([
                'success' => false,
                'message' => "{$routes} tournée(s) ne sont pas terminées : terminez-les ou annulez-les avant de clôturer.",
            ], 422);
        }

        $avant = $campagne->statut;
        $campagne->update(['statut' => 'terminee']);
        audit('update', 'campagnes', $campagne->id, ['statut' => $avant], ['statut' => 'terminee']);

        return response()->json(['success' => true, 'campagne' => new CampagneResource($campagne->fresh())]);
    }

    /**
     * « Démarrer la campagne » (06/10/2026) — voir CampagneDemarrageService :
     * statut 'en_cours', tournées imposées, rendez-vous et emails de retrait
     * QG. Refus en 422 avec un message prêt à afficher.
     */
    public function demarrer(Campagne $campagne, CampagneDemarrageService $demarrage): JsonResponse
    {
        try {
            $resultat = $demarrage->demarrer($campagne);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            ...$resultat,
            'campagne' => new CampagneResource($campagne->fresh()->load('journees')),
        ]);
    }

    /**
     * Rouvre une campagne terminée (03/10/2026) — retour à 'en_cours' si elle
     * avait des tournées (06/10/2026, voir ci-dessous), sinon 'preparation'.
     */
    public function rouvrir(Campagne $campagne): JsonResponse
    {
        if ($campagne->statut !== 'terminee') {
            return response()->json(['success' => false, 'message' => "Cette campagne n'est pas terminée."], 422);
        }

        // Retour à 'en_cours' dès que la campagne a des tournées (elle avait
        // démarré) : repasser en 'preparation' ferait réapparaître « Démarrer
        // la campagne » et renverrait les emails de retrait QG (06/10/2026).
        $statut = $campagne->routes()->exists() ? 'en_cours' : 'preparation';
        $campagne->update(['statut' => $statut]);
        audit('update', 'campagnes', $campagne->id, ['statut' => 'terminee'], ['statut' => $statut]);

        return response()->json(['success' => true, 'campagne' => new CampagneResource($campagne->fresh())]);
    }

    /**
     * « Tout résoudre de force » de la fenêtre de clôture : tous les
     * incidents ouverts de la campagne passent à 'resolu', sans re-clustering.
     */
    public function forcerResolutionIncidents(Campagne $campagne, IncidentResolutionService $incidents): JsonResponse
    {
        $nombre = $incidents->forcerResolutionPourCampagne($campagne);
        if ($nombre > 0) {
            audit('update', 'route_incidents', null, null, ['campagne' => $campagne->id, 'resolus_de_force' => $nombre]);
        }

        return response()->json(['success' => true, 'resolus' => $nombre]);
    }
}
