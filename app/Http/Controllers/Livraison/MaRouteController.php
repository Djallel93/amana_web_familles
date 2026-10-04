<?php
// app/Http/Controllers/Livraison/MaRouteController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use Amana\Shared\Models\Personne;
use App\Http\Controllers\Controller;
use App\Models\BenevoleRetourQg;
use App\Models\EtapeRoute;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Notifications\DemandeNouvelleTourneeNotification;
use App\Services\MaRouteVueService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
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
    public function retourQg(RouteLivraison $route): JsonResponse
    {
        if (!$this->peutAgirSur($route)) {
            throw ValidationException::withMessages(['route' => "Cette tournée n'est pas la vôtre."]);
        }

        if ($route->statut !== 'livraisons_terminees') {
            throw ValidationException::withMessages(['route' => "La livraison n'est pas encore marquée terminée."]);
        }

        $route->update(['statut' => 'terminee']);

        BenevoleRetourQg::create([
            'id_campagne' => $route->id_campagne,
            // Le chauffeur de la tournée, pas auth()->id() : un admin qui
            // clôt la tournée à sa place (voirCommeChauffeur()) ne devient
            // pas "disponible pour le prochain lot".
            'id_personne' => $route->id_benevole,
            'id_route_origine' => $route->id,
            'disponible_depuis' => now(),
        ]);

        $destinataires = Personne::adminsDe()->orWhere(
            fn($q) => $q->avecRole('gestionnaire'),
        )->get();

        Notification::send($destinataires, new DemandeNouvelleTourneeNotification($route));

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
