<?php
// app/Http/Controllers/Livraison/PeseeController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use App\Models\Campagne;
use App\Models\Donation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Poste de pesée (entrée QG) — équipe_pesee. AUCUNE donnée famille
 * visible depuis cet écran (voir le prompt du 30/08/2026 §4) : écrit
 * uniquement dans donations (poids total unique par relevé, jamais de
 * ventilation par catégorie — voir ce modèle).
 */
class PeseeController extends Controller
{
    public function show(Campagne $campagne): View
    {
        return view('livraison.pesee', ['campagne' => $campagne]);
    }

    public function enregistrer(Request $request, Campagne $campagne): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'poids_kg' => 'required|numeric|min:0.1|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        Donation::create([
            'id_campagne' => $campagne->id,
            'poids_kg' => $request->input('poids_kg'),
            'horodatage' => now(),
            'logge_par' => auth()->id(),
        ]);

        return response()->json(['success' => true, 'total_campagne' => $campagne->fresh()->poids_collecte_kg]);
    }
}
