<?php
// routes/web.php

declare(strict_types=1);

use Amana\Shared\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\PersonnesController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SettingsController;

/*
|--------------------------------------------------------------------------
| Routes web — AMANA Familles
|--------------------------------------------------------------------------
|
| Nommage cohérent avec amana_web_planning : login / logout /
| password.request / password.reset (AuthController), familles.index
| (vue principale, protégée par 'auth'), admin.personnes.* (protégée par
| 'role:admin').
|
| Étape actuelle : auth SSO + layout + gestion du staff. La vue dossiers
| (filtres, tableau, panneau de détail) reste à construire — voir
| resources/views/familles/index.blade.php.
|
*/

// ── Authentification (SSO partagé via ref_personnes) ────────────────────
// Limites de débit alignées sur amana_web_planning\routes\web.php.
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])
    ->name('login.submit')
    ->middleware('throttle:10,1');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/mot-de-passe-oublie', [AuthController::class, 'showForgotPassword'])->name('password.request');
Route::post('/mot-de-passe-oublie', [AuthController::class, 'sendResetLink'])
    ->name('password.email')
    ->middleware('throttle:5,1');
Route::get('/reinitialiser-mot-de-passe/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
Route::post('/reinitialiser-mot-de-passe', [AuthController::class, 'resetPassword'])
    ->name('password.update')
    ->middleware('throttle:10,1');

// ── Formulaire public d'intake (aucune authentification — familles) ─────
// throttle:5,1 sur /demande (store) + piège à robots (champ site_web,
// silencieusement ignoré côté IntakeController::store) : deux couches
// complémentaires contre le spam/bots, demande du 09/08/2026.
Route::get('/demande/{langue?}', [\App\Http\Controllers\IntakeController::class, 'showForm'])
    ->name('intake.show')
    ->middleware('throttle:20,1');
Route::post('/demande', [\App\Http\Controllers\IntakeController::class, 'store'])
    ->name('intake.store')
    ->middleware('throttle:5,1');
// Étape 0 du formulaire (consentement RGPD) — appelée seule quand la famille
// refuse, pour journaliser le refus sans jamais collecter le reste du
// formulaire (voir IntakeConsentRefusal / section "Refus" du Google Form).
Route::post('/demande/refus-consentement', [\App\Http\Controllers\IntakeController::class, 'refuserConsentement'])
    ->name('intake.refus-consentement')
    ->middleware('throttle:10,1');

// Confirmation par email d'une demande en attente (voir IntakeAttenteService /
// IntakeDemandeAttente, ajout du 11/08/2026) — même forme que les routes de
// vérification ci-dessous.
Route::get('/demande/confirmer/{token}', [\App\Http\Controllers\IntakeConfirmationController::class, 'show'])
    ->name('intake.confirmer.show')
    ->middleware('throttle:20,1');
Route::post('/demande/confirmer/{token}', [\App\Http\Controllers\IntakeConfirmationController::class, 'confirmer'])
    ->name('intake.confirmer')
    ->middleware('throttle:5,1');

// ── Vérification publique des informations (lien reçu par email) ────────
Route::get('/verification/{token}', [\App\Http\Controllers\VerificationController::class, 'show'])
    ->name('verification.show')
    ->middleware('throttle:20,1');
Route::post('/verification/{token}/confirmer', [\App\Http\Controllers\VerificationController::class, 'confirmer'])
    ->name('verification.confirmer')
    ->middleware('throttle:5,1');

// ── Formulaire public de candidature bénévole (aucune authentification) ──
// Même schéma de throttle/piège à robots que l'intake familles ci-dessus.
// Ajouté le 24/08/2026 — voir BenevoleIntakeController et le prompt de
// migration du module bénévoles.
Route::get('/devenir-benevole/{langue?}', [\App\Http\Controllers\BenevoleIntakeController::class, 'showForm'])
    ->name('benevole.show')
    ->middleware('throttle:20,1');
Route::post('/devenir-benevole', [\App\Http\Controllers\BenevoleIntakeController::class, 'store'])
    ->name('benevole.store')
    ->middleware('throttle:5,1');
Route::post('/devenir-benevole/refus-consentement', [\App\Http\Controllers\BenevoleIntakeController::class, 'refuserConsentement'])
    ->name('benevole.refus-consentement')
    ->middleware('throttle:10,1');
Route::get('/devenir-benevole/confirmer/{token}', [\App\Http\Controllers\BenevoleIntakeConfirmationController::class, 'show'])
    ->name('benevole.confirmer.show')
    ->middleware('throttle:20,1');
Route::post('/devenir-benevole/confirmer/{token}', [\App\Http\Controllers\BenevoleIntakeConfirmationController::class, 'confirmer'])
    ->name('benevole.confirmer')
    ->middleware('throttle:5,1');

// ── Dossiers familles (staff — tous rôles) ───────────────────────────────
Route::middleware('auth')->group(function () {
    Route::get('/', [\App\Http\Controllers\FamillesController::class, 'index'])->name('familles.index');
    Route::get('/nouvelles', [\App\Http\Controllers\FamillesController::class, 'nouvelles'])->name('familles.nouvelles');
    // Placée avant /familles/{id} par convention (whereNumber la protège déjà
    // d'une collision, ce chemin littéral ne matchant pas \d+, mais autant
    // garder les routes explicites avant le wildcard).
    Route::get('/familles/recherche-suggestions', [\App\Http\Controllers\FamillesController::class, 'rechercheSuggestions'])->name('familles.recherche-suggestions');
    Route::get('/familles/export', [\App\Http\Controllers\FamillesController::class, 'export'])->name('familles.export');
    Route::get('/familles/{id}', [\App\Http\Controllers\FamillesController::class, 'show'])->whereNumber('id')->name('familles.show');
    Route::put('/familles/{id}', [\App\Http\Controllers\FamillesController::class, 'update'])->whereNumber('id')->name('familles.update');
    // Verrouillage d'édition (décision du 15/08/2026) — relâche le verrou
    // pris par show() sans enregistrer, voir FamillesController::deverrouiller()
    // et DetailPanel.vue (fermeture du panneau sans sauvegarde).
    Route::post('/familles/{id}/deverrouiller', [\App\Http\Controllers\FamillesController::class, 'deverrouiller'])->whereNumber('id')->name('familles.deverrouiller');
    Route::post('/familles/{id}/documents', [\App\Http\Controllers\FamillesController::class, 'uploadDocument'])->whereNumber('id')->name('familles.documents.store');
    Route::get('/familles/{id}/documents/{documentId}', [\App\Http\Controllers\FamillesController::class, 'downloadDocument'])->whereNumber('id')->whereNumber('documentId')->name('familles.documents.download');
    Route::delete('/familles/{id}/documents/{documentId}', [\App\Http\Controllers\FamillesController::class, 'destroyDocument'])->whereNumber('id')->whereNumber('documentId')->name('familles.documents.destroy');
});

// Déverrouillage forcé d'un dossier (décision du 15/08/2026) — réservé
// admin, "easy out" si un verrou d'édition reste bloqué. Séparé du groupe
// 'auth' ci-dessus (qui couvre le CRUD dossier normal, accessible à tout
// utilisateur authentifié) pour appliquer role:admin uniquement à cette
// route — voir FamillesController::forcerDeverrouillage().
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::post('/familles/{id}/forcer-deverrouillage', [\App\Http\Controllers\FamillesController::class, 'forcerDeverrouillage'])->whereNumber('id')->name('familles.forcer-deverrouillage');
});

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
});

// ── Statistiques dossiers familles (admin + gestionnaire) ────────────────
Route::middleware(['auth', 'role:gestionnaire'])->prefix('familles')->name('familles.')->group(function () {
    Route::get('/statistiques', [\App\Http\Controllers\Admin\StatistiquesFamillesController::class, 'index'])->name('statistiques.index');
    Route::get('/statistiques/data', [\App\Http\Controllers\Admin\StatistiquesFamillesController::class, 'data'])->name('statistiques.data');

    // ── Sync retour Google Contacts → Dossier (décision du 14/08/2026) ──
    // Bouton dédié dans familles/index.blade.php — voir
    // App\Http\Controllers\GoogleContactsReverseSyncController et
    // resources/js/components/familles/ReverseSyncPanel.vue.
    Route::get('/google-contacts/scan', [\App\Http\Controllers\GoogleContactsReverseSyncController::class, 'scan'])->name('google-contacts.scan');
    Route::post('/google-contacts/appliquer', [\App\Http\Controllers\GoogleContactsReverseSyncController::class, 'apply'])->name('google-contacts.appliquer');
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
});