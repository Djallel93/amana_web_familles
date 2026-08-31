<?php
// app/Http/Controllers/Livraison/DisponibiliteController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use Amana\Shared\Models\BenevoleProfil;
use App\Http\Controllers\Controller;
use App\Models\BenevoleDisponibilite;
use App\Models\Campagne;
use App\Services\BenevoleDisponibiliteService;
use App\Support\Creneau;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Page de disponibilité bénévole (véhicule, coverage/secteurs, créneaux)
 * — compte propre, scopée à une campagne, éditable à tout moment après la
 * confirmation initiale (pas de flux "renvoyer le formulaire") — voir le
 * prompt du 30/08/2026 §3.2/§7.
 */
class DisponibiliteController extends Controller
{
    public function __construct(
        private readonly BenevoleDisponibiliteService $disponibiliteService,
    ) {
    }

    public function show(Campagne $campagne): View
    {
        $profil = BenevoleProfil::where('id_personne', auth()->id())->first();

        $disponibilite = BenevoleDisponibilite::with('creneaux')
            ->where('id_personne', auth()->id())
            ->where('id_campagne', $campagne->id)
            ->first();

        return view('livraison.disponibilite', [
            'campagne' => $campagne,
            'profil' => $profil,
            'disponibilite' => $disponibilite,
            'creneauxSelectionnes' => $disponibilite ? $disponibilite->creneaux->pluck('creneau')->all() : [],
            'creneaux' => Creneau::LIBELLES,
        ]);
    }

    public function update(Request $request, Campagne $campagne): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'vehicule_confirme' => 'required|boolean',
            'coverage_confirmee' => 'required|boolean',
            'coverage_notes' => 'nullable|string|max:1000',
            'creneaux' => 'required|array|min:1',
            'creneaux.*' => 'in:' . implode(',', Creneau::TOUS),
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $disponibilite = $this->disponibiliteService->confirmer(
            auth()->id(),
            $campagne,
            $validator->validated(),
            $request->input('creneaux'),
        );

        return response()->json(['success' => true, 'disponibilite' => $disponibilite]);
    }
}
