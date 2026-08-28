<?php
// app/Http/Controllers/SettingsController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use Amana\Shared\Http\Controllers\SettingsControllerBase;
use Amana\Shared\Models\Setting;
use Amana\Shared\Models\VehiculeType;
use App\Models\Organisation;
use Illuminate\View\View;

/**
 * Surcharge index() pour intégrer la section "Types de véhicule" à la
 * même page que les réglages génériques — demande du 26/08/2026 : ne pas
 * en faire une page/entrée de menu séparée (voir VehiculeTypesController,
 * dont seul update() reste routé désormais, index() n'existant plus).
 * Vue propre à l'app (resources/views/settings/index.blade.php), pas la
 * vue générique du package — voir SettingsControllerBase pour ce schéma
 * de surcharge.
 */
class SettingsController extends SettingsControllerBase
{
    protected function appCode(): string
    {
        return 'familles';
    }

    public function index(): View
    {
        return view('settings.index', [
            'settings' => Setting::allForApp($this->appCode()),
            'vehicules' => VehiculeType::orderBy('id')->get(),
            'organisations' => Organisation::orderByDesc('est_principale')->orderBy('nom')->get(),
        ]);
    }
}
