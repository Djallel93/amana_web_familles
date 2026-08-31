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

    // ── Organisations partenaires (ajouté le 28/08/2026) ─────────────────
    // Section de l'écran Paramètres (voir SettingsController::index() et
    // resources/views/settings/index.blade.php) — admin uniquement, voir
    // docblock de classe d'Admin\OrganisationsController.
    Route::post('/organisations', [\App\Http\Controllers\Admin\OrganisationsController::class, 'store'])->name('organisations.store');
    Route::put('/organisations/{organisation}', [\App\Http\Controllers\Admin\OrganisationsController::class, 'update'])->name('organisations.update');
    Route::delete('/organisations/{organisation}', [\App\Http\Controllers\Admin\OrganisationsController::class, 'destroy'])->name('organisations.destroy');
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

// ═══════════════════════════════════════════════════════════════════════
// Domaine LIVRAISON (Patch 1 — fondations, ajouté le 31/08/2026)
// ═══════════════════════════════════════════════════════════════════════
// Squelette de routes uniquement : chaque contrôleur ci-dessous renvoie
// pour l'instant resources/views/livraison/a-venir.blade.php — le but de
// ce patch est de valider que le groupage de rôles compile exactement
// selon la matrice de droits du prompt du 30/08/2026 §4, avant d'écrire
// la moindre logique métier (Patch 2 et suivants). Seules des routes GET
// sont câblées ici ; les routes d'action (POST/PUT/DELETE) arriveront
// avec la logique de chaque écran, patch par patch.

// ── Admin/gestionnaire : campagnes, sélection éligibilité, suivi contact,
//    tableau de bord live (accès "Full" sur ces lignes de la matrice) ────
Route::middleware(['auth', 'role:gestionnaire'])->prefix('livraison')->name('livraison.')->group(function () {
    Route::get('/campagnes', [\App\Http\Controllers\Admin\Livraison\CampagnesController::class, 'index'])
        ->name('campagnes.index');

    Route::get('/contacts', [\App\Http\Controllers\Admin\Livraison\ContactTrackingController::class, 'index'])
        ->name('contacts.index');

    Route::get('/tableau-de-bord', [\App\Http\Controllers\Admin\Livraison\LiveBoardController::class, 'index'])
        ->name('tableau-de-bord.index');
});

// ── Statistiques campagne : admin/gestionnaire Full, benevole lecture
//    seule (voir matrice §4) — role:benevole cascade déjà depuis
//    gestionnaire/admin (voir Amana\Shared\Http\Middleware\EnsureRole),
//    donc un seul groupe couvre les trois. Le contrôleur distinguera
//    lecture/écriture en interne selon le rôle une fois la logique
//    écrite (Patch 5).
Route::middleware(['auth', 'role:benevole'])->prefix('livraison')->name('livraison.')->group(function () {
    Route::get('/statistiques', [\App\Http\Controllers\Admin\Livraison\StatistiquesController::class, 'index'])
        ->name('statistiques.index');
});

// ── Bénévole (= chauffeur potentiel, benevole + BenevoleProfil — "chauffeur"
//    n'est pas un rôle séparé, voir prompt §4) : sa propre disponibilité,
//    sa propre tournée uniquement (admin/gestionnaire peuvent voir
//    n'importe laquelle via le tableau de bord ci-dessus, pas ici) ──────
Route::middleware(['auth', 'role:benevole'])->prefix('livraison/benevole')->name('livraison.benevole.')->group(function () {
    Route::get('/disponibilite', [\App\Http\Controllers\Livraison\DisponibiliteController::class, 'show'])
        ->name('disponibilite.show');

    Route::get('/ma-route', [\App\Http\Controllers\Livraison\MaRouteController::class, 'show'])
        ->name('ma-route.show');
});

// ── Équipes latérales (équipe_reception/pesee/packaging/chargement) —
//    rôles propres à ce domaine, voir
//    2026_08_31_000000_register_livraison_roles.php et
//    App\Http\Middleware\EnsureLivraisonRole. Chaque poste n'a accès qu'à
//    son propre écran (voir matrice §4 — aucune de ces lignes n'a
//    d'overlap entre elles en dehors d'admin/gestionnaire, déjà couverts
//    par le middleware). ──────────────────────────────────────────────
Route::middleware(['auth', 'livraison_role:equipe_reception'])->prefix('livraison/reception')->name('livraison.reception.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Livraison\ReceptionController::class, 'show'])->name('show');
});

Route::middleware(['auth', 'livraison_role:equipe_pesee'])->prefix('livraison/pesee')->name('livraison.pesee.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Livraison\PeseeController::class, 'show'])->name('show');
});

Route::middleware(['auth', 'livraison_role:equipe_packaging'])->prefix('livraison/packaging')->name('livraison.packaging.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Livraison\PackagingController::class, 'index'])->name('index');
});

Route::middleware(['auth', 'livraison_role:equipe_chargement'])->prefix('livraison/chargement')->name('livraison.chargement.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Livraison\ChargementController::class, 'index'])->name('index');
});

// ── Formulaire public de confirmation famille (aucune authentification) ──
// Accès scopé strictement par jeton (contact_tokens) — voir
// App\Http\Controllers\Livraison\ContactConfirmationController. Même
// schéma de throttle que les autres formulaires publics de l'app
// ci-dessus (intake/vérification/candidature bénévole). Contrairement à
// ces confirmations "en un clic", ce formulaire a un vrai second temps
// (saisie adresse/membres du foyer/créneaux) : la route POST arrivera
// avec cette logique, en Patch 2.
Route::get('/livraison/confirmation/{token}', [\App\Http\Controllers\Livraison\ContactConfirmationController::class, 'show'])
    ->name('livraison.confirmation.show')
    ->middleware('throttle:20,1');
