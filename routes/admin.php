<?php
// routes/admin.php

declare(strict_types=1);

use App\Http\Controllers\Admin\PersonnesController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes — Administration
|--------------------------------------------------------------------------
|
| Extrait de routes/web.php le 10/09/2026 (Section E2 du refactor) :
| gestion du staff (personnes/imports/vérifications/candidatures
| bénévoles/organisations, role:admin), plus les écrans de configuration
| partagés admin+gestionnaire (réglages, véhicules, adresses hôtel,
| rattachements d'organisation). Inclus depuis routes/web.php.
|
*/

// ── Administration (admin uniquement) ────────────────────────────────────
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/personnes', [PersonnesController::class, 'index'])->name('personnes.index');
    Route::get('/personnes/creer', [PersonnesController::class, 'create'])->name('personnes.create');
    Route::post('/personnes', [PersonnesController::class, 'store'])->name('personnes.store');
    Route::get('/personnes/{id}/modifier', [PersonnesController::class, 'edit'])->name('personnes.edit');
    Route::put('/personnes/{id}', [PersonnesController::class, 'update'])->name('personnes.update');
    Route::delete('/personnes/{id}', [PersonnesController::class, 'destroy'])->name('personnes.destroy');

    // ── Import/mise à jour en masse (décision 6.9) ───────────────────────
    Route::get('/imports', [\App\Http\Controllers\Admin\ImportsController::class, 'index'])->name('imports.index');
    Route::get('/imports/creer', [\App\Http\Controllers\Admin\ImportsController::class, 'create'])->name('imports.create');
    Route::post('/imports/csv', [\App\Http\Controllers\Admin\ImportsController::class, 'storeCsv'])->name('imports.store-csv');
    Route::post('/imports/manuel', [\App\Http\Controllers\Admin\ImportsController::class, 'storeManuel'])->name('imports.store-manuel');
    Route::get('/imports/{id}', [\App\Http\Controllers\Admin\ImportsController::class, 'show'])->name('imports.show');
    Route::post('/imports/{id}/annuler', [\App\Http\Controllers\Admin\ImportsController::class, 'rollback'])->name('imports.rollback');
    Route::post('/imports/{id}/synchroniser-google-contacts', [\App\Http\Controllers\Admin\ImportsController::class, 'syncGoogleContacts'])->name('imports.sync-google-contacts');
    Route::post('/imports/{id}/lignes/{rowId}/synchroniser-google-contacts', [\App\Http\Controllers\Admin\ImportsController::class, 'syncGoogleContactsRow'])->name('imports.rows.sync-google-contacts');

    // ── Statistiques d'activité (mesure l'usage de l'app elle-même) ─────
    Route::prefix('activite')->name('activite.')->group(function () {
        Route::get('/', [\Amana\Shared\Http\Controllers\ActivityStatsController::class, 'index'])->name('index');
        Route::get('/data', [\Amana\Shared\Http\Controllers\ActivityStatsController::class, 'data'])->name('data');
    });

    // ── Journal d'audit (amana/shared — pas encore d'équivalent local,
    //    contrairement à activite/ ci-dessus qui existait déjà) ──────────
    Route::prefix('journal')->name('journal.')->group(function () {
        Route::get('/', [\Amana\Shared\Http\Controllers\AuditLogController::class, 'index'])->name('index');
        Route::get('/data', [\Amana\Shared\Http\Controllers\AuditLogController::class, 'data'])->name('data');
    });

    // ── Vérification périodique des informations (décision 6.10) ────────
    Route::get('/verifications', [\App\Http\Controllers\Admin\VerificationsController::class, 'index'])->name('verifications.index');
    Route::post('/verifications/envoyer', [\App\Http\Controllers\Admin\VerificationsController::class, 'envoyer'])->name('verifications.envoyer');

    // ── Candidatures bénévoles (ajouté le 24/08/2026) ────────────────────
    Route::get('/benevoles', [\App\Http\Controllers\Admin\BenevoleCandidaturesController::class, 'index'])->name('benevoles.index');
    Route::get('/benevoles/{id}', [\App\Http\Controllers\Admin\BenevoleCandidaturesController::class, 'show'])->whereNumber('id')->name('benevoles.show');
    Route::post('/benevoles/{id}/valider', [\App\Http\Controllers\Admin\BenevoleCandidaturesController::class, 'valider'])->whereNumber('id')->name('benevoles.valider');
    Route::post('/benevoles/{id}/rejeter', [\App\Http\Controllers\Admin\BenevoleCandidaturesController::class, 'rejeter'])->whereNumber('id')->name('benevoles.rejeter');

    // ── Autorisation OAuth Google Contacts (décision du 17/07/2026) ─────
    // Usage ponctuel (autorisation initiale du compte amana44.pole.social@
    // gmail.com, ou ré-autorisation si le refresh token est révoqué) — voir
    // Admin\GoogleContactsController et App\Services\GoogleContactsService.
    Route::get('/google-contacts/authorize', [\App\Http\Controllers\Admin\GoogleContactsController::class, 'redirect'])->name('google-contacts.authorize');
    Route::get('/google-contacts/callback', [\App\Http\Controllers\Admin\GoogleContactsController::class, 'callback'])->name('google-contacts.callback');

    // ── Organisations partenaires (ajouté le 28/08/2026) ─────────────────
    // Section de l'écran Paramètres (voir SettingsController::index() et
    // resources/views/settings/index.blade.php) — admin uniquement, voir
    // docblock de classe d'Admin\OrganisationsController.
    Route::post('/organisations', [\App\Http\Controllers\Admin\OrganisationsController::class, 'store'])->name('organisations.store');
    Route::put('/organisations/{organisation}', [\App\Http\Controllers\Admin\OrganisationsController::class, 'update'])->name('organisations.update');
    Route::delete('/organisations/{organisation}', [\App\Http\Controllers\Admin\OrganisationsController::class, 'destroy'])->name('organisations.destroy');
});

