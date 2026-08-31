<?php
// app/Http/Controllers/Livraison/PackagingController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Écran packaging — équipe_packaging compose les colis à partir de la
 * file de priorité des livraisons en attente (headcount, etudiant/
 * est_hotel/nombre_enfant, note_besoins_speciaux en LECTURE SEULE), marque
 * chaque colis `statut_conditionnement = prete`, imprime étiquettes et
 * feuille de préparation — voir le prompt du 30/08/2026 §3.4/§4/§7.
 *
 * Découplé de l'assignation route/chauffeur PAR CONCEPTION (voir §3.4) :
 * cette file ne dépend jamais de l'état d'une tournée ou d'un bénévole.
 *
 * SQUELETTE (Patch 1) — file de priorité, impression (étiquette avec QR de
 * secours authentifié, feuille de préparation réimprimable) et bascule
 * automatique de la tournée en "prête à charger" à écrire en Patch 4.
 */
class PackagingController extends Controller
{
    public function index(): View
    {
        return view('livraison.a-venir', ['titre' => 'Packaging']);
    }
}
