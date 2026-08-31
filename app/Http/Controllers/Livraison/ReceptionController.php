<?php
// app/Http/Controllers/Livraison/ReceptionController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Poste de comptage des donateurs (parking) — équipe_reception.
 * AUCUNE donnée famille visible depuis cet écran (voir le prompt du
 * 30/08/2026 §4) : écrit uniquement dans campagne_arrivees
 * (nombre_donateur par tape, voir ce modèle pour le raisonnement complet).
 *
 * SQUELETTE (Patch 1) — écriture du journal à écrire en Patch 4.
 */
class ReceptionController extends Controller
{
    public function show(): View
    {
        return view('livraison.a-venir', ['titre' => 'Comptage des ménages']);
    }
}
