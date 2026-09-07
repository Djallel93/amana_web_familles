<?php
// app/Http/Controllers/Admin/Livraison/ContactTrackingController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use Amana\Shared\Models\Personne;
use App\Http\Controllers\Controller;
use App\Models\Campagne;
use App\Models\Famille;
use App\Models\Livraison;
use App\Services\FamilleConfirmationSyncService;
use App\Support\Creneau;
use App\Support\FamilleFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Tableau de suivi des contacts téléphoniques (file d'appels, filtre par
 * gestionnaire assigné, statut_contact, vue "assigné à moi") — voir le
 * prompt du 30/08/2026 §7. Assignation de contact ("Assigner un contact
 * famille") réservée gestionnaire/admin, avec validation que la personne
 * assignée détient bien le rôle gestionnaire (§2 : id_personne_assignee
 * "Must validate that the assigned person holds the gestionnaire role
 * (or admin, via existing cascade) — reject assignment otherwise").
 *
 * contacterManuel() couvre le second canal de confirmation du prompt §3.1
 * ("Family has no email → phone contact by staff... Staff enters the
 * same fields directly on the campaign tracking screen") — mêmes champs
 * et mêmes règles de validation que ContactConfirmationController::store()
 * (formulaire public), staff-only ici.
 */
class ContactTrackingController extends Controller
{
    public function __construct(
        private readonly FamilleConfirmationSyncService $syncService,
    ) {
    }

    /**
     * secteursActivite/organismesAide/googlePlacesKey/googleEmbedKey
     * ajoutés le 05/09/2026 (prompt §2.3) — mêmes référentiels que
     * FamillesController::index(), nécessaires pour monter DetailPanel.vue
     * (voir contacts.blade.php) sur cet écran aussi : "if family needs to
     * be edited when contacted, open the Family panel" — même panneau
     * partagé qu'utilise Dossier Familles, pas une copie.
     */
    public function index(): View
    {
        $campagnes = Campagne::orderByDesc('date_livraison')->with('journees')->get();
        $secteursActivite = \App\Models\SecteurActivite::actifs()->get(['id', 'code', 'libelle_fr', 'libelle_ar', 'libelle_en']);
        $organismesAide = \App\Models\OrganismeAide::actifs()->get(['id', 'code', 'libelle_fr', 'libelle_ar', 'libelle_en']);
        // Référentiels du filtre partagé (05/09/2026, prompt §2.6) — mêmes
        // requêtes que CampagnesController::show()/FamillesController::index().
        $villes = \Amana\Shared\Models\Ville::orderBy('nom')->get(['id', 'nom']);
        $secteurs = \Amana\Shared\Models\Secteur::orderBy('nom')->get(['id', 'nom', 'id_ville']);
        $quartiers = \App\Models\Quartier::orderBy('nom')->get(['id', 'nom', 'id_secteur']);
        $organisations = \App\Models\Organisation::actifs()->orderBy('nom')->get(['id', 'nom']);

        return view('livraison.contacts', compact(
            'campagnes', 'secteursActivite', 'organismesAide', 'villes', 'secteurs', 'quartiers', 'organisations',
        ));
    }

    /**
     * File des livraisons pas encore confirmées, filtrable par
     * gestionnaire assigné — `mine=1` couvre la vue "assigné à moi" du
     * prompt §7. Depuis le 03/09/2026 (prompt de cette date §2.3) :
     * familles joignables par email en tête de file (email non nul
     * d'abord), pas de tri secondaire au-delà — décision explicite,
     * volontairement simple.
     *
     * Filtres Dossier Familles (05/09/2026, prompt §2.6) : appliqués via
     * App\Support\FamilleFilters, mais PAS directement sur cette requête
     * (elle interroge Livraison, pas Famille — les scopes locaux de
     * FamilleFilters comme recherche()/rechercheNom() n'existent que sur
     * le Builder de Famille). On résout donc d'abord les id_famille
     * correspondants via une requête Famille séparée, puis un whereIn()
     * classique — même pattern que id_secteur/id_ville dans
     * FamillesController::baseQuery() (éviter un whereHas() entre deux
     * requêtes qui n'ont ici aucune raison structurelle d'être fusionnées).
     *
     * per_page (05/09/2026, prompt §2.7) : configurable, 50 par défaut
     * (comportement historique inchangé si absent).
     *
     * ids_only (05/09/2026, prompt §2.6 : "select/deselect all after
     * filtering") : renvoie uniquement la liste des ids Livraison
     * correspondant aux filtres courants (TOUTES pages), pour que
     * "sélectionner tout le filtré" côté Vue n'ait pas à paginer pour
     * récupérer les ids un par un avant d'assigner en lot.
     */
    public function queue(Request $request): JsonResponse
    {
        $query = Livraison::with(['famille:id,nom,prenom,telephone,telephone_bis,email,id_quartier', 'famille.quartier.secteur.ville', 'personneAssignee', 'campagne'])
            ->where('statut_contact', '!=', 'confirme')
            ->join('familles', 'familles.id', '=', 'livraisons.id_famille')
            ->orderByRaw('familles.email IS NULL')
            ->select('livraisons.*');

        if ($request->boolean('mine')) {
            $query->where('id_personne_assignee', auth()->id());
        } elseif ($request->filled('id_personne_assignee')) {
            $query->where('id_personne_assignee', $request->input('id_personne_assignee'));
        }

        if ($request->filled('id_campagne')) {
            $query->where('id_campagne', $request->input('id_campagne'));
        }
        // id_campagne_journee / statut_contact (05/09/2026, prompt §1.5) :
        // le bouton de lancement du clustering a été déplacé sur cet écran
        // (voir le prompt) et doit vérifier, pour LA JOURNÉE choisie, qu'il
        // ne reste plus aucune famille à statut_contact = 'a_contacter' —
        // le front interroge ce même endpoint avec ids_only=1 pour ce
        // calcul plutôt que dupliquer la logique de filtre côté serveur.
        if ($request->filled('id_campagne_journee')) {
            $query->where('id_campagne_journee', $request->input('id_campagne_journee'));
        }
        if ($request->filled('statut_contact')) {
            $query->where('statut_contact', $request->input('statut_contact'));
        }

        if ($this->requeteAUnFiltreFamille($request)) {
            $idsFamilles = tap(Famille::query(), fn ($q) => FamilleFilters::appliquer($q, $request))->pluck('id');
            $query->whereIn('id_famille', $idsFamilles);
        }

        if ($request->boolean('ids_only')) {
            return response()->json(['ids' => $query->pluck('livraisons.id')]);
        }

        return response()->json($query->paginate($request->integer('per_page') ?: 50)->withQueryString());
    }

    /**
     * Clés reconnues par App\Support\FamilleFilters — si aucune n'est
     * présente, on évite l'aller-retour vers Famille (qui retournerait de
     * toute façon tous les ids sans rien filtrer).
     */
    private function requeteAUnFiltreFamille(Request $request): bool
    {
        return $request->hasAny([
            'id_quartier', 'id_secteur', 'id_ville', 'zakat_el_fitr', 'sadaqa',
            'se_deplace', 'est_hotel', 'etudiant', 'criticite', 'recherche',
            'id_selection', 'nom', 'telephone', 'id_organisation_origine', 'id_organisation_rattachee',
        ]);
    }

    /**
     * Assigne (ou réassigne) une livraison à un gestionnaire pour le
     * contact téléphonique — rejette si la personne visée n'a pas le rôle
     * gestionnaire (ou admin, cascade existante), voir le prompt §2.
     */
    public function assigner(Request $request, Livraison $livraison): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_personne_assignee' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $personne = Personne::find($request->input('id_personne_assignee'));

        if (!$personne || (!$personne->isGestionnaire() && !$personne->isAdmin())) {
            return response()->json([
                'success' => false,
                'message' => 'Cette personne ne détient pas le rôle gestionnaire.',
            ], 422);
        }

        $livraison->update(['id_personne_assignee' => $personne->id]);

        return response()->json(['success' => true]);
    }

    /**
     * Assignation en lot — ajoutée le 03/09/2026 (prompt de cette date
     * §2.4) : avec 100+ familles à répartir, assigner une par une n'est
     * pas praticable. Le front filtre/sélectionne (voir ContactsQueue.vue)
     * puis poste la liste d'IDs retenue ici en un seul appel — même
     * validation du rôle gestionnaire/admin que assigner() ci-dessus,
     * appliquée une seule fois plutôt que par livraison.
     */
    public function assignerLot(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_personne_assignee' => 'required|integer',
            'ids_livraison' => 'required|array|min:1',
            'ids_livraison.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $personne = Personne::find($request->input('id_personne_assignee'));

        if (!$personne || (!$personne->isGestionnaire() && !$personne->isAdmin())) {
            return response()->json([
                'success' => false,
                'message' => 'Cette personne ne détient pas le rôle gestionnaire.',
            ], 422);
        }

        $nombre = Livraison::whereIn('id', $request->input('ids_livraison'))
            ->update(['id_personne_assignee' => $personne->id]);

        return response()->json(['success' => true, 'assignees' => $nombre]);
    }

    /**
     * Saisie téléphonique par le staff — mêmes champs/règles que
     * ContactConfirmationController::store() (formulaire public), pour
     * les familles sans email (voir le prompt §3.1). `statut_contact`
     * passe directement à 'confirme' si les 3 champs sont fournis, ou à
     * une valeur intermédiaire ('contacte'/'injoignable') si l'appel n'a
     * pas abouti à une confirmation complète — ou, depuis le 03/09/2026,
     * à 'rejetee'/'archive' si le staff détermine au contact que la
     * famille n'a plus besoin d'aide ou doit être écartée (voir
     * Livraison::STATUTS_CONTACT_EFFETS, appliqué via
     * FamilleConfirmationSyncService::appliquerEffetStatut()).
     */
    public function contacterManuel(Request $request, Livraison $livraison): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'statut_contact' => 'required|in:' . implode(',', Livraison::STATUTS_CONTACT_POSTABLES),
            'adresse_confirmee' => 'required_if:statut_contact,confirme|nullable|string|max:500',
            'code_postal_confirme' => 'nullable|string|max:10',
            'ville_confirmee' => 'nullable|string|max:150',
            'nombre_adulte_confirme' => 'required_if:statut_contact,confirme|nullable|integer|min:1|max:30',
            'nombre_enfant_confirme' => 'required_if:statut_contact,confirme|nullable|integer|min:0|max:30',
            'creneaux' => 'required_if:statut_contact,confirme|nullable|array|min:1',
            'creneaux.*' => 'in:' . implode(',', Creneau::TOUS),
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $statut = $request->input('statut_contact');
        $donnees = ['statut_contact' => $statut];
        $donneesConfirmees = null;

        if ($statut === 'confirme') {
            $donneesConfirmees = $validator->safe()->only([
                'adresse_confirmee', 'code_postal_confirme', 'ville_confirmee',
                'nombre_adulte_confirme', 'nombre_enfant_confirme',
            ]);
            $donnees = [...$donnees, ...$donneesConfirmees];
        }

        $livraison->update($donnees);

        if ($donneesConfirmees !== null) {
            $this->syncService->synchroniser($livraison, $donneesConfirmees);

            $livraison->creneaux()->delete();
            foreach ($request->input('creneaux') as $creneau) {
                $livraison->creneaux()->create(['creneau' => $creneau]);
            }
        } else {
            // 'rejetee'/'archive' (et tout futur statut à effet dossier
            // non-'sync') : voir STATUTS_CONTACT_EFFETS. 'contacte'/
            // 'injoignable' n'ont aucun effet dossier, appliquerEffetStatut()
            // est alors un no-op.
            $this->syncService->appliquerEffetStatut($livraison, $statut);
        }

        return response()->json(['success' => true]);
    }
}
