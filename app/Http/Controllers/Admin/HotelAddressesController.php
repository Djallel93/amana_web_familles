<?php
// app/Http/Controllers/Admin/HotelAddressesController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HotelAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CRUD du référentiel hotel_addresses — section "Adresses hôtel" de l'écran
 * Paramètres (voir SettingsController::index() et
 * resources/views/settings/index.blade.php), ajoutée le 30/08/2026. Même
 * emplacement/esprit que la section "Types de véhicule" déjà présente là.
 *
 * admin ET gestionnaire (contrairement à Admin\OrganisationsController, qui
 * est admin uniquement) — voir routes/web.php : gérer cette liste n'ouvre
 * aucun accès externe au système, contrairement à une organisation
 * partenaire, donc pas de raison de la réserver à admin.
 *
 * Suppression réelle (pas de désactivation comme Organisation::destroy()) :
 * une adresse hôtel n'est référencée par rien d'autre, la retirer de la
 * liste ne casse aucune contrainte FK ni aucun accès existant.
 */
class HotelAddressesController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'adresse' => ['required', 'string', 'max:255'],
        ], [
            'adresse.required' => "L'adresse est obligatoire.",
        ]);

        $hotelAddress = HotelAddress::create($validated);

        audit('create', 'hotel_addresses', $hotelAddress->id, null, $hotelAddress->toArray());

        return redirect()->route('settings.index')->with('success', 'Adresse hôtel ajoutée.');
    }

    public function update(Request $request, HotelAddress $hotelAddress): RedirectResponse
    {
        $validated = $request->validate([
            'adresse' => ['required', 'string', 'max:255'],
        ], [
            'adresse.required' => "L'adresse est obligatoire.",
        ]);

        $avant = $hotelAddress->toArray();
        $hotelAddress->update($validated);

        audit('update', 'hotel_addresses', $hotelAddress->id, $avant, $hotelAddress->toArray());

        return redirect()->route('settings.index')->with('success', 'Adresse hôtel mise à jour.');
    }

    public function destroy(HotelAddress $hotelAddress): RedirectResponse
    {
        $avant = $hotelAddress->toArray();
        $hotelAddress->delete();

        audit('delete', 'hotel_addresses', $hotelAddress->id, $avant, null);

        return redirect()->route('settings.index')->with('success', 'Adresse hôtel supprimée.');
    }
}
