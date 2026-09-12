<?php
// routes/familles.php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes — Domaine Familles
|--------------------------------------------------------------------------
|
| Extrait de routes/web.php le 10/09/2026 (Section E2 du refactor) :
| formulaires publics (intake famille/bénévole, vérification), le CRUD du
| dossier famille lui-même (staff, tous rôles), ses statistiques, et
| l'auto-service d'import pour un gestionnaire_externe. Inclus depuis
| routes/web.php — voir ce fichier pour l'ordre d'inclusion et les routes
| restées communes (auth/login).
|
*/

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
// Confirmation en un clic (30/08/2026) : show() confirme directement, pas
// de route POST séparée pour un second temps de confirmation (même schéma
// que VerificationController ci-dessous).
Route::get('/demande/confirmer/{token}', [\App\Http\Controllers\IntakeConfirmationController::class, 'show'])
    ->name('intake.confirmer.show')
    ->middleware('throttle:20,1');

// ── Vérification publique des informations (lien reçu par email) ────────
// Confirmation en un clic : show() confirme directement, pas de route
// séparée pour un second temps de confirmation (voir VerificationController).
Route::get('/verification/{token}', [\App\Http\Controllers\VerificationController::class, 'show'])
    ->name('verification.show')
    ->middleware('throttle:20,1');

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
// Confirmation en un clic (30/08/2026) : voir commentaire équivalent sur
// les routes /demande/confirmer ci-dessus.
Route::get('/devenir-benevole/confirmer/{token}', [\App\Http\Controllers\BenevoleIntakeConfirmationController::class, 'show'])
    ->name('benevole.confirmer.show')
    ->middleware('throttle:20,1');

// ── Référentiel véhicules en lecture (ajouté le 03/09/2026) — public et
//    sans rôle : sert BenevoleForm.vue (candidature bénévole, page
//    ci-dessus, non authentifiée) et les pickers véhicule des écrans
//    livraison authentifiés. N'expose rien de plus que ce que
//    data-vehicules exposait déjà en clair sur /devenir-benevole avant
//    cet ajout (capacite_kg/nombre_part_max) — voir VehiculeTypesController::index().
//    Écriture (update) reste réservée au groupe role:gestionnaire
//    ci-dessous, inchangée.
Route::get('/vehicules', [\App\Http\Controllers\VehiculeTypesController::class, 'index'])
    ->name('vehicules.index')
    ->middleware('throttle:60,1');

// ── Dossiers familles (staff — tous rôles) ───────────────────────────────
Route::middleware('auth')->group(function () {
    // Centre de notifications partagé (voir le prompt du 03/09/2026,
    // Amana\Shared\Http\Controllers\NotificationsController) — accessible
    // à toute personne connectée, pas restreint à admin/gestionnaire : le
    // contenu réel (urgent vs info, qui reçoit quoi) est déjà filtré côté
    // service par destinataire (voir RouteIncident::booted() et
    // PackagingController::marquerPret()), cette route ne fait qu'exposer
    // "mes notifications à moi".
    Route::get('/notifications', [\Amana\Shared\Http\Controllers\NotificationsController::class, 'index'])
        ->name('notifications.index');
    Route::post('/notifications/{id}/lue', [\Amana\Shared\Http\Controllers\NotificationsController::class, 'marquerLue'])
        ->name('notifications.marquer-lue');

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

// ── Ajout/import de familles par un gestionnaire externe (ajouté le
//    28/08/2026) — réutilise Admin\ImportsController (même pipeline que le
//    staff interne, voir décision 6.9), route séparée avec son propre
//    gate de rôle : gestionnaire_externe n'a PAS accès au groupe /admin
//    (role:admin) où vit normalement ce contrôleur. Organisation forcée
//    à celle de l'auteur — voir ImportsController::resoudreIdOrganisation().
Route::middleware(['auth', 'role:gestionnaire_externe'])->prefix('mes-imports')->name('externe.imports.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\ImportsController::class, 'index'])->name('index');
    Route::get('/creer', [\App\Http\Controllers\Admin\ImportsController::class, 'create'])->name('create');
    Route::post('/csv', [\App\Http\Controllers\Admin\ImportsController::class, 'storeCsv'])->name('store-csv');
    Route::post('/manuel', [\App\Http\Controllers\Admin\ImportsController::class, 'storeManuel'])->name('store-manuel');
    Route::get('/{id}', [\App\Http\Controllers\Admin\ImportsController::class, 'show'])->name('show');
});
