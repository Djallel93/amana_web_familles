<?php
// app/Http/Controllers/Admin/Livraison/CampagnesController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use Amana\Shared\Models\Secteur;
use Amana\Shared\Models\Ville;
use App\Http\Controllers\Controller;
use App\Models\Campagne;
use App\Models\CampagnePoidsMoyenHistorique;
use App\Models\Livraison;
use App\Models\Organisation;
use App\Models\Quartier;
use App\Models\RouteLivraison;
use App\Services\BenevoleDisponibiliteService;
use App\Services\LivraisonGenerationService;
use App\Support\RouteOptimizationConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
    ) {
    }

    public function index(): View
    {
        $campagnes = Campagne::orderByDesc('date_livraison')->get();

        return view('livraison.campagnes', [
            'campagnes' => $campagnes,
            // Préremplissage visible du formulaire "Nouvelle campagne" (prompt
            // du 08/09/2026 §2.2.3) — même valeur que celle effectivement
            // appliquée à la création si le champ est laissé vide (voir
            // store() ci-dessous), affichée ici pour que l'admin la voie et
            // puisse la modifier AVANT de créer, pas seulement après coup.
            'livraisonsMaxParTourneeDefaut' => RouteOptimizationConfig::maxLivraisonsParRoute(),
            // Ajouté le 09/09/2026 (prompt de cette date §2.1) : le
            // formulaire de création expose désormais le même champ HQ
            // optionnel que la page détail — même principe de
            // préremplissage visible que ci-dessus, voir aussi
            // CampagnesController::store() pour le repli côté serveur si
            // laissé vide.
            'hqGlobalDefaut' => RouteOptimizationConfig::coordonneesHq(),
        ]);
    }

    public function show(Campagne $campagne): View
    {
        // quartiers/organisations passés ici (ajouté le 03/09/2026) pour
        // les selects du filtre d'éligibilité côté Vue — même requête et
        // même ordre que FamillesController::index() pour son propre
        // filtre quartier/organisation, réutilisés tels quels plutôt que
        // d'ajouter un endpoint JSON dédié pour un référentiel déjà
        // disponible en lecture partout ailleurs dans l'app.
        return view('livraison.campagne-detail', [
            'campagne' => $campagne->load(['journees', 'poidsMoyenHistorique.loggePar:id,nom,prenom']),
            'quartiers' => Quartier::orderBy('nom')->get(['id', 'nom', 'id_secteur']),
            // villes/secteurs ajoutés le 05/09/2026 (prompt §1.6) : même
            // référentiel que FamillesController::index(), pour le
            // sélecteur ville → secteur → quartier en cascade du nouveau
            // FamilleFilterPanel.vue partagé.
            'villes' => Ville::orderBy('nom')->get(['id', 'nom']),
            'secteurs' => Secteur::orderBy('nom')->get(['id', 'nom', 'id_ville']),
            'organisations' => Organisation::actifs()->orderBy('nom')->get(['id', 'nom']),
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

        return response()->json(['success' => true, 'campagne' => $campagne->load('journees')], 201);
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
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $campagne->update([...$validator->validated(), 'hq_confirmee_le' => now()]);

        return response()->json(['success' => true, 'campagne' => $campagne->fresh()]);
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

        return response()->json([
            'success' => true,
            'campagne' => $campagne->fresh(),
            'historique' => $campagne->poidsMoyenHistorique()->with('loggePar:id,nom,prenom')->get(),
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

        return response()->json(['success' => true, 'journee' => $journee], 201);
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
     * appelants existants. quartier.secteur.ville chargé pour l'affichage
     * ville/secteur en colonne du tableau (CampagneDetail.vue).
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
        $query = $this->generationService->eligibles([
            'criticite_min' => $request->integer('criticite_min') ?: null,
            'id_quartier' => $request->integer('id_quartier') ?: null,
            'id_organisation' => $request->integer('id_organisation') ?: null,
        ], $campagne, $request, appliquerTriParDefaut: false)->with('quartier.secteur.ville');

        $this->appliquerTriEligibles($query, $request);

        // ids_only (05/09/2026, prompt §1.6.3 : "option to select/deselect
        // all after filtering") — mêmes ids que la liste filtrée, TOUTES
        // pages, pour que "tout sélectionner" côté Vue n'ait pas besoin de
        // paginer manuellement pour les récupérer.
        if ($request->boolean('ids_only')) {
            return response()->json(['ids' => $query->pluck('familles.id')]);
        }

        return response()->json($query->paginate($request->integer('per_page') ?: 50)->withQueryString());
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
            'conflits' => $resultat['conflits']->map(fn ($f) => [
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
    public function avancement(Campagne $campagne): JsonResponse
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
            ],
        ]);
    }
}
