<?php
// app/Http/Controllers/Livraison/ScanLivraisonController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use App\Models\Livraison;
use Illuminate\Http\RedirectResponse;

/**
 * Cible UNIQUE des QR codes imprimés sur les étiquettes de colis (octobre 2026, voir
 * ChargementController::etiquettesCampagne()). Le QR identifie la FAMILLE (sa livraison), pas un
 * arrêt de tournée : l'arrêt est résolu au moment du scan, si bien qu'une étiquette imprimée reste
 * valable quand les tournées sont recomposées (RouteMutationService recrée des arrêts) et qu'elle
 * peut être imprimée avant la génération des routes.
 *
 *  - famille se_deplace → écran de retrait au QG (livraison.retrait-hq.scan) ;
 *  - sinon → confirmation de l'arrêt de la tournée courante (livraison.benevole.etapes.scan).
 *
 * Aucune autorisation ici : l'utilisateur doit être connecté (middleware auth) et chaque cible
 * applique la sienne (can:gererRetraitHq / role:benevole + propriété de l'arrêt).
 */
class ScanLivraisonController extends Controller
{
    public function __invoke(Livraison $livraison): RedirectResponse
    {
        if ($livraison->se_deplace) {
            return redirect()->route('livraison.retrait-hq.scan', $livraison);
        }

        // Tournée courante : la plus récente non annulée (une tournée abandonnée — bénévole absent —
        // laisse des arrêts « annulee » derrière elle).
        $etape = $livraison->etapesRoute()
            ->whereHas('route', fn($requete) => $requete->where('statut', '!=', 'annulee'))
            ->orderByDesc('id')
            ->first();

        abort_if($etape === null, 404, "Cette famille n'est dans aucune tournée pour le moment.");

        return redirect()->route('livraison.benevole.etapes.scan', $etape);
    }
}
