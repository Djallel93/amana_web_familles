<?php
// app/Http/Controllers/Livraison/ChargementController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use Amana\Shared\Models\Personne;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Livraison\Concerns\FiltreCampagnesEquipe;
use App\Models\Campagne;
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Notifications\RouteChargeeNotification;
use App\Services\QrCodeService;
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

    public function __construct(
        private readonly QrCodeService $qrCode,
    ) {
    }

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
        $routes = RouteLivraison::where('id_campagne', $campagne->id)
            ->whereIn('statut', ['chargement', 'charge', 'packaging_annule'])
            ->with(['benevole', 'etapes.livraison.famille:id,nom,prenom,etudiant,est_hotel,nombre_enfant', 'etapes.livraison.creneaux'])
            ->get()
            ->map(function (RouteLivraison $route) {
                $route->urgence = $this->calculerUrgence($route);

                return $route;
            })
            ->sortBy([
                // charge (déjà chargée) toujours en dernier — §7.2.
                fn ($route) => $route->statut === 'charge' ? 1 : 0,
                // Puis famille-urgente avant bénévole-urgent avant le
                // reste — §7.3 : "family-availability should weigh more
                // since we can replace the driver".
                fn ($route) => match ($route->urgence) {
                    'famille' => 0,
                    'benevole' => 1,
                    default => 2,
                },
            ])
            ->values();

        // Stats + filtre (09/09/2026, prompt de cette date §4) — mêmes
        // stats/filtres que Packaging (voir PackagingController::index())
        // : "Toutes"/"Restantes"/"Chargée" plutôt que "Terminées", cet
        // écran n'a que deux statuts pertinents (packaging_annule compté
        // avec 'chargement' dans "Restantes", comme dans le tri
        // ci-dessus) — voir hors scope les tournées 'en_cours', pas
        // récupérées par la requête au-dessus.
        $stats = [
            'chargees' => $routes->where('statut', 'charge')->count(),
            'restantes' => $routes->whereIn('statut', ['chargement', 'packaging_annule'])->count(),
        ];

        $filtreChargement = $request->input('filtre_chargement', 'toutes');
        if ($filtreChargement === 'restantes') {
            $routes = $routes->whereIn('statut', ['chargement', 'packaging_annule'])->values();
        } elseif ($filtreChargement === 'chargees') {
            $routes = $routes->where('statut', 'charge')->values();
        }

        return view('livraison.chargement', [
            'campagne' => $campagne,
            'routes' => $routes,
            'stats' => $stats,
            'filtreChargement' => $filtreChargement,
            // Retour visible (07/09/2026, prompt §4.2) — même règle que
            // Packaging/Pesee/Réception : équipe_chargement n'a pas accès
            // à livraison.campagnes.show, repli sur le point d'entrée
            // "choisir".
            'urlRetour' => (auth()->user()->isAdmin() || auth()->user()->isGestionnaire())
                ? route('livraison.campagnes.show', $campagne)
                : route('livraison.chargement.choisir'),
        ]);
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
        $creneauActuel = \App\Support\Creneau::actuel();
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
            $disponibilite = \App\Models\BenevoleDisponibilite::where('id_personne', $route->id_benevole)
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
