<?php
// app/Http/Controllers/VehiculeTypesController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use Amana\Shared\Models\VehiculeType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Édition du référentiel des types de véhicule (ref_vehicules,
 * amana_commun) — capacite_kg/nombre_part_max ne sont plus saisis par le
 * bénévole candidat (voir migration create_ref_vehicules_table) mais
 * définis ici par le staff, pour alimenter le futur moteur de matching
 * (amana_livraison).
 *
 * Route hors préfixe /admin, dans le même groupe `role:gestionnaire` que
 * SettingsController (voir routes/web.php) — demande explicite du
 * 24/08/2026 : admin ET gestionnaire doivent pouvoir éditer ces valeurs,
 * ce que le groupe /admin (role:admin uniquement) ne permet pas.
 *
 * Pas de page Blade dédiée : le formulaire d'édition vit directement dans
 * la page Paramètres (resources/views/settings/index.blade.php, voir
 * SettingsController::index()) plutôt que dans une entrée de menu à part.
 *
 * index() réintroduit le 03/09/2026 (retiré le 26/08/2026 en tant que
 * vue, ré-ajouté ici en JSON) : source unique pour BenevoleForm.vue
 * (candidature bénévole publique, remplace l'ancien data-vehicules
 * embarqué dans resources/views/benevole/show.blade.php) et pour les
 * pickers véhicule des écrans livraison — voir routes/web.php,
 * GET /vehicules, public et sans rôle (aucune donnée nouvellement
 * exposée, cf. commentaire sur la route).
 *
 * Ajout / retrait de types (octobre 2026, store()/destroy()/reactiver()) : « retirer » DÉSACTIVE
 * le type (colonne actif, amana/shared) au lieu de le supprimer — benevole_profils.id_vehicule_type
 * (FK RESTRICT), les tournées et les disponibilités y font référence. Un type inactif disparaît
 * des choix (formulaire bénévole, pickers, GET /vehicules) mais reste lisible partout où il est
 * déjà utilisé, et peut être réactivé. 'Non véhiculé' et 'Sans permis' (VehiculeType::IDS_PROTEGES)
 * portent de la logique métier : jamais désactivables. Le libellé se fixe à la création, il reste en
 * lecture seule ensuite (pour éviter toute confusion avec les libellés déjà affichés ailleurs).
 */
class VehiculeTypesController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            // Seulement les types actifs : source du formulaire bénévole public et des pickers.
            VehiculeType::actifs()->orderBy('id')->get(['id', 'type', 'capacite_kg', 'nombre_part_max']),
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:50'],
            'capacite_kg' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'nombre_part_max' => ['required', 'integer', 'min:0', 'max:65535'],
        ], [
            'type.required' => 'Le libellé du type est obligatoire.',
        ]);

        $libelle = trim($validated['type']);
        if (VehiculeType::whereRaw('LOWER(type) = ?', [mb_strtolower($libelle)])->exists()) {
            throw ValidationException::withMessages(['type' => 'Un type de véhicule porte déjà ce libellé.']);
        }

        $vehicule = VehiculeType::create([
            'type' => $libelle,
            'capacite_kg' => $validated['capacite_kg'],
            'nombre_part_max' => $validated['nombre_part_max'],
            'actif' => true,
        ]);

        audit('create', 'ref_vehicules', $vehicule->id, null, $vehicule->toArray());

        return redirect()->route('settings.index')->with('success', "Type de véhicule « {$vehicule->type} » ajouté.");
    }

    /** « Retirer » un type = le désactiver (voir le docblock de classe). */
    public function destroy(VehiculeType $vehiculeType): RedirectResponse
    {
        if ($vehiculeType->estProtege()) {
            return back()->withErrors(['vehicule' => "Le type « {$vehiculeType->type} » est utilisé par la logique de répartition : il ne peut pas être retiré."]);
        }

        $avant = $vehiculeType->toArray();
        $vehiculeType->update(['actif' => false]);

        audit('update', 'ref_vehicules', $vehiculeType->id, $avant, $vehiculeType->toArray());

        return redirect()->route('settings.index')->with('success', "Type de véhicule « {$vehiculeType->type} » retiré.");
    }

    public function reactiver(VehiculeType $vehiculeType): RedirectResponse
    {
        $avant = $vehiculeType->toArray();
        $vehiculeType->update(['actif' => true]);

        audit('update', 'ref_vehicules', $vehiculeType->id, $avant, $vehiculeType->toArray());

        return redirect()->route('settings.index')->with('success', "Type de véhicule « {$vehiculeType->type} » réactivé.");
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'vehicules' => ['required', 'array'],
            'vehicules.*.capacite_kg' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'vehicules.*.nombre_part_max' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);

        $avant = VehiculeType::orderBy('id')->get(['id', 'capacite_kg', 'nombre_part_max'])->toArray();

        foreach ($validated['vehicules'] as $id => $donnees) {
            VehiculeType::whereKey($id)->update([
                'capacite_kg' => $donnees['capacite_kg'],
                'nombre_part_max' => $donnees['nombre_part_max'],
            ]);
        }

        $apres = VehiculeType::orderBy('id')->get(['id', 'capacite_kg', 'nombre_part_max'])->toArray();

        audit('update', 'ref_vehicules', null, $avant, $apres);

        return redirect()->route('settings.index')->with('success', 'Capacités des véhicules mises à jour.');
    }
}
