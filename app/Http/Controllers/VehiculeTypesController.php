<?php
// app/Http/Controllers/VehiculeTypesController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use Amana\Shared\Models\VehiculeType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
 * Pas de page dédiée (index() retiré le 26/08/2026) : le formulaire vit
 * directement dans la page Paramètres (resources/views/settings/index.blade.php,
 * voir SettingsController::index()) plutôt que dans une entrée de menu à
 * part — seul update() reste routé ici.
 *
 * Pas de create/delete : le référentiel est un ensemble fixe de 8 lignes
 * (voir VehiculeTypesSeeder) — seules capacite_kg et nombre_part_max sont
 * éditables ligne par ligne (le libellé 'type' reste en lecture seule
 * pour éviter toute confusion avec les libellés déjà utilisés côté
 * formulaire public).
 */
class VehiculeTypesController extends Controller
{
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
