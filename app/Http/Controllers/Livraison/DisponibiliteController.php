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
 * — compte propre, éditable à tout moment après la confirmation initiale
 * (pas de flux "renvoyer le formulaire") — voir le prompt du 30/08/2026
 * §3.2/§7.
 *
 * Rescopée par journée le 05/09/2026 : la page reste routée au niveau
 * Campagne (le lien email envoyé une fois par campagne ne change pas,
 * voir CampagneDisponibiliteNotification) mais affiche désormais UN bloc
 * de confirmation PAR CampagneJournee — un bénévole peut être disponible
 * une journée et pas l'autre. Pour une campagne mono-jour (le cas
 * courant), une seule CampagneJournee existe (voir
 * CampagnesController::store()) donc l'écran reste équivalent à avant
 * cette évolution.
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

        $journees = $campagne->journees;

        $disponibilites = BenevoleDisponibilite::with('creneaux')
            ->where('id_personne', auth()->id())
            ->whereIn('id_campagne_journee', $journees->pluck('id'))
            ->get()
            ->keyBy('id_campagne_journee');

        return view('livraison.disponibilite', [
            'campagne' => $campagne,
            'profil' => $profil,
            'journees' => $journees,
            'disponibilites' => $disponibilites,
            'creneaux' => Creneau::LIBELLES,
        ]);
    }

    public function update(Request $request, Campagne $campagne): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            // id_campagne_journee : identifie QUELLE journée de la
            // campagne ce bloc de confirmation concerne (voir la vue,
            // un formulaire par journée) — doit appartenir à $campagne,
            // vérifié ci-dessous via $campagne->journees()->findOrFail().
            'id_campagne_journee' => 'required|integer',
            'vehicule_confirme' => 'required|boolean',
            'coverage_confirmee' => 'required|boolean',
            'coverage_notes' => 'nullable|string|max:1000',
            'creneaux' => 'required|array|min:1',
            'creneaux.*' => 'in:' . implode(',', Creneau::TOUS),
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $journee = $campagne->journees()->findOrFail($request->integer('id_campagne_journee'));

        $disponibilite = $this->disponibiliteService->confirmer(
            auth()->id(),
            $journee,
            $validator->validated(),
            $request->input('creneaux'),
        );

        return response()->json(['success' => true, 'disponibilite' => $disponibilite]);
    }
}
