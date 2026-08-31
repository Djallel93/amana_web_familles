<?php
// app/Http/Controllers/Livraison/MaRouteController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Écran "Ma tournée" du bénévole (carte, arrêts ordonnés, coordonnées,
 * statut par arrêt, scan QR de secours, demande de nouvelle tournée) —
 * voir le prompt du 30/08/2026 §7. Accès restreint à SA PROPRE tournée
 * (admin/gestionnaire peuvent voir n'importe laquelle, voir matrice §4) —
 * vérification d'appartenance à écrire en Patch 3, en même temps que la
 * logique de confirmation de livraison.
 *
 * SQUELETTE (Patch 1).
 */
class MaRouteController extends Controller
{
    public function show(): View
    {
        return view('livraison.a-venir', ['titre' => 'Ma tournée']);
    }
}
