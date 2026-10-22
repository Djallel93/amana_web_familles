<?php
// app/Http/Controllers/Livraison/MaRouteController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use App\Models\BenevoleRetourQg;
use App\Models\Campagne;
use App\Models\EtapeRoute;
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Services\MaRouteVueService;
use App\Services\RetourQgService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Écran "Ma tournée" du bénévole — voir le prompt du 30/08/2026 §7.
 * Accès restreint à SA PROPRE tournée (admin/gestionnaire peuvent voir
 * n'importe laquelle via le tableau de bord, pas ici — voir matrice §4).
 *
 * confirmerScan() est la cible du QR code au verso des étiquettes de
 * colis (voir QrCodeService/PackagingController) : vit dans CE groupe de
 * routes authentifiées (role:benevole) — scanner sans être connecté
 * redirige vers la connexion, jamais un accès public direct (corrige le
 * défaut de l'ancien système, voir le prompt §3.4).
 */
class MaRouteController extends Controller
{
    public function __construct(private readonly MaRouteVueService $vue) {}

    public function show(): View
    {
        // 'charge' : la tournée reste visible entre la confirmation de
        // chargement et le clic du chauffeur sur « Je commence ma tournée »
        // (voir demarrer()) — depuis le 30/09/2026 ce clic est la SEULE
        // façon de passer 'charge' → 'en_cours'.
        $routes = RouteLivraison::where('id_benevole', auth()->id())
            ->whereIn('statut', ['planifiee', 'chargement', 'charge', 'en_cours', 'livraisons_terminees'])
            ->orderByDesc('created_at')
            ->get();

        return view('livraison.ma-route', $this->donneesVue($routes, false));
    }

    /**
     * Vue admin/gestionnaire d'UNE tournée, strictement identique à l'écran
     * du chauffeur (29/09/2026, prompt de cette date §6.2 : "same view as
     * the driver's ma-route") — même vue Blade, mêmes actions. Accessible
     * quel que soit le statut de la tournée (y compris annulée/terminée).
     *
     * Les actions POST de ce contrôleur restent celles du chauffeur
     * (livraison.benevole.*) : elles acceptent aussi un gestionnaire/admin
     * (voir peutAgirSur()).
     */
    public function voirCommeChauffeur(RouteLivraison $route): View
    {
        $route->load('benevole:id,nom,prenom');

        return view('livraison.ma-route', $this->donneesVue(collect([$route]), true));
    }

    /**
     * Feuille de tournée imprimable pour un chauffeur peu à l'aise avec l'application (octobre
     * 2026) : tous les arrêts dans l'ordre, avec adresse complète, téléphones, nombre de colis et
     * besoins particuliers — de quoi livrer sans écran. Réservée au staff (même groupe de routes que
     * voirCommeChauffeur()) : c'est l'admin/gestionnaire qui valide ensuite la tournée à la main,
     * d'où ni QR ni statut sur cette feuille, seulement des cases à cocher pour le chauffeur.
     * Les arrêts annulés (tournée abandonnée) n'y figurent pas.
     */
    public function imprimer(RouteLivraison $route): View
    {
        $route->load(['benevole', 'vehiculeType', 'campagne', 'campagneJournee']);

        /** @var \Illuminate\Database\Eloquent\Collection<int, EtapeRoute> $etapes */
        $etapes = $route->etapes()
            ->whereNotNull('id_livraison')
            ->where('statut', '!=', 'annulee')
            ->with([
                'livraison' => fn($requete) => $requete->withCount('colis'),
                'livraison.famille.quartier.secteur.ville',
            ])
            ->orderBy('ordre')
            ->get();

        $totalColis = 0;
        $poidsTotal = 0.0;
        foreach ($etapes as $etape) {
            /** @var Livraison|null $livraison La relation est typée Model par l'analyse statique. */
            $livraison = $etape->livraison;
            if ($livraison) {
                $totalColis += $livraison->nombre_colis;
                $poidsTotal += (float) $livraison->poids_kg;
            }
        }

        return view('livraison.route-impression', [
            'route' => $route,
            'etapes' => $etapes,
            'totalColis' => $totalColis,
            'poidsTotal' => round($poidsTotal, 1),
        ]);
    }

    /**
     * @param Collection<int, RouteLivraison> $routes
     * @return array<string, mixed>
     */
    private function donneesVue($routes, bool $modeAdmin): array
    {
        $vues = [];
        foreach ($routes as $route) {
            $vues[$route->id] = $this->vue->preparer($route);
        }

        return ['routes' => $routes, 'vues' => $vues, 'modeAdmin' => $modeAdmin];
    }

    /**
     * « Je commence ma tournée » (30/09/2026) : seule une tournée 'charge'
     * (chargement confirmé par l'équipe chargement) peut démarrer. Passe la
     * tournée, tous ses arrêts ouverts et leurs livraisons à 'en_cours' —
     * ce que suivi-livraison affiche aussitôt.
     */
    public function demarrer(RouteLivraison $route): JsonResponse
    {
        if (!$this->peutAgirSur($route)) {
            throw ValidationException::withMessages(['route' => "Cette tournée n'est pas la vôtre."]);
        }

        if ($route->statut !== 'charge') {
            throw ValidationException::withMessages(['route' => "Le chargement n'est pas terminé : la tournée ne peut pas démarrer."]);
        }

        $route->demarrer();

        return response()->json(['success' => true]);
    }

    /**
     * Rouvre une tournée terminée (11/10/2026, admin uniquement — voir routes/livraison.php) : le
     * « retour au QG » a figé la tournée (statut 'terminee'), plus aucun arrêt modifiable. Elle
     * repasse à 'livraisons_terminees' — arrêts inchangés, tous de nouveau modifiables ; un arrêt
     * remis « en cours » la ramène à 'en_cours' (RouteLivraison::rouvrirEtape()). Le chauffeur
     * n'est plus « de retour au QG » : sa ligne BenevoleRetourQg est supprimée. Le mail
     * « nouvelle tournée » déjà parti n'est pas rappelé. Refusé si la campagne est terminée.
     */
    public function rouvrir(RouteLivraison $route): JsonResponse
    {
        if ($route->statut !== 'terminee') {
            return response()->json(['success' => false, 'message' => "Cette tournée n'est pas terminée."], 422);
        }

        if (Campagne::findOrFail($route->id_campagne)->statut === 'terminee') {
            return response()->json([
                'success' => false,
                'message' => 'La campagne est terminée : rouvrez-la avant de rouvrir une tournée.',
            ], 422);
        }

        DB::transaction(function () use ($route) {
            $route->update(['statut' => 'livraisons_terminees', 'retour_qg' => null, 'retour_qg_le' => null]);
            BenevoleRetourQg::where('id_route_origine', $route->id)->delete();
        });

        audit('update', 'routes_livraison', $route->id, ['statut' => 'terminee'], ['statut' => 'livraisons_terminees']);

        return response()->json(['success' => true]);
    }

    /**
     * Polling de l'écran (30/09/2026) : renvoie l'empreinte courante ; la
     * page se recharge quand elle diffère de celle rendue au chargement
     * (démarrage, arrêt livré/ignoré/rouvert par l'admin, etc.).
     */
    public function etat(RouteLivraison $route): JsonResponse
    {
        if (!$this->peutAgirSur($route)) {
            abort(403);
        }

        return response()->json(['signature' => $this->vue->preparer($route)['signature']]);
    }

    /**
     * Remet un arrêt ignoré à 'en_cours' — la famille est finalement
     * disponible (30/09/2026). Chauffeur propriétaire ou admin/gestionnaire.
     */
    public function remettreEnCours(EtapeRoute $etape): JsonResponse
    {
        $this->assertProprietaire($etape);

        if ($etape->statut !== 'ignoree') {
            throw ValidationException::withMessages(['etape' => 'Seul un arrêt ignoré peut être remis en cours.']);
        }

        if (!in_array($etape->route->statut, ['en_cours', 'livraisons_terminees'], true)) {
            throw ValidationException::withMessages(['etape' => "Cette tournée n'est plus modifiable."]);
        }

        $etape->route->rouvrirEtape($etape);

        return response()->json(['success' => true]);
    }

    /**
     * Annule un « Livré » cliqué par erreur (09/10/2026) : l'arrêt et sa
     * livraison repassent à 'en_cours' (même retour que remettreEnCours() pour
     * un arrêt ignoré) et une tournée déjà 'livraisons_terminees' se rouvre.
     * Aucune notification à la famille. Chauffeur propriétaire ou admin/gestionnaire.
     */
    public function annulerLivraison(EtapeRoute $etape): JsonResponse
    {
        $this->assertProprietaire($etape);

        if ($etape->statut !== 'livree') {
            throw ValidationException::withMessages(['etape' => 'Seul un arrêt livré peut être annulé.']);
        }

        $route = RouteLivraison::findOrFail($etape->id_route);

        if (!in_array($route->statut, ['en_cours', 'livraisons_terminees'], true)) {
            throw ValidationException::withMessages(['etape' => "Cette tournée n'est plus modifiable."]);
        }

        $route->rouvrirEtape($etape);

        return response()->json(['success' => true]);
    }

    /**
     * Toute action de terrain (livré / ignoré / scan) exige une tournée
     * démarrée (30/09/2026) : le clic sur « Je commence ma tournée » n'est
     * plus implicite.
     */
    private function assertTourneeDemarree(EtapeRoute $etape): void
    {
        if ($etape->route->statut !== 'en_cours') {
            throw ValidationException::withMessages(['etape' => "Démarrez d'abord la tournée (« Je commence ma tournée »)."]);
        }
    }

    /**
     * Confirmation manuelle d'un arrêt — depuis l'écran, pas le QR (voir
     * confirmerScan() pour le canal de secours).
     */
    public function confirmerEtape(EtapeRoute $etape): JsonResponse
    {
        $this->assertProprietaire($etape);
        $this->assertTourneeDemarree($etape);

        $etape->update(['statut' => 'livree']);
        $etape->livraison->update(['statut' => 'livree']);

        return response()->json(['success' => true, 'tout_traite' => $etape->route->toutesEtapesTraitees()]);
    }

    /**
     * Fallback QR — même effet que confirmerEtape(), déclenché par le
     * scan plutôt qu'un tap sur l'écran (voir le prompt §3.4 : "used only
     * as a fallback delivery-confirmation channel").
     */
    public function confirmerScan(EtapeRoute $etape): View
    {
        $this->assertProprietaire($etape);

        // Tournée pas encore démarrée : rien n'est confirmé, la page de
        // scan invite à démarrer d'abord (30/09/2026).
        if ($etape->route->statut !== 'en_cours' && $etape->statut !== 'livree') {
            return view('livraison.scan-confirme', ['etape' => $etape, 'demarre' => false]);
        }

        if ($etape->statut !== 'livree') {
            $etape->update(['statut' => 'livree']);
            $etape->livraison->update(['statut' => 'livree']);
        }

        return view('livraison.scan-confirme', ['etape' => $etape, 'demarre' => true]);
    }

    /**
     * Signalement "livraison ignorée" — seul type d'incident qu'un
     * bénévole peut lever lui-même, et seulement en cours de route (voir
     * matrice §4 : "Raise (livraison_ignoree only, en route)").
     */
    public function signalerIgnoree(Request $request, EtapeRoute $etape): JsonResponse
    {
        $this->assertProprietaire($etape);
        $this->assertTourneeDemarree($etape);

        $etape->update(['statut' => 'ignoree']);
        $etape->livraison->update(['statut' => 'ignoree']);

        RouteIncident::create([
            'id_route' => $etape->id_route,
            'type' => 'livraison_ignoree',
            'id_livraison' => $etape->id_livraison,
            'signale_par' => auth()->id(),
            'statut' => 'ouvert',
            'notes' => $request->input('notes'),
        ]);

        return response()->json(['success' => true, 'tout_traite' => $etape->route->toutesEtapesTraitees()]);
    }

    /**
     * "Livraison terminé" (voir le prompt du 03/09/2026) : premier des
     * deux boutons de fin de tournée, activé côté vue une fois
     * `toutesEtapesTraitees()` vrai. Passe la tournée à
     * 'livraisons_terminees' — état visible admin/gestionnaire même si le
     * bénévole ne tape jamais "Retour QG" ensuite (objectif explicite du
     * prompt : "This way if they do not return they have a way to notify
     * that route is done").
     */
    public function livraisonTerminee(RouteLivraison $route): JsonResponse
    {
        if (!$this->peutAgirSur($route)) {
            throw ValidationException::withMessages(['route' => "Cette tournée n'est pas la vôtre."]);
        }

        if (!$route->toutesEtapesTraitees()) {
            throw ValidationException::withMessages(['route' => 'Tous les arrêts ne sont pas encore livrés ou ignorés.']);
        }

        $route->update(['statut' => 'livraisons_terminees']);

        return response()->json(['success' => true]);
    }

    /**
     * "Retour QG" — second bouton, activé seulement après
     * livraisonTerminee() (voir ma-route.blade.php : grisé tant que
     * statut !== 'livraisons_terminees'). Clôt la tournée et enregistre
     * le bénévole comme disponible pour le prochain lot de tournées (voir
     * BenevoleRetourQg et RouteGenerationService) — remplace l'ancien
     * signal "demande de nouvelle tournée" (notification email sans état
     * persisté) par un état que le tableau de bord peut effectivement
     * interroger, plutôt qu'un email à repérer manuellement.
     */
    public function retourQg(Request $request, RouteLivraison $route, RetourQgService $retourQg): JsonResponse
    {
        if (!$this->peutAgirSur($route)) {
            throw ValidationException::withMessages(['route' => "Cette tournée n'est pas la vôtre."]);
        }

        // 18/10/2026 : `retour` = false pour le « Non » (jusqu'ici rien n'était envoyé) ; absent = « Oui »,
        // compatible avec les clients qui n'envoient pas le champ.
        $retour = $request->boolean('retour', true);

        try {
            $retourQg->enregistrer($route, $retour, auth()->id());
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['route' => $e->getMessage()]);
        }

        return response()->json(['success' => true, 'retour' => $retour]);
    }

    /**
     * « Remettre dans le pool » (18/10/2026, gestionnaire/admin — voir routes/livraison.php) : un chauffeur ayant
     * répondu « Non » est de nouveau disponible pour les prochains lots de tournées.
     */
    public function remettreDansLePool(RouteLivraison $route, RetourQgService $retourQg): JsonResponse
    {
        try {
            $retourQg->remettreDansLePool($route);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Propriétaire de la tournée OU admin/gestionnaire (29/09/2026, prompt
     * de cette date §6.2 : l'admin doit pouvoir tout faire comme le
     * chauffeur). Un chauffeur ne peut toujours agir que sur SA tournée.
     */
    private function peutAgirSur(RouteLivraison $route): bool
    {
        return $route->id_benevole === auth()->id()
            || (bool) auth()->user()?->hasAtLeastRole('gestionnaire');
    }

    private function assertProprietaire(EtapeRoute $etape): void
    {
        if (!$this->peutAgirSur($etape->route)) {
            throw ValidationException::withMessages(['etape' => "Cet arrêt n'appartient pas à votre tournée."]);
        }
    }
}
