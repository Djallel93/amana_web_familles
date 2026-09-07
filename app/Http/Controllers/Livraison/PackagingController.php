<?php
// app/Http/Controllers/Livraison/PackagingController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use Amana\Shared\Models\Personne;
use App\Http\Controllers\Controller;
use App\Models\Campagne;
use App\Models\Livraison;
use App\Models\LivraisonColis;
use App\Models\RouteIncident;
use App\Notifications\PackagingAnnuleNotification;
use App\Notifications\RoutePretePourChargementNotification;
use App\Services\QrCodeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;

/**
 * Écran packaging — équipe_packaging compose les colis à partir de la
 * file de priorité des livraisons en attente (headcount, etudiant/
 * est_hotel/nombre_enfant, note_besoins_speciaux en LECTURE SEULE), marque
 * chaque colis `statut_conditionnement = prete`, imprime étiquettes et
 * feuille de préparation — voir le prompt du 30/08/2026 §3.4/§4/§7.
 *
 * Découplé de l'assignation route/chauffeur PAR CONCEPTION (voir §3.4) :
 * la file (index()) ne filtre JAMAIS par tournée/bénévole, uniquement par
 * statut_conditionnement — une livraison peut être conditionnée avant
 * même d'avoir une tournée. Un défaut de l'ancien système (le no-show
 * d'un chauffeur invalidait le travail déjà préparé pour SA tournée)
 * disparaît par construction : marquerPret() ne touche jamais à
 * l'assignation.
 *
 * Imprimables en HTML navigateur (@media print), pas de PDF — décision du
 * 31/08/2026 : ces documents sont imprimés sur place, pas envoyés par
 * email, une dépendance PDF n'apporterait rien ici.
 */
class PackagingController extends Controller
{
    public function __construct(
        private readonly QrCodeService $qrCode,
    ) {
    }

    /**
     * File de priorité : livraisons en attente de conditionnement pour
     * cette campagne, triées par criticité (les plus urgentes en premier)
     * — voir le prompt §3.4.
     */
    /**
     * Point d'entrée sans campagne — voir le prompt du 05/09/2026 §4.1 :
     * equipe_packaging n'avait jusqu'ici AUCUNE entrée de menu vers cet
     * écran (config/amana-shared.php ne listait que les écrans
     * gestionnaire), et les routes existantes exigent un {campagne} que
     * cette équipe n'a pas de moyen de choisir. Liste les campagnes
     * actives, chaque lien mène directement à index() pour la campagne
     * choisie.
     */
    public function choisir(): View
    {
        $campagnes = Campagne::whereIn('statut', ['preparation', 'en_cours'])
            ->with('journees')
            ->orderByDesc('date_livraison')
            ->get();

        return view('livraison.choisir-poste', [
            'campagnes' => $campagnes,
            'titre' => 'Packaging — choisir une campagne',
            'routeIndex' => 'livraison.packaging.index',
            'avecJournee' => true,
        ]);
    }

    /**
     * File de priorité : livraisons en attente de conditionnement pour
     * cette campagne, triées par criticité (les plus urgentes en premier)
     * — voir le prompt §3.4. Filtrable par journée (05/09/2026, prompt
     * §5.4) via id_campagne_journee, optionnel (toutes journées si non
     * précisé — comportement historique inchangé par défaut).
     */
    public function index(Request $request, Campagne $campagne): View
    {
        $query = Livraison::where('id_campagne', $campagne->id)
            ->where('statut_conditionnement', 'en_attente')
            ->where('statut_contact', 'confirme') // 07/09/2026, prompt §3.2
            ->whereNotIn('statut', ['ignoree', 'livree']);

        if ($request->filled('id_campagne_journee')) {
            $query->where('id_campagne_journee', $request->input('id_campagne_journee'));
        }

        $livraisons = $query
            ->with(['famille:id,nom,prenom,criticite,etudiant,est_hotel,nombre_adulte,nombre_enfant', 'colis'])
            ->get()
            ->sortByDesc(fn (Livraison $l) => $l->famille->criticite ?? 0)
            ->values();

        return view('livraison.packaging', [
            'campagne' => $campagne->load(['journees', 'poidsMoyenHistorique.loggePar:id,nom,prenom']),
            'livraisons' => $livraisons,
            'idCampagneJourneeSelectionnee' => $request->integer('id_campagne_journee') ?: null,
            'autresCampagnes' => Campagne::whereIn('statut', ['preparation', 'en_cours'])->orderByDesc('date_livraison')->get(),
            'urlRetour' => (auth()->user()->isAdmin() || auth()->user()->isGestionnaire())
                ? route('livraison.campagnes.show', $campagne)
                : route('livraison.packaging.choisir'),
        ]);
    }

    /**
     * Voir le prompt du 05/09/2026 §5.3 : un colis par personne du foyer
     * (livraisons.nombre_personnes), pas une case unique par famille —
     * chaque colis se marque indépendamment, la case "famille entière" ne
     * se coche que lorsque TOUS ses colis sont prêts (voir colisTousPrets()
     * sur Livraison), et reste grisée/indisponible tant que ce n'est pas
     * le cas (contrôle côté Vue ET revalidé ici côté serveur).
     */
    public function marquerColisPret(Request $request, LivraisonColis $colis): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'statut' => 'required|in:' . implode(',', LivraisonColis::STATUTS),
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $colis->update([
            'statut' => $request->input('statut'),
            'pret_le' => $request->input('statut') === 'pret' ? now() : null,
            'pret_par' => $request->input('statut') === 'pret' ? auth()->id() : null,
        ]);

