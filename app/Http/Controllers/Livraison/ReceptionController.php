<?php
// app/Http/Controllers/Livraison/ReceptionController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Livraison\Concerns\FiltreCampagnesEquipe;
use App\Models\Campagne;
use App\Models\CampagneArrivee;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Poste de réception (comptage des donateurs à l'accueil QG) —
 * équipe_reception. Même principe que PeseeController : un relevé
 * ponctuel (nombre de donateurs présents à un instant T), jamais une
 * liste nominative — voir campagne_arrivees.
 *
 * La réception reste une étape FACULTATIVE par campagne (prompt du
 * 05/09/2026 §4), même raisonnement que la pesée : aucune campagne n'est
 * bloquée si ce poste n'est pas utilisé.
 */
class ReceptionController extends Controller
{
    use FiltreCampagnesEquipe;

    /**
     * Point d'entrée sans campagne — voir le prompt §4.1 : equipe_reception
     * n'avait aucune entrée de menu vers cet écran (config/amana-shared.php
     * ne listait que les écrans gestionnaire) et les routes existantes
     * exigent un {campagne} que cette équipe n'avait aucun moyen de
     * choisir — d'où l'impression que l'écran "n'existait pas".
     *
     * Liste désormais restreinte aux campagnes où la personne a une
     * affectation campagne_equipe_membres (voir FiltreCampagnesEquipe et
     * le prompt du 08/09/2026) — plus toutes les campagnes actives comme
     * avant.
     */
    public function choisir(): View
    {
        $campagnes = $this->campagnesPourEquipe(Auth::user(), 'equipe_reception', avecJournees: true);

        return view('livraison.choisir-poste', [
            'campagnes' => $campagnes,
            'titre' => 'Réception — choisir une campagne',
            'routeIndex' => 'livraison.reception.show',
            'avecJournee' => true,
        ]);
    }

    public function show(Campagne $campagne): View
    {
        return view('livraison.reception', [
            'campagne' => $campagne->load('journees'),
            'autresCampagnes' => Campagne::whereIn('statut', ['preparation', 'en_cours'])->orderByDesc('date_livraison')->get(),
            'urlRetour' => (auth()->user()->isAdmin() || auth()->user()->isGestionnaire())
                ? route('livraison.campagnes.show', $campagne)
                : route('livraison.reception.choisir'),
        ]);
    }

    public function enregistrer(Request $request, Campagne $campagne): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre_donateur' => 'required|integer|min:1|max:5000',
            'id_campagne_journee' => 'nullable|integer|exists:campagne_journees,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $arrivee = CampagneArrivee::create([
            'id_campagne' => $campagne->id,
            'id_campagne_journee' => $request->input('id_campagne_journee'),
            'nombre_donateur' => $request->input('nombre_donateur'),
            'horodatage' => now(),
            'logge_par' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'arrivee' => $arrivee->load('loggePar:id,nom,prenom'),
            'total_campagne' => $campagne->fresh()->nombre_menages,
        ]);
    }

    public function journal(Request $request, Campagne $campagne): JsonResponse
    {
        $query = CampagneArrivee::where('id_campagne', $campagne->id);
        if ($request->filled('id_campagne_journee')) {
            $query->where('id_campagne_journee', $request->input('id_campagne_journee'));
        }

        $arrivees = $query->with('loggePar:id,nom,prenom')->orderByDesc('horodatage')->get();

        return response()->json(['arrivees' => $arrivees, 'total_donateurs' => (int) $query->sum('nombre_donateur')]);
    }

    public function modifier(Request $request, CampagneArrivee $arrivee): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre_donateur' => 'required|integer|min:1|max:5000',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $arrivee->update(['nombre_donateur' => $request->input('nombre_donateur')]);

        return response()->json(['success' => true, 'arrivee' => $arrivee->fresh()->load('loggePar:id,nom,prenom')]);
    }

    public function supprimer(CampagneArrivee $arrivee): JsonResponse
    {
        $arrivee->delete();

        return response()->json(['success' => true]);
    }
}
