<?php
// app/Http/Controllers/Livraison/PosteReleveController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Livraison\Concerns\FiltreCampagnesEquipe;
use App\Http\Controllers\Livraison\Concerns\UrlRetourEquipe;
use App\Http\Resources\ReleveResource;
use App\Models\Campagne;
use App\Models\CampagneArrivee;
use App\Models\Donation;
use App\Support\RelevePosteDefinition;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Poste de relevé ponctuel — pesée (équipe_pesee → Donation) et réception
 * (équipe_reception → CampagneArrivee). Fusion de PeseeController et
 * ReceptionController le 10/09/2026 (Section A1 du refactor) : les deux
 * étaient des quasi-duplicatas (voir RelevePosteDefinition pour le détail
 * de ce qui varie). AUCUNE donnée famille visible depuis ces écrans (voir
 * le prompt du 30/08/2026 §4) : un relevé ponctuel (poids total unique /
 * nombre de donateurs présents à un instant T), jamais de ventilation ni
 * de liste nominative — voir Donation/CampagneArrivee.
 *
 * Les deux postes restent des étapes FACULTATIVES par campagne (prompt du
 * 05/09/2026 §3/§4) : aucune validation n'exige qu'au moins un relevé
 * existe pour qu'une campagne avance.
 *
 * `$type` ('pesee'|'reception') arrive en route default (voir
 * routes/web.php) — chaque groupe de routes garde son propre préfixe,
 * son propre nom (livraison.pesee.* / livraison.reception.*) et son
 * propre rôle en middleware, seule la classe contrôleur est désormais
 * partagée.
 *
 * modifier()/supprimer() restent deux méthodes fines par modèle plutôt
 * qu'une seule générique : le binding implicite de route ({don} →
 * Donation, {arrivee} → CampagneArrivee) et la résolution de policy qui
 * en dépend (can:gerer,{param} → DonationPolicy/CampagneArriveePolicy
 * selon la classe du paramètre) exigent un type concret sur le paramètre
 * de route — passer par une interface commune ferait perdre cette
 * résolution automatique pour gagner quelques lignes. Toute la logique
 * réelle (validation, mise à jour, réponse) vit une seule fois dans
 * modifierCommun()/supprimerCommun().
 */
class PosteReleveController extends Controller
{
    use FiltreCampagnesEquipe;
    use UrlRetourEquipe;

    /**
     * Point d'entrée sans campagne — voir le prompt §4.1 : equipe_pesee/
     * equipe_reception n'avaient aucune entrée de menu vers cet écran.
     * Liste restreinte aux campagnes affectées (08/09/2026, voir
     * FiltreCampagnesEquipe).
     */
    public function choisir(string $type): View
    {
        $definition = RelevePosteDefinition::pour($type);
        $campagnes = $this->campagnesPourEquipe(Auth::user(), $definition->role, avecJournees: true);

        return view('livraison.choisir-poste', [
            'campagnes' => $campagnes,
            'titre' => $definition->titreChoix,
            'routeIndex' => "livraison.{$type}.show",
            'avecJournee' => true,
        ]);
    }

    /**
     * $campagne AVANT $type dans la signature (22/09/2026, correction) :
     * $type arrive via Route::defaults() plutôt qu'un segment d'URL — le
     * binder de route place les paramètres résolus depuis l'URL avant
     * ceux ajoutés par defaults() dans le tableau interne des paramètres
     * de la route, et ResolvesRouteDependencies (Laravel) ne réordonne
     * jamais un paramètre déjà résolu (route model binding ou default)
     * pour le faire correspondre à l'ordre de la signature — seuls les
     * paramètres à résoudre depuis le container (ex. Request, absent
     * ici) sont insérés à leur position réfléchie. L'appel final se fait
     * positionnellement (array_values()) : avec $type déclaré en
     * premier, $campagne (modèle déjà résolu) et $type (chaîne, ajoutée
     * après par défaut) se retrouvaient inversés, d'où le TypeError
     * "Argument #2 ($campagne) must be of type Campagne, string given".
     * Même correction appliquée à enregistrer()/journal() ci-dessous.
     */
    public function show(Campagne $campagne, string $type): View
    {
        $definition = RelevePosteDefinition::pour($type);

        return view('livraison.poste-releve', [
            'definition' => $definition,
            'campagne' => $campagne->load('journees'),
            'autresCampagnes' => Campagne::whereIn('statut', ['preparation', 'en_cours'])->orderByDesc('date_livraison')->get(),
            // Un compte gestionnaire/admin (le plus courant en pratique,
            // voir EnsureLivraisonRole) a accès à campagnes.show — y
            // renvoyer directement plutôt qu'à choisir() (05/09/2026,
            // correction : "I expect to go back to /livraison/campagnes/{id}").
            // Un compte equipe_* PUR n'a lui accès qu'à choisir() — voir
            // UrlRetourEquipe (Section B du refactor).
            'urlRetour' => $this->urlRetourEquipe($campagne, "livraison.{$type}.choisir"),
        ]);
    }

