<?php
// app/Http/Controllers/Admin/Livraison/EquipeMembresController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use Amana\Shared\Models\Personne;
use App\Http\Controllers\Controller;
use App\Http\Resources\CampagneResource;
use App\Models\Campagne;
use App\Models\CampagneEquipeMembre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Écran d'admin pour peupler campagne_equipe_membres — voir le prompt du
 * 07/09/2026 : jusqu'ici cette table n'avait aucun moyen d'être remplie
 * hors insertion SQL manuelle. Même architecture que
 * BenevoleDisponibiliteController (écran dédié lié depuis
 * CampagneDetail.vue, pas un onglet — voir campagne-detail.blade.php,
 * aucun des écrans liés depuis cette page n'est un onglet dans cette
 * app) : page Inertia depuis le 16/09/2026 (Section E4 du refactor —
 * c'était jusque-là une Blade minimale + un îlot Vue chargé en JSON ;
 * BenevoleDisponibiliteController est passé à Inertia dans le chunk
 * suivant, même forme, à ceci près que sa liste reste chargée en XHR),
 * fusion Personne
 * (connexion 'commun') / CampagneEquipeMembre (connexion par défaut) en
 * PHP, même raisonnement que partout ailleurs dans ce contrôleur/l'app
 * pour ce genre de jointure (voir BenevoleDisponibiliteController).
 *
 * Décisions du 08/09/2026 (prompt de cette date) :
 *  - Accès à cet écran : role:gestionnaire (voir routes/web.php), pas de
 *    restriction supplémentaire — gestionnaire_externe ne peut de toute
 *    façon pas atteindre le groupe Admin\Livraison\*.
 *  - Le picker personne n'est PAS restreint aux détenteurs du rôle
 *    global equipe_* correspondant : n'importe quelle Personne staff
 *    Familles peut être affectée (même portée que
 *    PickersController::personnes(), réutilisé tel quel — voir
 *    PersonPicker.vue, aucun paramètre `role` passé ici).
 *  - Retirer une affectation ne touche JAMAIS les relevés déjà saisis
 *    (campagne_arrivees.logge_par / donations.logge_par ne référencent
 *    que la Personne, jamais une ligne campagne_equipe_membres) — aucune
 *    logique de purge à écrire, la séparation des tables suffit déjà.
 */
class EquipeMembresController extends Controller
{
    /**
     * Section E4 du refactor (16/09/2026) — première page livraison
     * convertie à Inertia, remplace resources/views/livraison/
     * equipe-membres.blade.php (supprimée dans ce même chunk).
     *
     * La liste est passée en prop de page plutôt que rechargée en XHR au
     * montage : c'est un tableau non paginé que cette action produit déjà
     * trivialement (même requête que l'ancien endpoint JSON `liste`, voir
     * lignesEquipe() ci-dessous), donc le premier rendu n'a plus d'état
     * "Chargement…". Après ajout/retrait, le composant demande un
     * router.reload({ only: ['lignes'] }) — rechargement partiel Inertia,
     * qui ré-exécute cette action et ne resérialise que cette prop. Les
     * MUTATIONS, elles, restent en JSON (ajouter()/retirer() inchangées) :
     * voir la décision du 16/09/2026 sur la profondeur de migration des
     * écrans livraison, tous construits en îlots XHR.
     *
     * L'ancien endpoint `livraison.campagnes.equipes.liste` est supprimé
     * avec sa route : son unique appelant était EquipeMembresQueue.vue
     * (vérifié par grep avant suppression), qui lit désormais la prop.
     */
    public function index(Campagne $campagne): InertiaResponse
    {
        return Inertia::render('Livraison/Equipes', [
            'campagne' => new CampagneResource($campagne),
            'lignes' => $this->lignesEquipe($campagne),
            'retourUrl' => route('livraison.campagnes.show', $campagne),
            'ajouterUrl' => route('livraison.campagnes.equipes.ajouter', $campagne),
            'retirerUrlTemplate' => route('livraison.campagnes.equipes.retirer', [$campagne, '__ID__', '__ROLE__']),
        ]);
    }

    /**
     * Une ligne par personne affectée, rôles groupés (une personne peut
     * cumuler plusieurs rôles équipe_* sur la même campagne — voir
     * create_campagne_equipe_membres_table.php) plutôt qu'une ligne par
     * (personne, rôle), pour un affichage type "Farid — Pesée, Packaging"
     * en une seule carte côté Vue.
     *
     * Corps repris tel quel de l'ancienne action JSON liste() (16/09/2026,
     * Section E4) — seule l'enveloppe response()->json(['data' => ...]) a
     * disparu, la prop de page portant directement le tableau.
     */
    private function lignesEquipe(Campagne $campagne): Collection
    {
        $membres = $campagne->equipeMembres()->get();

        $personnes = Personne::whereIn('id', $membres->pluck('id_personne')->unique())
            ->get(['id', 'nom', 'prenom'])
            ->keyBy('id');

        return $membres
            ->groupBy('id_personne')
            ->map(function ($groupe, $idPersonne) use ($personnes) {
                $personne = $personnes->get($idPersonne);

                return [
                    'id_personne' => (int) $idPersonne,
                    // Personne introuvable (compte supprimé côté commun) :
                    // reste affichable plutôt que de faire planter la
                    // liste — même filet de sécurité que
                    // BenevoleDisponibiliteController::queue().
                    'nom' => $personne->nom ?? '(personne introuvable)',
                    'prenom' => $personne->prenom ?? '',
                    'roles' => $groupe->pluck('role')->values()->all(),
                ];
            })
            ->values();
    }

    /**
     * firstOrCreate() plutôt que create() : unique(['id_campagne',
     * 'id_personne', 'role']) ferait de toute façon échouer un doublon,
     * mais un double-clic réseau sur "Ajouter" ne doit pas remonter une
     * erreur 500 (contrainte SQL) côté utilisateur.
     */
    public function ajouter(Request $request, Campagne $campagne): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_personne' => 'required|integer',
            'role' => 'required|in:' . implode(',', CampagneEquipeMembre::ROLES),
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $campagne->equipeMembres()->firstOrCreate([
            'id_personne' => $request->integer('id_personne'),
            'role' => $request->input('role'),
        ]);

        return response()->json(['success' => true]);
    }

    public function retirer(Campagne $campagne, int $idPersonne, string $role): JsonResponse
    {
        $campagne->equipeMembres()
            ->where('id_personne', $idPersonne)
            ->where('role', $role)
            ->delete();

        return response()->json(['success' => true]);
    }
}
