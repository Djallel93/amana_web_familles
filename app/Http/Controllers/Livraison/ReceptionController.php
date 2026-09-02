<?php
// app/Http/Controllers/Livraison/ReceptionController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use App\Models\Campagne;
use App\Models\CampagneArrivee;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Poste de comptage des donateurs (parking) — équipe_reception.
 * AUCUNE donnée famille visible depuis cet écran (voir le prompt du
 * 30/08/2026 §4) : écrit uniquement dans campagne_arrivees
 * (nombre_donateur par tape, voir ce modèle pour le raisonnement complet
 * — notamment pourquoi ce n'est pas un simple +1 fixe).
 */
class ReceptionController extends Controller
{
    public function show(Campagne $campagne): View
    {
        return view('livraison.reception', ['campagne' => $campagne]);
    }

    public function enregistrer(Request $request, Campagne $campagne): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre_donateur' => 'required|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        CampagneArrivee::create([
            'id_campagne' => $campagne->id,
            'nombre_donateur' => $request->input('nombre_donateur'),
            'horodatage' => now(),
            'logge_par' => auth()->id(),
        ]);

        return response()->json(['success' => true, 'total_campagne' => $campagne->fresh()->nombre_menages]);
    }
}