Route::middleware(['auth', 'role:gestionnaire'])->group(function () {
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');

    // ── Référentiel des types de véhicule (ajouté le 24/08/2026) ─────────
    // Même groupe que /settings : admin ET gestionnaire doivent pouvoir
    // éditer les capacités, contrairement au groupe /admin (role:admin
    // uniquement) où vivent les candidatures bénévoles elles-mêmes.
    // Pas de route GET dédiée : le formulaire vit dans /settings (voir
    // SettingsController::index() et resources/views/settings/index.blade.php).
    Route::post('/vehicules', [\App\Http\Controllers\VehiculeTypesController::class, 'update'])->name('vehicules.update');

    // ── Référentiel des adresses hôtel (ajouté le 30/08/2026) ─────────────
    // Même groupe que /vehicules ci-dessus (admin + gestionnaire) — voir
    // docblock de classe d'Admin\HotelAddressesController. Formulaire dans
    // /settings, pas de route GET dédiée non plus.
    Route::post('/hotel-addresses', [\App\Http\Controllers\Admin\HotelAddressesController::class, 'store'])->name('hotel-addresses.store');
    Route::put('/hotel-addresses/{hotelAddress}', [\App\Http\Controllers\Admin\HotelAddressesController::class, 'update'])->name('hotel-addresses.update');
    Route::delete('/hotel-addresses/{hotelAddress}', [\App\Http\Controllers\Admin\HotelAddressesController::class, 'destroy'])->name('hotel-addresses.destroy');
});

// ── Rattachements d'organisation en attente (ajouté le 28/08/2026) ──────
// Écran de revue admin/gestionnaire (staff interne, jamais un
// gestionnaire_externe même de l'organisation déjà rattachée — voir
// échange du 28/08/2026) — décide si une organisation B obtient l'accès à
// un dossier déjà rattaché à une organisation A. Même groupe de rôle que
// /settings ci-dessus (admin + gestionnaire), pas /admin (role:admin
// uniquement dans cette app).
Route::middleware(['auth', 'role:gestionnaire'])->prefix('rattachements')->name('rattachements.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\RattachementsController::class, 'index'])->name('index');
    Route::post('/{demande}/valider', [\App\Http\Controllers\Admin\RattachementsController::class, 'valider'])->whereNumber('demande')->name('valider');
    Route::post('/{demande}/rejeter', [\App\Http\Controllers\Admin\RattachementsController::class, 'rejeter'])->whereNumber('demande')->name('rejeter');
});