    /**
     * id_campagne_journee ajouté le 05/09/2026 (prompt §3.3) — sélecteur
     * en haut de l'écran, nullable ici aussi côté validation : une
     * campagne mono-journée n'affiche pas le sélecteur côté Vue et
     * n'envoie donc rien.
     */
    /** Ordre des paramètres : voir le docblock de show() ci-dessus. */
    public function enregistrer(Request $request, Campagne $campagne, string $type): JsonResponse
    {
        $definition = RelevePosteDefinition::pour($type);

        $validator = Validator::make($request->all(), [
            $definition->champ => $definition->regleValidation,
            'id_campagne_journee' => 'nullable|integer|exists:campagne_journees,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $definition->modelClass::create([
            'id_campagne' => $campagne->id,
            'id_campagne_journee' => $request->input('id_campagne_journee'),
            $definition->champ => $request->input($definition->champ),
            'horodatage' => now(),
            'logge_par' => auth()->id(),
        ]);

        // $definition->cleItemJson retiré de la réponse le 12/09/2026
        // (Section E3 du refactor, suite) : poste-releve.blade.php
        // (formulaire de saisie) ne lit que .success/.total_campagne
        // après enregistrement — il rafraîchit le journal séparément via
        // chargerJournal() plutôt que de lire la ligne créée dans cette
        // réponse.
        return response()->json([
            'success' => true,
            'total_campagne' => $campagne->fresh()->{$definition->accesseurTotalCampagne},
        ]);
    }

    /**
     * Journal des relevés — voir le prompt §3.2 : "a table with a row for
     * each input". Filtrable par journée comme enregistrer() ; sans
     * filtre, montre tout l'historique de la campagne.
     */
    /** Ordre des paramètres : voir le docblock de show() ci-dessus. */
    public function journal(Request $request, Campagne $campagne, string $type): JsonResponse
    {
        $definition = RelevePosteDefinition::pour($type);

        $query = $definition->modelClass::where('id_campagne', $campagne->id);
        if ($request->filled('id_campagne_journee')) {
            $query->where('id_campagne_journee', $request->input('id_campagne_journee'));
        }

        $releves = $query->with('loggePar:id,nom,prenom')->orderByDesc('horodatage')->get();

        return response()->json([
            $definition->cleListeJson => $releves->map(fn ($releve) => new ReleveResource($releve, $definition)),
            $definition->cleTotalJournalJson => $definition->estEntier
                ? (int) $query->sum($definition->champ)
                : (float) $query->sum($definition->champ),
        ]);
    }

    /**
     * Édition/suppression d'une ligne — pas de restriction de propriété
     * (n'importe quel membre de l'équipe peut modifier une ligne saisie
     * par quelqu'un d'autre) : décision explicite du 05/09/2026, ni la
     * pesée ni la réception n'ont jamais eu de notion de "propriétaire"
     * d'une saisie.
     */
    public function modifierDon(Request $request, Donation $don): JsonResponse
    {
        return $this->modifierCommun($request, $don, RelevePosteDefinition::pesee());
    }

    public function modifierArrivee(Request $request, CampagneArrivee $arrivee): JsonResponse
    {
        return $this->modifierCommun($request, $arrivee, RelevePosteDefinition::reception());
    }

    public function supprimerDon(Donation $don): JsonResponse
    {
        return $this->supprimerCommun($don);
    }

    public function supprimerArrivee(CampagneArrivee $arrivee): JsonResponse
    {
        return $this->supprimerCommun($arrivee);
    }

    private function modifierCommun(Request $request, Model $releve, RelevePosteDefinition $definition): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            $definition->champ => $definition->regleValidation,
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $releve->update([$definition->champ => $request->input($definition->champ)]);

        // $definition->cleItemJson retiré de la réponse le 12/09/2026
        // (Section E3 du refactor, suite) : modifierLigne()
        // (poste-releve.blade.php) ne lit que .success, puis rafraîchit le
        // journal séparément via chargerJournal().
        return response()->json(['success' => true]);
    }

    private function supprimerCommun(Model $releve): JsonResponse
    {
        $releve->delete();

        return response()->json(['success' => true]);
    }
}
