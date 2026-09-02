<?php
// app/Http/Controllers/Livraison/PackagingController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use App\Models\Campagne;
use App\Models\Livraison;
use App\Services\QrCodeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

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
    public function index(Campagne $campagne): View
    {
        $livraisons = Livraison::where('id_campagne', $campagne->id)
            ->where('statut_conditionnement', 'en_attente')
            ->whereNotIn('statut', ['ignoree', 'livree'])
            ->with('famille:id,nom,prenom,criticite,etudiant,est_hotel,nombre_enfant')
            ->get()
            ->sortByDesc(fn (Livraison $l) => $l->famille->criticite ?? 0)
            ->values();

        return view('livraison.packaging', ['campagne' => $campagne, 'livraisons' => $livraisons]);
    }

    /**
     * Marque une livraison comme conditionnée — bascule automatiquement
     * sa tournée en "chargement" si TOUTES ses livraisons sont
     * désormais prêtes (voir le prompt §3.4). Aucun effet sur
     * l'assignation route/bénévole.
     */
    public function marquerPret(Livraison $livraison): JsonResponse
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
            }
        }

        return response()->json(['success' => true]);
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
            ->with('famille:id,nom,prenom,criticite,etudiant,est_hotel,nombre_enfant')
            ->get()
            ->sortByDesc(fn (Livraison $l) => $l->famille->criticite ?? 0)
            ->values();

        return view('livraison.feuille-preparation', ['campagne' => $campagne, 'livraisons' => $livraisons]);
    }

    /**
     * Étiquettes imprimables — une par colis (une par personne du foyer,
     * voir le prompt §2 : "each person gets one package"). Recto : famille
     * + "colis X/N". Verso : QR de secours vers la confirmation
     * authentifiée du bénévole (voir QrCodeService/MaRouteController).
     *
     * Le packaging étant découplé de l'assignation (voir docblock de
     * classe), une livraison peut être conditionnée AVANT d'avoir une
     * tournée — dans ce cas, pas d'étape à laquelle rattacher le QR de
     * secours : l'écran l'indique clairement plutôt que de générer un
     * lien qui échouerait au scan (id d'étape inexistant). L'équipe
     * réimprime l'étiquette une fois la tournée générée (réimpression
     * déjà supportée par design, voir feuillePreparation()).
     */
    public function etiquettes(Livraison $livraison): View
    {
        $etape = $livraison->etapesRoute()->first();

        return view('livraison.etiquettes', [
            'livraison' => $livraison,
            'qrSvg' => $etape ? $this->qrCode->genererSvg(route('livraison.benevole.etapes.scan', $etape)) : null,
        ]);
    }
}
