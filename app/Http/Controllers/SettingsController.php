<?php
// app/Http/Controllers/SettingsController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use Amana\Shared\Http\Controllers\SettingsControllerBase;
use Amana\Shared\Models\Setting;
use Amana\Shared\Models\VehiculeType;
use App\Models\HotelAddress;
use App\Models\Organisation;
use Illuminate\View\View;

/**
 * Surcharge index() pour intégrer la section "Types de véhicule" à la
 * même page que les réglages génériques — demande du 26/08/2026 : ne pas
 * en faire une page/entrée de menu séparée (voir VehiculeTypesController,
 * dont seul update() reste routé désormais, index() n'existant plus).
 * Vue propre à l'app (resources/views/settings/index.blade.php), pas la
 * vue générique du package — voir SettingsControllerBase pour ce schéma
 * de surcharge. Section "Adresses hôtel" ajoutée le 30/08/2026 sur le
 * même principe.
 *
 * Onglets ajoutés le 05/09/2026 (page jugée trop chargée/trop de scroll) :
 * regroupement par thème calqué sur le même principe que
 * amana_web_planning\SettingsController::grouperDecalages() (filtrage par
 * préfixe de clé), mais en PHP simple ici plutôt qu'un vrai regroupement
 * imbriqué — seulement deux sous-ensembles de la boucle générique à
 * séparer : les deux interrupteurs d'inscription ("Général") et les
 * réglages d'algorithme de clustering ("Itinéraires"). Les clés
 * route_hq_latitude/route_hq_longitude sont exclues de $reglagesItineraires
 * : elles ont leur propre widget dédié dans l'onglet "Itinéraires" (voir
 * HqCoordinatesAutocomplete.vue) plutôt que la boucle générique.
 */
class SettingsController extends SettingsControllerBase
{
    private const CLES_HQ = ['route_hq_latitude', 'route_hq_longitude'];

    protected function appCode(): string
    {
        return 'familles';
    }

    public function index(): View
    {
        $settings = Setting::allForApp($this->appCode());

        return view('settings.index', [
            'settings' => $settings,
            'reglagesGeneraux' => $settings->only(['inscription_familles_ouverte', 'inscription_benevoles_ouverte']),
            'reglagesItineraires' => $settings->filter(
                fn($_, $cle) => str_starts_with($cle, 'route_') && !in_array($cle, self::CLES_HQ, true)
            ),
            'vehicules' => VehiculeType::orderBy('id')->get(),
            'organisations' => Organisation::orderByDesc('est_principale')->orderBy('nom')->get(),
            'hotelAddresses' => HotelAddress::orderBy('adresse')->get(),
            'googlePlacesKey' => config('services.google.maps.places_api_key'),
        ]);
    }
}
