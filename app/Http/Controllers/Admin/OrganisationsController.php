<?php
// app/Http/Controllers/Admin/OrganisationsController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organisation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CRUD du référentiel organisations partenaires — décision du 28/08/2026 :
 * section dédiée dans l'écran Paramètres (voir resources/views/settings/
 * index.blade.php et SettingsController::index()), pas une page à part,
 * même esprit que la section "Types de véhicule" déjà présente là.
 *
 * Contrairement à VehiculeTypesController (référentiel fixe, édition seule),
 * les organisations sont un ensemble ouvert : create/update/destroy tous
 * présents ici. `destroy()` désactive plutôt que supprime réellement (voir
 * commentaire sur la méthode) — un dossier peut déjà être rattaché à
 * l'organisation.
 *
 * admin uniquement (role:admin, voir routes/web.php) — décision du
 * 28/08/2026 : créer une organisation revient à ouvrir un nouvel accès
 * externe au système, un cran au-dessus de ce que gestionnaire édite déjà
 * ailleurs (stats/vehicules).
 */
class OrganisationsController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:organisations,code'],
            'nom' => ['required', 'string', 'max:150'],
        ], [
            'code.unique' => 'Ce code est déjà utilisé par une autre organisation.',
            'code.alpha_dash' => 'Le code ne peut contenir que lettres, chiffres, tirets et underscores.',
        ]);

        $organisation = Organisation::create($validated + ['actif' => true]);

        audit('create', 'organisations', $organisation->id, null, $organisation->toArray());

        return redirect()->route('settings.index')->with('success', "Organisation « {$organisation->nom} » créée.");
    }

    public function update(Request $request, Organisation $organisation): RedirectResponse
    {
        $validated = $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            'actif' => ['required', 'boolean'],
        ]);

        // La ligne AMANA (est_principale) ne peut jamais être désactivée —
        // toute la logique de rattachement par défaut (voir
        // FamilleUpsertService::rattacherOrganisationInitiale()) en dépend.
        if ($organisation->est_principale) {
            $validated['actif'] = true;
        }

        $avant = $organisation->toArray();
        $organisation->update($validated);

        audit('update', 'organisations', $organisation->id, $avant, $organisation->toArray());

        return redirect()->route('settings.index')->with('success', "Organisation « {$organisation->nom} » mise à jour.");
    }

    /**
     * "Suppression" = désactivation (actif = false), jamais une vraie
     * suppression de ligne — une organisation peut déjà avoir des dossiers
     * rattachés (famille_organisation) et des comptes gestionnaire_externe
     * (personne_organisation), voir contraintes FK cascadeOnDelete sur ces
     * deux tables : une suppression réelle romprait silencieusement l'accès
     * de comptes existants plutôt que de simplement l'empêcher pour l'avenir.
     * Désactivée, l'organisation disparaît des formulaires publics (voir
     * Organisation::scopeActifs()) sans toucher à l'historique.
     */
    public function destroy(Organisation $organisation): RedirectResponse
    {
        if ($organisation->est_principale) {
            return back()->withErrors(['organisation' => "L'organisation principale (AMANA) ne peut pas être désactivée."]);
        }

        $avant = $organisation->toArray();
        $organisation->update(['actif' => false]);

        audit('update', 'organisations', $organisation->id, $avant, $organisation->toArray());

        return redirect()->route('settings.index')->with('success', "Organisation « {$organisation->nom} » désactivée.");
    }
}
