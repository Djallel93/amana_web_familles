<?php
// app/Http/Controllers/Livraison/PeseeController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Poste de pesée (entrée QG) — équipe_pesee. AUCUNE donnée famille
 * visible depuis cet écran (voir le prompt du 30/08/2026 §4) : écrit
 * uniquement dans donations (poids total unique par relevé, jamais de
 * ventilation par catégorie — voir ce modèle).
 *
 * SQUELETTE (Patch 1) — écriture du journal à écrire en Patch 4.
 */
class PeseeController extends Controller
{
    public function show(): View
    {
        return view('livraison.a-venir', ['titre' => 'Pesée des dons']);
    }
}
