<?php
// app/Http/Controllers/Livraison/MaRouteController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
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
            ->whereIn('statut', ['planifiee', 'chargement', 'en_cours'])
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
     * "Demande de nouvelle tournée" (voir le prompt §7) : le bénévole a
     * terminé sa tournée en cours et signale qu'il est prêt à repartir
     * (pertinent notamment pour zakat_el_fitr, où les tournées sont
     * générées progressivement par lots — voir §1). Pas de nouvelle
     * colonne dédiée pour ce signal (le prompt ne le demande pas
     * explicitement comme un champ de suivi) : simple notification
     * directe à l'admin/gestionnaire, rien de persisté — à revoir si un
     * suivi structuré s'avère nécessaire à l'usage.
     */
    public function demanderNouvelleTournee(RouteLivraison $route): JsonResponse
    {
        if ($route->id_benevole !== auth()->id()) {
            throw ValidationException::withMessages(['route' => "Cette tournée n'est pas la vôtre."]);
        }

        Notification::route('mail', config('mail.admin_notifications_address', config('mail.from.address')))
            ->notify(new \App\Notifications\DemandeNouvelleTourneeNotification($route));

        return response()->json(['success' => true]);
    }

    private function assertProprietaire(EtapeRoute $etape): void
    {
        if ($etape->route->id_benevole !== auth()->id()) {
            throw ValidationException::withMessages(['etape' => "Cet arrêt n'appartient pas à votre tournée."]);
        }
    }
}
