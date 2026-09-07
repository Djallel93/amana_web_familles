<?php
// app/Http/Controllers/Admin/Livraison/BenevoleDisponibiliteController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

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
 * Suivi des réponses de disponibilité bénévole pour une campagne — voir
 * le prompt du 05/09/2026 §1.3 : "a new view tracking volunteers
 * responses. In the same fashion as Suivi des contacts I can see who
 * responded and who didn't. I can also manually change response if
 * needed." Remplace le bouton "Notifier bénévole" isolé sur
 * CampagneDetail.vue par un vrai écran de suivi, sur le même modèle que
 * ContactTrackingController.
 *
 * BenevoleProfil/Personne vivent sur la connexion 'commun'
 * (Amana\Shared\Models\BenevoleProfil::getConnectionName()),
 * BenevoleDisponibilite sur la connexion par défaut de cette app — deux
 * requêtes mono-connexion fusionnées en PHP, même raisonnement que
 * partout ailleurs dans ce contrôleur/l'app pour ce genre de jointure
 * (voir FamillesController::baseQuery() pour le précédent le plus
 * ancien).
 */
class BenevoleDisponibiliteController extends Controller
{
    public function __construct(
        private readonly BenevoleDisponibiliteService $disponibiliteService,
    ) {
    }

    public function index(Campagne $campagne): View
    {
        return view('livraison.benevole-disponibilite', ['campagne' => $campagne->load('journees')]);
    }

    /**
     * $request->id_campagne_journee : campagne multi-jours, un bénévole
     * confirme séparément par journée (voir BenevoleDisponibiliteService)
     * — par défaut la première journée si non précisé (cas mono-jour, le
     * plus courant).
     */
    public function queue(Request $request, Campagne $campagne): JsonResponse
    {
        $journee = $request->filled('id_campagne_journee')
            ? $campagne->journees()->findOrFail($request->integer('id_campagne_journee'))
            : $campagne->journees()->first();

        if (!$journee) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $profils = BenevoleProfil::where('statut', 'Validé')->with('personne')->get();

        $disponibilites = BenevoleDisponibilite::with('creneaux')
            ->where('id_campagne_journee', $journee->id)
            ->get()
            ->keyBy('id_personne');

        $lignes = $profils
            ->filter(fn (BenevoleProfil $p) => $p->personne !== null)
            ->map(function (BenevoleProfil $profil) use ($disponibilites) {
                $dispo = $disponibilites->get($profil->id_personne);

                return [
                    'id_personne' => $profil->id_personne,
                    'nom' => $profil->personne->nom,
                    'prenom' => $profil->personne->prenom,
                    'telephone' => $profil->personne->telephone ?? null,
                    'email' => $profil->personne->email ?? null,
                    'statut' => $dispo->statut ?? 'non_confirme',
                    'vehicule_confirme' => $dispo->vehicule_confirme ?? false,
                    'coverage_confirmee' => $dispo->coverage_confirmee ?? false,
                    'coverage_notes' => $dispo->coverage_notes ?? null,
                    'creneaux' => $dispo ? $dispo->creneaux->pluck('creneau') : [],
                ];
            })
            ->values();

        // Filtre statut (05/09/2026, prompt §1.3 : "Add filters") — 'tous'
        // par défaut, 'confirme'/'non_confirme' sinon. Appliqué en PHP
        // après fusion, la source de vérité du statut n'existant qu'après
        // avoir croisé les deux requêtes ci-dessus.
        if ($request->filled('statut') && in_array($request->input('statut'), BenevoleDisponibilite::STATUTS, true)) {
            $lignes = $lignes->where('statut', $request->input('statut'))->values();
        }
        if ($request->filled('recherche')) {
            $terme = mb_strtolower($request->input('recherche'));
            $lignes = $lignes->filter(fn ($l) => str_contains(mb_strtolower($l['nom'] . ' ' . $l['prenom']), $terme))->values();
        }

        return response()->json([
            'data' => $lignes,
            'total' => $lignes->count(),
            'id_campagne_journee' => $journee->id,
        ]);
    }

    /**
     * Modification manuelle par un gestionnaire (prompt §1.3 : "I can
     * also manually change response if needed") — même service que la
     * confirmation par le bénévole lui-même (BenevoleDisponibiliteService::confirmer()),
     * pas de logique dupliquée pour le cas "admin corrige à la place".
     */
    public function mettreAJour(Request $request, Campagne $campagne, int $idPersonne): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_campagne_journee' => 'required|integer',
            'statut' => 'required|in:' . implode(',', BenevoleDisponibilite::STATUTS),
            'vehicule_confirme' => 'nullable|boolean',
            'coverage_confirmee' => 'nullable|boolean',
            'coverage_notes' => 'nullable|string|max:1000',
            'creneaux' => 'nullable|array',
            'creneaux.*' => 'in:' . implode(',', Creneau::TOUS),
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $journee = $campagne->journees()->findOrFail($request->integer('id_campagne_journee'));

        if ($request->input('statut') === 'non_confirme') {
            // Repasser "non confirmé" : supprime la ligne plutôt que de
            // garder un enregistrement 'confirme' historique avec un
            // statut incohérent — un futur queue() la retraitera alors
            // comme "pas encore répondu", identique à un bénévole qui n'a
            // simplement jamais répondu.
            BenevoleDisponibilite::where('id_personne', $idPersonne)
                ->where('id_campagne_journee', $journee->id)
                ->delete();

            return response()->json(['success' => true]);
        }

        $disponibilite = $this->disponibiliteService->confirmer(
            $idPersonne,
            $journee,
            $validator->safe()->only(['vehicule_confirme', 'coverage_confirmee', 'coverage_notes']),
            $request->input('creneaux', []),
        );

        return response()->json(['success' => true, 'disponibilite' => $disponibilite->load('creneaux')]);
    }
}
