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
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function show(): View
    {
        $routes = RouteLivraison::where('id_benevole', auth()->id())
            ->whereIn('statut', ['planifiee', 'chargement', 'en_cours', 'livraisons_terminees'])
            ->with(['etapes.livraison.famille:id,nom,prenom,adresse,telephone'])
            ->orderByDesc('created_at')
            ->get();

        return view('livraison.ma-route', ['routes' => $routes]);
    }

    /**
     * Confirmation manuelle d'un arrêt — depuis l'écran, pas le QR (voir
     * confirmerScan() pour le canal de secours).
     */
    public function confirmerEtape(EtapeRoute $etape): JsonResponse
    {
        $this->assertProprietaire($etape);

        $etape->update(['statut' => 'livree']);
        $etape->livraison->update(['statut' => 'livree']);

        return response()->json(['success' => true]);
    }

    /**
     * Fallback QR — même effet que confirmerEtape(), déclenché par le
     * scan plutôt qu'un tap sur l'écran (voir le prompt §3.4 : "used only
     * as a fallback delivery-confirmation channel").
     */
    public function confirmerScan(EtapeRoute $etape): View
    {
        $this->assertProprietaire($etape);

        if ($etape->statut !== 'livree') {
            $etape->update(['statut' => 'livree']);
            $etape->livraison->update(['statut' => 'livree']);
        }

        return view('livraison.scan-confirme', ['etape' => $etape]);
    }

    /**
     * Signalement "livraison ignorée" — seul type d'incident qu'un
     * bénévole peut lever lui-même, et seulement en cours de route (voir
     * matrice §4 : "Raise (livraison_ignoree only, en route)").
     */
    public function signalerIgnoree(Request $request, EtapeRoute $etape): JsonResponse
    {
        $this->assertProprietaire($etape);

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

        return response()->json(['success' => true]);
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
        if ($route->id_benevole !== auth()->id()) {
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
        if ($route->id_benevole !== auth()->id()) {
            throw ValidationException::withMessages(['route' => "Cette tournée n'est pas la vôtre."]);
        }

        if ($route->statut !== 'livraisons_terminees') {
            throw ValidationException::withMessages(['route' => "La livraison n'est pas encore marquée terminée."]);
        }

        $route->update(['statut' => 'terminee']);

        BenevoleRetourQg::create([
            'id_campagne' => $route->id_campagne,
            'id_personne' => auth()->id(),
            'id_route_origine' => $route->id,
            'disponible_depuis' => now(),
        ]);

        $destinataires = Personne::adminsDe()->orWhere(
            fn ($q) => $q->avecRole('gestionnaire'),
        )->get();

        Notification::send($destinataires, new \App\Notifications\DemandeNouvelleTourneeNotification($route));

        return response()->json(['success' => true]);
    }

    private function assertProprietaire(EtapeRoute $etape): void
    {
        if ($etape->route->id_benevole !== auth()->id()) {
            throw ValidationException::withMessages(['etape' => "Cet arrêt n'appartient pas à votre tournée."]);
        }
    }
}
