<?php
// app/Http/Controllers/SettingsController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use Amana\Shared\Http\Controllers\SettingsControllerBase;
use Amana\Shared\Models\Setting;
use Amana\Shared\Models\VehiculeType;
use App\Models\HotelAddress;
use App\Models\Organisation;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Surcharge index() pour intégrer la section "Types de véhicule" à la
 * même page que les réglages génériques — demande du 26/08/2026 : ne pas
 * en faire une page/entrée de menu séparée (voir VehiculeTypesController,
 * dont seul update() reste routé désormais, index() n'existant plus).
 * Page Inertia propre à l'app (Settings/Index.vue, depuis le 16/09/2026 —
 * Section E4 du refactor, chunk settings ; auparavant une Vue Blade
 * dédiée, resources/views/settings/index.blade.php, elle-même distincte
 * de la vue générique du package), voir SettingsControllerBase pour ce
 * schéma de surcharge. Section "Adresses hôtel" ajoutée le 30/08/2026 sur
 * le même principe.
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

    /**
     * Section E4 du refactor (16/09/2026, chunk settings — dernier de la
     * section) — page Inertia, remplace resources/views/settings/
     * index.blade.php (supprimée dans ce même chunk). update() (hérité
     * de SettingsControllerBase, vendor) et les quatre autres contrôleurs
     * qui soumettent dans cette page (VehiculeTypesController::update(),
     * Admin\OrganisationsController::store()/update()/destroy(),
     * Admin\HotelAddressesController::store()/update()/destroy())
     * restent des redirections classiques inchangées — seule cette
     * action change, comme pour toutes les conversions de ce refactor.
     *
     * Contrairement à toutes les pages converties jusqu'ici, les sept
     * <form> de cette page restent des soumissions natives classiques
     * (pas de router.post()) : Inertia n'intercepte jamais un <form> HTML
     * natif, donc leur redirection pleine page (avec withErrors()/
     * withInput() automatiques de Laravel après une ValidationException)
     * continue de fonctionner sans rien changer côté serveur au-delà de
     * cette seule action de rendu.
     *
     * $defaultTab n'est plus calculé ici : la Blade le calculait depuis
     * $errors->keys() (variable Blade globale, invisible depuis Vue) ;
     * resources/js/pages/Settings/Index.vue le recalcule côté client à
     * partir de la prop 'errors' (partagée automatiquement par la classe
     * Middleware Inertia parente — voir HandleInertiaRequests::share()),
     * même logique de préfixes portée telle quelle en TypeScript.
     *
     * 'old' (voir HandleInertiaRequests::share(), ajouté dans ce même
     * chunk) fournit l'équivalent de old() pour les sept formulaires —
     * settings.route_hq_latitude/longitude, vehicules.{id}.*, code, nom,
     * adresse.
     */
    public function index(): InertiaResponse
    {
        $settings = Setting::allForApp($this->appCode());

        return Inertia::render('Settings/Index', [
            'settings' => $settings,
            'reglagesGeneraux' => $settings->only(['inscription_familles_ouverte', 'inscription_benevoles_ouverte']),
            'reglagesItineraires' => $settings->filter(
                fn($_, $cle) => str_starts_with($cle, 'route_') && !in_array($cle, self::CLES_HQ, true)
            ),
            'vehicules' => VehiculeType::orderBy('id')->get(),
            'organisations' => Organisation::orderByDesc('est_principale')->orderBy('nom')->get(),
            'hotelAddresses' => HotelAddress::orderBy('adresse')->get(),
            'googlePlacesKey' => config('services.google.maps.places_api_key'),
            'updateUrl' => route('settings.update'),
            'vehiculesUpdateUrl' => route('vehicules.update'),
            'organisationsStoreUrl' => route('admin.organisations.store'),
            'organisationsUpdateUrlTemplate' => route('admin.organisations.update', ['organisation' => '__ID__']),
            'hotelAddressesStoreUrl' => route('hotel-addresses.store'),
            'hotelAddressesUpdateUrlTemplate' => route('hotel-addresses.update', ['hotelAddress' => '__ID__']),
            'hotelAddressesDestroyUrlTemplate' => route('hotel-addresses.destroy', ['hotelAddress' => '__ID__']),
        ]);
    }
}
