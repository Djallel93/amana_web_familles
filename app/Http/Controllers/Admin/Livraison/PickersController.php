<?php
// app/Http/Controllers/Admin/Livraison/PickersController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use Amana\Shared\Models\Personne;
use App\Http\Controllers\Controller;
use App\Models\PersonneDesactivee;
use App\Services\ChauffeursConfirmesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints de recherche pour les sélecteurs des écrans livraison (voir
 * migration frontend du 03/09/2026 — remplace les inputs "ID numérique"
 * de la version placeholder par de vrais pickers).
 *
 * Volontairement séparé de Admin\PersonnesController plutôt qu'ajouté à
 * ce dernier : PersonnesController::index()/create()/etc. vivent dans le
 * groupe role:admin (voir routes/web.php), alors que ces recherches
 * doivent être utilisables par un gestionnaire (assignation de contact,
 * réassignation de tournée) — même groupe role:gestionnaire que le reste
 * du domaine Livraison plutôt qu'un élargissement du groupe admin
 * existant.
 *
 * Le picker véhicule (recherche par type) n'a pas d'équivalent ici : le
 * référentiel ref_vehicules est un ensemble fixe de 8 lignes (voir
 * VehiculeTypesController), une recherche serait inutile — VehiculeTypesController::index()
 * sert directement la liste complète, réutilisée par BenevoleForm.vue et
 * par la réassignation de tournée (voir routes/web.php, GET /vehicules).
 * Le picker chauffeur de BuildRouteFlow.vue/RoutesPanel.vue n'a lui-même
 * PLUS de VehiculePicker séparé depuis le 09/09/2026 (prompt de cette
 * date §5.1.2/5.2.1) — le véhicule est dérivé du profil du bénévole
 * choisi (voir avec_vehicule ci-dessous), donc VehiculeTypesController
 * reste utilisé par les autres écrans mais plus par ce flux-là.
 */
class PickersController extends Controller
{
    public function __construct(private readonly ChauffeursConfirmesService $chauffeursConfirmes) {}

    /**
     * Recherche de personnes par nom/prénom, filtrable par rôle minimum
     * (`role=benevole` pour le picker chauffeur du tableau de bord,
     * `role=gestionnaire` pour l'assignation de contact). Limité au
     * staff Familles (staffFamilles(), voir App\Models\Personne) et à
     * 20 résultats — un picker de recherche, pas un export.
     */
    public function personnes(Request $request): JsonResponse
    {
        // Personnes désactivées pour Familles (03/10/2026) : jamais proposées,
        // ni pour une campagne ni pour une assignation — voir
        // PersonneActivationService.
        $query = Personne::query()
            ->whereNotIn('id', PersonneDesactivee::ids())
            ->whereHas('roles', function ($q) {
                $q->whereHas('application', fn($q2) => $q2->where('code', 'familles'));
            });

        if ($request->filled('q')) {
            $terme = '%' . $request->input('q') . '%';
            $query->where(function ($q) use ($terme) {
                $q->where('nom', 'like', $terme)->orWhere('prenom', 'like', $terme);
            });
        }

        // avec_vehicule (09/09/2026, prompt de cette date §5.1.2/5.2.1) :
        // "filter them and keep only drivers with vehicules" — le picker
        // chauffeur de BuildRouteFlow.vue/RoutesPanel.vue n'a plus de
        // VehiculePicker séparé, le véhicule est dérivé du profil du
        // bénévole choisi (benevoleProfil.id_vehicule_type), donc un
        // bénévole qui n'en a pas déclaré aucun n'est pas proposable ici.
        // Contrainte SQL (pas un filtrage en mémoire comme hasAtLeastRole()
        // ci-dessous) : appliquée avant le limit(50), pour ne pas risquer
        // de tronquer la liste avec des résultats qui seraient de toute
        // façon exclus ensuite.
        // Ignoré quand la liste est restreinte aux chauffeurs d'une journée (voir plus
        // bas, 09/10/2026) : le véhicule effectif y est celui de la journée, qui peut
        // différer — ou exister sans véhicule au profil.
        if ($request->boolean('avec_vehicule') && !$request->filled('id_campagne_journee') && !$request->filled('id_campagne')) {
            $query->whereHas('benevoleProfil', fn($q) => $q->whereNotNull('id_vehicule_type'));
        }

        // id_campagne_journee / id_campagne (09/10/2026) : « Prise en charge par un
        // chauffeur » — seuls les bénévoles CONFIRMÉS pour cette journée sont proposés
        // (voir ChauffeursConfirmesService), avec le véhicule déclaré pour la journée.
        $chauffeursJournee = null;
        if ($request->filled('id_campagne_journee') || $request->filled('id_campagne')) {
            $chauffeursJournee = $this->chauffeursConfirmes->pourJournee(
                $request->filled('id_campagne_journee') ? $request->integer('id_campagne_journee') : null,
                $request->filled('id_campagne') ? $request->integer('id_campagne') : null,
            );
            $query->whereIn('id', array_keys($chauffeursJournee));
        }

        // hasAtLeastRole() n'est pas une contrainte SQL (logique de cascade
        // admin ⊇ gestionnaire ⊇ membre ⊇ benevole côté PHP, voir
        // Amana\Shared\Models\Personne::hasAtLeastRole()) — filtrage en
        // mémoire après la recherche nom/prénom plutôt qu'un whereIn sur
        // des rôles littéraux, pour rester correct si la cascade évolue.
        // limit(50) borne le coût de ce filtrage en mémoire avant de
        // retomber sous la limite réelle de 20 résultats ci-dessous.
        // tous=1 (29/09/2026, prompt de cette date §2.2/§3) : liste COMPLÈTE,
        // sans les plafonds 50/20 ci-dessous — pour PersonSelect.vue, le
        // dropdown avec recherche « par le début du prénom/nom », qui charge
        // la liste une fois et filtre côté client (le staff Familles reste
        // de l'ordre de quelques dizaines de personnes, contrairement à un
        // export). Sans ce paramètre : comportement historique inchangé
        // (PersonPicker.vue, recherche serveur bornée à 20 résultats).
        $toutes = $request->boolean('tous');

        $requete = $query->orderBy('nom')->orderBy('prenom')->with('benevoleProfil.vehiculeType');
        if (!$toutes) {
            $requete->limit(50);
        }
        $personnes = $requete->get(['id', 'nom', 'prenom']);

        if ($request->filled('role')) {
            $roleMin = $request->input('role');
            $personnes = $personnes->filter(fn(Personne $p) => $p->hasAtLeastRole($roleMin, 'familles'))->values();
        }

        // id_vehicule_type/vehicule_type (09/09/2026, prompt de cette date
        // §5.1.2/5.2.1) : le picker chauffeur n'a plus de VehiculePicker à
        // côté, BuildRouteFlow.vue/RoutesPanel.vue dérivent le véhicule du
        // profil du bénévole sélectionné — exposé ici directement plutôt
        // qu'un aller-retour supplémentaire.
        return response()->json(
            ($toutes ? $personnes : $personnes->take(20))->map(function (Personne $p) use ($chauffeursJournee) {
                // Véhicule de la journée quand la liste est restreinte aux chauffeurs confirmés.
                $vehicule = $chauffeursJournee[$p->id] ?? null;

                return [
                    'id' => $p->id,
                    'nom' => $p->nom,
                    'prenom' => $p->prenom,
                    'id_vehicule_type' => $vehicule->id ?? $p->benevoleProfil?->id_vehicule_type,
                    'vehicule_type' => $vehicule->type ?? $p->benevoleProfil?->vehiculeType?->type,
                ];
            }),
        );
    }
}
