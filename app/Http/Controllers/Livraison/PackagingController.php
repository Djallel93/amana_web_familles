<?php
// app/Http/Controllers/Livraison/PackagingController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use Amana\Shared\Models\Personne;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Livraison\Concerns\FiltreCampagnesEquipe;
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
use Illuminate\Support\Facades\Auth;
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
    use FiltreCampagnesEquipe;

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
     * choisie. Liste restreinte aux campagnes affectées (08/09/2026, voir
     * FiltreCampagnesEquipe).
     */
    public function choisir(): View
    {
        $campagnes = $this->campagnesPourEquipe(Auth::user(), 'equipe_packaging', avecJournees: true);

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
     *
     * Ne filtre plus sur statut_conditionnement (08/09/2026, prompt de
     * cette date §6.1) : une famille dont tous les colis sont prêts
     * restait auparavant invisible dès le prochain chargement de cette
     * page (le filtre `en_attente` l'excluait purement et simplement de
     * la requête) — corrigé en listant TOUJOURS les deux statuts, chaque
     * ligne affiche désormais son statut (voir packaging.blade.php) et un
     * filtre optionnel `filtre_conditionnement` (§6.2, valeurs
     * restantes/terminees, "toutes" par défaut = comportement décrit
     * ci-dessus) permet de ne regarder qu'un sous-ensemble sans revenir
     * au bug d'origine.
     */
    public function index(Request $request, Campagne $campagne): View
    {
        $query = Livraison::where('id_campagne', $campagne->id)
            ->where('statut_contact', 'confirme') // 07/09/2026, prompt §3.2
            ->whereNotIn('statut', ['ignoree', 'livree']);

        if ($request->filled('id_campagne_journee')) {
            $query->where('id_campagne_journee', $request->input('id_campagne_journee'));
        }

        // Cartes statistiques (§6.3) calculées AVANT le filtre
        // restantes/terminees ci-dessous : elles doivent refléter
        // l'ensemble filtré par campagne/journée uniquement, pas le
        // sous-ensemble actuellement affiché — sinon la carte "Terminées"
        // tomberait toujours à 0 dès qu'on filtre sur "Restantes".
        //
        // 'en_cours' simplifié le 09/09/2026 (prompt de cette date §2.1) :
        // statut_conditionnement est désormais un vrai statut à 3 valeurs
        // (en_attente/en_cours/prete, voir create_livraisons_table.php et
        // Livraison::statutConditionnementDerive()), posé par
        // marquerColisPret() dès qu'au moins un colis (mais pas tous) est
        // prêt — le whereHas('colis', ...) qui servait auparavant à
        // dériver ce sous-ensemble à la volée n'est plus nécessaire, ces
        // trois comptages sont désormais mutuellement exclusifs.
        $stats = [
            'terminees' => (clone $query)->where('statut_conditionnement', 'prete')->count(),
            'restantes' => (clone $query)->where('statut_conditionnement', 'en_attente')->count(),
            'en_cours' => (clone $query)->where('statut_conditionnement', 'en_cours')->count(),
        ];

        $filtreConditionnement = $request->input('filtre_conditionnement', 'toutes');
        if ($filtreConditionnement === 'restantes') {
            $query->where('statut_conditionnement', 'en_attente');
        } elseif ($filtreConditionnement === 'terminees') {
            $query->where('statut_conditionnement', 'prete');
        } elseif ($filtreConditionnement === 'en_cours') {
            $query->where('statut_conditionnement', 'en_cours');
        }

        // 'creneaux' ajouté le 09/09/2026 (prompt de cette date §2.2) pour
        // calculerUrgencePackaging() ci-dessous — voir son docblock :
        // distinct de ChargementController::calculerUrgence(), pas de
        // comparaison au créneau horaire actuel ici.
        $livraisons = $query
            ->with(['famille:id,nom,prenom,criticite,etudiant,est_hotel,nombre_adulte,nombre_enfant', 'colis', 'creneaux'])
            ->get()
            ->map(function (Livraison $livraison) {
                $livraison->urgente = $this->calculerUrgencePackaging($livraison);

                return $livraison;
            })
            ->sortBy([
                // Urgente (créneau unique confirmé, aucun repli possible)
                // toujours en tête, avant même la criticité — §2.2 : "the
                // packaging team sees this family at the top AND with the
                // red border", indépendant de criticite.
                fn (Livraison $l) => $l->urgente ? 0 : 1,
                fn (Livraison $l) => -($l->famille->criticite ?? 0),
            ])
            ->values();

        return view('livraison.packaging', [
            'campagne' => $campagne->load(['journees', 'poidsMoyenHistorique.loggePar:id,nom,prenom']),
            'livraisons' => $livraisons,
            'stats' => $stats,
            'filtreConditionnement' => $filtreConditionnement,
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
     * se coche que lorsque TOUS ses colis sont prêts (voir
     * statutConditionnementDerive() sur Livraison), et reste grisée/
     * indisponible tant que ce n'est pas le cas (contrôle côté Vue ET
     * revalidé ici côté serveur).
     *
     * Verrouillée une fois statut_conditionnement = 'prete' (09/09/2026,
     * prompt de cette date §2.1, en même temps que l'ajout du statut
     * 'en_cours' — voir create_livraisons_table.php et
     * statutConditionnementDerive() sur Livraison) : décocher un colis
     * individuel une fois la livraison prête contournait jusque-là
     * annulerConditionnement() (pas de confirmation, pas d'incident/
     * notification à l'équipe chargement/chauffeur si la tournée était
     * déjà passée en 'chargement'/'charge') — désormais refusé ici avec
     * un message explicite, seule la case "famille entière" (qui, elle,
     * appelle annulerConditionnement() avec confirmation, voir
     * packaging.blade.php) peut faire revenir en arrière une livraison
     * déjà prête.
     */
    public function marquerColisPret(Request $request, LivraisonColis $colis): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'statut' => 'required|in:' . implode(',', LivraisonColis::STATUTS),
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $livraison = $colis->livraison;
        if ($livraison->statut_conditionnement === 'prete') {
            return response()->json([
                'success' => false,
                'message' => "Cette livraison est déjà conditionnée — décochez la case famille entière pour reprendre les colis.",
            ], 422);
        }

        $colis->update([
            'statut' => $request->input('statut'),
            'pret_le' => $request->input('statut') === 'pret' ? now() : null,
            'pret_par' => $request->input('statut') === 'pret' ? auth()->id() : null,
        ]);

        $livraison->refresh();
        $statutDerive = $livraison->statutConditionnementDerive();

        if ($statutDerive === 'prete') {
            $this->finaliserConditionnement($livraison);
        } elseif ($statutDerive !== $livraison->statut_conditionnement) {
            $livraison->update(['statut_conditionnement' => $statutDerive]);
        }

        return response()->json([
            'success' => true,
            'colis' => $livraison->colis,
            'statut_conditionnement' => $livraison->fresh()->statut_conditionnement,
        ]);
    }

    /**
     * Urgence PACKAGING (09/09/2026, prompt de cette date §2.2) — distincte
     * de ChargementController::calculerUrgence() : ici "urgente" veut dire
     * "cette famille n'a confirmé qu'UN SEUL créneau, aucun repli possible
     * si elle n'est pas prête à temps", indépendamment de l'heure actuelle
     * (contrairement à Chargement, qui ne signale l'urgence que pendant le
     * créneau concerné lui-même — packaging a lieu en amont, souvent avant
     * même l'ouverture du créneau). Sans lien avec familles.criticite : une
     * famille peu critique mais sans repli de créneau reste prioritaire en
     * tête de liste (voir le tri dans index() ci-dessus), une famille très
     * critique mais disponible sur tous les créneaux ne l'est pas.
     */
    private function calculerUrgencePackaging(Livraison $livraison): bool
    {
        return $livraison->creneaux->count() === 1;
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

                // Remplacé le 08/09/2026 : Personne::avecRole('equipe_chargement')
                // notifiait TOUT détenteur du rôle global, toutes campagnes
                // confondues — voir Campagne::personnesAvecRole() pour le
                // raisonnement complet (distinction rôle global / affectation
                // par campagne).
                $destinataires = $route->campagne->personnesAvecRole('equipe_chargement');

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
     * Si la tournée avait déjà basculé en 'chargement' OU 'charge'
     * (équipe chargement/chauffeur déjà notifiés — 'charge' ajouté le
     * 09/09/2026, prompt de cette date §4, voir docblock de la migration
     * routes), elle redescend à 'packaging_annule' et un RouteIncident du
     * même type est levé + notifié — sinon (tournée encore 'planifiee',
     * pas encore de tournée du tout, ou déjà 'en_cours' — le bénévole est
     * déjà parti, hors scope de cette annulation), rien à avertir :
     * personne n'a encore été informé que ce colis était prêt (ou il est
     * trop tard pour le rattraper par ce biais).
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

        if ($route && in_array($route->statut, ['chargement', 'charge'], true)) {
            $route->update(['statut' => 'packaging_annule']);

            RouteIncident::create([
                'id_route' => $route->id,
                'type' => 'packaging_annule',
                'id_livraison' => $livraison->id,
                'signale_par' => auth()->id(),
                'statut' => 'ouvert',
                'notes' => $request->input('notes'),
            ]);

            // Voir le commentaire équivalent dans finaliserConditionnement()
            // ci-dessus : destinataires résolus par campagne désormais, pas
            // par le rôle global.
            $destinataires = $route->campagne->personnesAvecRole('equipe_chargement');
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
