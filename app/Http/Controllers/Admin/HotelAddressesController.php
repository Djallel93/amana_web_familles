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
 *
 * Doublons (décision du 05/09/2026) : bloqués sur `adresse_normalisee`
 * (comparaison normalisée, pas la chaîne brute — voir HotelAddress et sa
 * migration) à la fois ici (message d'erreur convivial) et par une
 * contrainte unique en base (filet de sécurité en cas d'écriture
 * concurrente). Ne bloque QUE la resaisie strictement identique une fois
 * normalisée — deux adresses réellement différentes pour un même
 * établissement (voir docblock du modèle) restent toutes les deux
 * acceptées.
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

        if ($this->dejaExistante($validated['adresse'])) {
            return back()->withErrors(['adresse' => 'Cette adresse hôtel est déjà enregistrée.'])->withInput();
        }

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

        if ($this->dejaExistante($validated['adresse'], excepte: $hotelAddress)) {
            return back()->withErrors(['adresse' => 'Cette adresse hôtel est déjà enregistrée.'])->withInput();
        }

        $avant = $hotelAddress->toArray();
        $hotelAddress->update($validated);

        audit('update', 'hotel_addresses', $hotelAddress->id, $avant, $hotelAddress->toArray());

        return redirect()->route('settings.index')->with('success', 'Adresse hôtel mise à jour.');
    }

    /**
     * true si une autre ligne porte déjà la même adresse une fois
     * normalisée (voir HotelAddress::normaliser()) — $excepte permet
     * d'ignorer la ligne elle-même lors d'une mise à jour.
     */
    private function dejaExistante(string $adresse, ?HotelAddress $excepte = null): bool
    {
        return HotelAddress::where('adresse_normalisee', HotelAddress::normaliser($adresse))
            ->when($excepte, fn($query, $hotelAddress) => $query->whereKeyNot($hotelAddress->id))
            ->exists();
    }

    public function destroy(HotelAddress $hotelAddress): RedirectResponse
    {
        $avant = $hotelAddress->toArray();
        $hotelAddress->delete();

        audit('delete', 'hotel_addresses', $hotelAddress->id, $avant, null);

        return redirect()->route('settings.index')->with('success', 'Adresse hôtel supprimée.');
    }
}