        $livraison = $colis->livraison;
        $livraison->refresh();

        if ($livraison->colisTousPrets() && $livraison->statut_conditionnement !== 'prete') {
            $this->finaliserConditionnement($livraison);
        }

        return response()->json([
            'success' => true,
            'colis' => $livraison->colis,
            'statut_conditionnement' => $livraison->fresh()->statut_conditionnement,
        ]);
    }

    /**
     * Bascule la tournée en 'chargement' + notifie l'équipe chargement/
     * chauffeur quand TOUTES les livraisons de cette tournée sont
     * désormais prêtes — logique inchangée par rapport à l'ancien
     * marquerPret() (voir le prompt du 03/09/2026 §2.9), simplement
     * extraite ici pour être appelée depuis marquerColisPret() une fois
     * le dernier colis d'une famille coché, plutôt que sur un bouton
     * "famille entière" cliqué directement par l'utilisateur.
     */
    private function finaliserConditionnement(Livraison $livraison): void
    {
        $livraison->update(['statut_conditionnement' => 'prete']);

        $etape = $livraison->etapesRoute()->with('route.etapes.livraison')->first();

        if ($etape) {
            $route = $etape->route;
            $toutesPretes = $route->etapes->every(
                fn ($e) => $e->livraison === null || $e->livraison->statut_conditionnement === 'prete',
            );

            if ($toutesPretes && $route->statut === 'planifiee') {
                $route->update(['statut' => 'chargement']);

                $destinataires = Personne::avecRole('equipe_chargement')->get();

                if ($route->id_benevole) {
                    $chauffeur = Personne::find($route->id_benevole);
                    if ($chauffeur) {
                        $destinataires->push($chauffeur);
                    }
                }

                Notification::send($destinataires, new RoutePretePourChargementNotification($route));
            }
        }
    }

    /**
     * Annule un conditionnement déjà marqué prêt, pour reprendre les
     * colis (erreur de manipulation) — voir le prompt du 05/09/2026 §5.3.
     * $request->confirme doit être explicitement true : la confirmation
     * elle-même a lieu côté Vue (boîte de dialogue avant d'décocher la
     * case "famille entière"), ce champ revalide juste côté serveur
     * qu'elle a bien eu lieu plutôt que de faire confiance à l'UI seule.
     *
     * Si la tournée avait déjà basculé en 'chargement' (équipe
     * chargement/chauffeur déjà notifiés), elle redescend à
     * 'packaging_annule' et un RouteIncident du même type est levé +
     * notifié — sinon (tournée encore 'planifiee', ou pas encore de
     * tournée du tout), rien à avertir : personne n'a encore été informé
     * que ce colis était prêt.
     */
    public function annulerConditionnement(Request $request, Livraison $livraison): JsonResponse
    {
        $validator = Validator::make($request->all(), ['confirme' => 'required|accepted']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        if ($livraison->statut_conditionnement !== 'prete') {
            return response()->json(['success' => false, 'message' => "Cette livraison n'est pas conditionnée."], 422);
        }

        $livraison->update(['statut_conditionnement' => 'en_attente']);
        $livraison->colis()->update(['statut' => 'a_preparer', 'pret_le' => null, 'pret_par' => null]);

        $etape = $livraison->etapesRoute()->first();
        $route = $etape?->route;

        if ($route && $route->statut === 'chargement') {
            $route->update(['statut' => 'packaging_annule']);

            RouteIncident::create([
                'id_route' => $route->id,
                'type' => 'packaging_annule',
                'id_livraison' => $livraison->id,
                'signale_par' => auth()->id(),
                'statut' => 'ouvert',
                'notes' => $request->input('notes'),
            ]);

            $destinataires = Personne::avecRole('equipe_chargement')->get();
            if ($route->id_benevole) {
                $chauffeur = Personne::find($route->id_benevole);
                if ($chauffeur) {
                    $destinataires->push($chauffeur);
                }
            }
            Notification::send($destinataires, new PackagingAnnuleNotification($route, $livraison));
        }

        return response()->json(['success' => true, 'colis' => $livraison->fresh()->colis]);
    }

    /**
     * Feuille de préparation imprimable — une ligne par famille du lot en
     * cours, réimprimable à tout moment (voir le prompt §3.4 : "safe to
     * reprint/regenerate at any time without needing to know or care
     * about route/driver state").
     */
    public function feuillePreparation(Campagne $campagne): View
    {
        $livraisons = Livraison::where('id_campagne', $campagne->id)
            ->with('famille:id,nom,prenom,criticite,etudiant,est_hotel,nombre_adulte,nombre_enfant')
            ->get()
            ->sortByDesc(fn (Livraison $l) => $l->famille->criticite ?? 0)
            ->values();

        return view('livraison.feuille-preparation', ['campagne' => $campagne, 'livraisons' => $livraisons]);
    }
}
