<?php
// app/Http/Controllers/Admin/Livraison/ContactTrackingController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use Amana\Shared\Models\Personne;
use App\Http\Controllers\Controller;
use App\Models\Livraison;
use App\Support\Creneau;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Tableau de suivi des contacts téléphoniques (file d'appels, filtre par
 * gestionnaire assigné, statut_contact, vue "assigné à moi") — voir le
 * prompt du 30/08/2026 §7. Assignation de contact ("Assigner un contact
 * famille") réservée gestionnaire/admin, avec validation que la personne
 * assignée détient bien le rôle gestionnaire (§2 : id_personne_assignee
 * "Must validate that the assigned person holds the gestionnaire role
 * (or admin, via existing cascade) — reject assignment otherwise").
 *
 * contacterManuel() couvre le second canal de confirmation du prompt §3.1
 * ("Family has no email → phone contact by staff... Staff enters the
 * same fields directly on the campaign tracking screen") — mêmes champs
 * et mêmes règles de validation que ContactConfirmationController::store()
 * (formulaire public), staff-only ici.
 */
class ContactTrackingController extends Controller
{
    public function index(): View
    {
        return view('livraison.a-venir', ['titre' => 'Suivi des contacts']);
    }

    /**
     * File des livraisons pas encore confirmées, filtrable par
     * gestionnaire assigné — `mine=1` couvre la vue "assigné à moi" du
     * prompt §7.
     */
    public function queue(Request $request): JsonResponse
    {
        $query = Livraison::with(['famille:id,nom,prenom,telephone,email', 'personneAssignee', 'campagne'])
            ->where('statut_contact', '!=', 'confirme');

        if ($request->boolean('mine')) {
            $query->where('id_personne_assignee', auth()->id());
        } elseif ($request->filled('id_personne_assignee')) {
            $query->where('id_personne_assignee', $request->input('id_personne_assignee'));
        }

        if ($request->filled('id_campagne')) {
            $query->where('id_campagne', $request->input('id_campagne'));
        }

        return response()->json($query->paginate(50));
    }

    /**
     * Assigne (ou réassigne) une livraison à un gestionnaire pour le
     * contact téléphonique — rejette si la personne visée n'a pas le rôle
     * gestionnaire (ou admin, cascade existante), voir le prompt §2.
     */
    public function assigner(Request $request, Livraison $livraison): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_personne_assignee' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $personne = Personne::find($request->input('id_personne_assignee'));

        if (!$personne || (!$personne->isGestionnaire() && !$personne->isAdmin())) {
            return response()->json([
                'success' => false,
                'message' => 'Cette personne ne détient pas le rôle gestionnaire.',
            ], 422);
        }

        $livraison->update(['id_personne_assignee' => $personne->id]);

        return response()->json(['success' => true]);
    }

    /**
     * Saisie téléphonique par le staff — mêmes champs/règles que
     * ContactConfirmationController::store() (formulaire public), pour
     * les familles sans email (voir le prompt §3.1). `statut_contact`
     * passe directement à 'confirme' si les 3 champs sont fournis, ou à
     * une valeur intermédiaire ('contacte'/'injoignable') si l'appel n'a
     * pas abouti à une confirmation complète.
     */
    public function contacterManuel(Request $request, Livraison $livraison): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'statut_contact' => 'required|in:contacte,injoignable,confirme',
            'adresse_confirmee' => 'required_if:statut_contact,confirme|nullable|string|max:500',
            'membres_foyer_confirmes' => 'required_if:statut_contact,confirme|nullable|integer|min:1|max:30',
            'creneaux' => 'required_if:statut_contact,confirme|nullable|array|min:1',
            'creneaux.*' => 'in:' . implode(',', Creneau::TOUS),
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $donnees = ['statut_contact' => $request->input('statut_contact')];

        if ($request->input('statut_contact') === 'confirme') {
            $donnees['adresse_confirmee'] = $request->input('adresse_confirmee');
            $donnees['membres_foyer_confirmes'] = $request->input('membres_foyer_confirmes');
        }

        $livraison->update($donnees);

        if ($request->input('statut_contact') === 'confirme') {
            $livraison->creneaux()->delete();
            foreach ($request->input('creneaux') as $creneau) {
                $livraison->creneaux()->create(['creneau' => $creneau]);
            }
        }

        return response()->json(['success' => true]);
    }
}
