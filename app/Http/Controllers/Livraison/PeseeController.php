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
 *
 * La pesée reste une étape FACULTATIVE par campagne (prompt du 05/09/2026
 * §3) : aucune validation ni ici ni ailleurs (CampagneProgressBar.vue)
 * n'exige qu'au moins un relevé existe pour qu'une campagne avance —
 * simplement un poste que certaines campagnes n'utilisent pas.
 */
class PeseeController extends Controller
{
    /**
     * Point d'entrée sans campagne — voir le prompt §4.1 (même
     * raisonnement que ReceptionController::choisir()) : equipe_pesee
     * n'avait aucune entrée de menu vers cet écran.
     */
    public function choisir(): View
    {
        $campagnes = Campagne::whereIn('statut', ['preparation', 'en_cours'])
            ->with('journees')
            ->orderByDesc('date_livraison')
            ->get();

        return view('livraison.choisir-poste', [
            'campagnes' => $campagnes,
            'titre' => 'Pesée — choisir une campagne',
            'routeIndex' => 'livraison.pesee.show',
            'avecJournee' => true,
        ]);
    }

    public function show(Campagne $campagne): View
    {
        return view('livraison.pesee', [
            'campagne' => $campagne->load('journees'),
            'autresCampagnes' => Campagne::whereIn('statut', ['preparation', 'en_cours'])->orderByDesc('date_livraison')->get(),
            // Un compte gestionnaire/admin (le plus courant en pratique,
            // voir EnsureLivraisonRole) a accès à campagnes.show — y
            // renvoyer directement plutôt qu'à choisir() (05/09/2026,
            // correction : "I expect to go back to /livraison/campagnes/{id}").
            // Un compte equipe_pesee PUR n'a lui accès qu'à choisir().
            'urlRetour' => (auth()->user()->isAdmin() || auth()->user()->isGestionnaire())
                ? route('livraison.campagnes.show', $campagne)
                : route('livraison.pesee.choisir'),
        ]);
    }

    /**
     * id_campagne_journee ajouté le 05/09/2026 (prompt §3.3) — sélecteur
     * en haut de l'écran, nullable ici aussi côté validation : une
     * campagne mono-journée n'affiche pas le sélecteur côté Vue et
     * n'envoie donc rien.
     */
    public function enregistrer(Request $request, Campagne $campagne): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'poids_kg' => 'required|numeric|min:0.1|max:2000',
            'id_campagne_journee' => 'nullable|integer|exists:campagne_journees,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $don = Donation::create([
            'id_campagne' => $campagne->id,
            'id_campagne_journee' => $request->input('id_campagne_journee'),
            'poids_kg' => $request->input('poids_kg'),
            'horodatage' => now(),
            'logge_par' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'don' => $don->load('loggePar:id,nom,prenom'),
            'total_campagne' => $campagne->fresh()->poids_collecte_kg,
        ]);
    }

    /**
     * Journal des relevés — voir le prompt §3.2 : "a table with a row for
     * each input". Filtrable par journée comme enregistrer() ; sans
     * filtre, montre tout l'historique de la campagne.
     */
    public function journal(Request $request, Campagne $campagne): JsonResponse
    {
        $query = Donation::where('id_campagne', $campagne->id);
        if ($request->filled('id_campagne_journee')) {
            $query->where('id_campagne_journee', $request->input('id_campagne_journee'));
        }

        $dons = $query->with('loggePar:id,nom,prenom')->orderByDesc('horodatage')->get();

        return response()->json(['dons' => $dons, 'total_kg' => (float) $query->sum('poids_kg')]);
    }

    /**
     * Édition/suppression d'une ligne — pas de restriction de propriété
     * (n'importe quel membre de equipe_pesee peut modifier une ligne
     * saisie par quelqu'un d'autre) : décision explicite du 05/09/2026,
     * ce poste n'a jamais eu de notion de "propriétaire" d'une saisie.
     */
    public function modifier(Request $request, Donation $don): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'poids_kg' => 'required|numeric|min:0.1|max:2000',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $don->update(['poids_kg' => $request->input('poids_kg')]);

        return response()->json(['success' => true, 'don' => $don->fresh()->load('loggePar:id,nom,prenom')]);
    }

    public function supprimer(Donation $don): JsonResponse
    {
        $don->delete();

        return response()->json(['success' => true]);
    }
}
