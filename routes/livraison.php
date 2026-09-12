<?php
// routes/livraison.php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes — Domaine Livraison
|--------------------------------------------------------------------------
|
| Extrait de routes/web.php le 10/09/2026 (Section E2 du refactor),
| contenu inchangé — campagnes/suivi contact/tableau de bord
| (role:gestionnaire), statistiques (role:benevole, cascade
| gestionnaire/admin), espace bénévole (disponibilité/ma route), équipes
| latérales pesée/réception/packaging/chargement, et le formulaire public
| de confirmation famille. Inclus depuis routes/web.php.
|
*/

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
    Route::get('/campagnes/{campagne}', [\App\Http\Controllers\Admin\Livraison\CampagnesController::class, 'show'])
        ->name('campagnes.show');
    Route::post('/campagnes', [\App\Http\Controllers\Admin\Livraison\CampagnesController::class, 'store'])
        ->name('campagnes.store');
    Route::post('/campagnes/{campagne}/journees', [\App\Http\Controllers\Admin\Livraison\CampagnesController::class, 'ajouterJournee'])
        ->name('campagnes.journees.store');
    Route::get('/campagnes/{campagne}/eligibles', [\App\Http\Controllers\Admin\Livraison\CampagnesController::class, 'eligibles'])
        ->name('campagnes.eligibles');
    Route::get('/campagnes/{campagne}/avancement', [\App\Http\Controllers\Admin\Livraison\CampagnesController::class, 'avancement'])
        ->name('campagnes.avancement');
    Route::post('/campagnes/{campagne}/generer-livraisons', [\App\Http\Controllers\Admin\Livraison\CampagnesController::class, 'genererLivraisons'])
        ->name('campagnes.generer-livraisons');
    Route::post('/campagnes/{campagne}/notifier-benevoles', [\App\Http\Controllers\Admin\Livraison\CampagnesController::class, 'notifierBenevoles'])
        ->name('campagnes.notifier-benevoles');
    // Édition (commentaire, HQ propre à la campagne) — voir le prompt du
    // 05/09/2026 §1.2/§1.3, éditable depuis la page détail elle-même
    // (pas de page d'édition séparée dans cette app).
    Route::patch('/campagnes/{campagne}', [\App\Http\Controllers\Admin\Livraison\CampagnesController::class, 'update'])
        ->name('campagnes.update');
    // Suppression (cascade DB — voir docblock de destroy()) + aperçu des
    // répercussions pour l'écran de confirmation (prompt du 08/09/2026
    // §2.1/§2.2).
    Route::get('/campagnes/{campagne}/resume-suppression', [\App\Http\Controllers\Admin\Livraison\CampagnesController::class, 'resumeSuppression'])
        ->name('campagnes.resume-suppression');
    Route::delete('/campagnes/{campagne}', [\App\Http\Controllers\Admin\Livraison\CampagnesController::class, 'destroy'])
        ->name('campagnes.destroy');
    // Poids moyen : mise à jour + historique (§5.2) et recalcul manuel,
    // volontairement scopé aux seules livraisons pas encore conditionnées
    // (voir CampagnesController::recalculerPoids()).
    Route::post('/campagnes/{campagne}/poids-moyen', [\App\Http\Controllers\Admin\Livraison\CampagnesController::class, 'mettreAJourPoidsMoyen'])
        ->name('campagnes.poids-moyen');
    Route::post('/campagnes/{campagne}/recalculer-poids', [\App\Http\Controllers\Admin\Livraison\CampagnesController::class, 'recalculerPoids'])
        ->name('campagnes.recalculer-poids');

    // Suivi des réponses de disponibilité bénévole (05/09/2026, prompt
    // §1.3) — remplace le bouton "Notifier bénévole" isolé par un vrai
    // écran de suivi, sur le modèle de Suivi des contacts.
    Route::get('/campagnes/{campagne}/benevoles', [\App\Http\Controllers\Admin\Livraison\BenevoleDisponibiliteController::class, 'index'])
        ->name('campagnes.benevoles.index');
    Route::get('/campagnes/{campagne}/benevoles/queue', [\App\Http\Controllers\Admin\Livraison\BenevoleDisponibiliteController::class, 'queue'])
        ->name('campagnes.benevoles.queue');
    Route::post('/campagnes/{campagne}/benevoles/{idPersonne}', [\App\Http\Controllers\Admin\Livraison\BenevoleDisponibiliteController::class, 'mettreAJour'])
        ->name('campagnes.benevoles.mettre-a-jour');

    // Affectations équipe_reception/pesee/packaging/chargement PAR
    // CAMPAGNE (08/09/2026, prompt de cette date) — voir
    // create_campagne_equipe_membres_table.php pour le raisonnement
    // complet et App\Policies\CampagnePolicy pour leur consommation en
    // autorisation. Écran dédié comme benevoles ci-dessus, pas un onglet.
    Route::get('/campagnes/{campagne}/equipes', [\App\Http\Controllers\Admin\Livraison\EquipeMembresController::class, 'index'])
        ->name('campagnes.equipes.index');
    Route::get('/campagnes/{campagne}/equipes/liste', [\App\Http\Controllers\Admin\Livraison\EquipeMembresController::class, 'liste'])
        ->name('campagnes.equipes.liste');
    Route::post('/campagnes/{campagne}/equipes', [\App\Http\Controllers\Admin\Livraison\EquipeMembresController::class, 'ajouter'])
        ->name('campagnes.equipes.ajouter');
    Route::delete('/campagnes/{campagne}/equipes/{idPersonne}/{role}', [\App\Http\Controllers\Admin\Livraison\EquipeMembresController::class, 'retirer'])
        ->name('campagnes.equipes.retirer');

    Route::get('/contacts', [\App\Http\Controllers\Admin\Livraison\ContactTrackingController::class, 'index'])
        ->name('contacts.index');
    Route::get('/contacts/file', [\App\Http\Controllers\Admin\Livraison\ContactTrackingController::class, 'queue'])
        ->name('contacts.queue');
    // Cartes statistiques (08/09/2026, prompt de cette date §3.2).
    Route::get('/contacts/statistiques', [\App\Http\Controllers\Admin\Livraison\ContactTrackingController::class, 'statistiques'])
        ->name('contacts.statistiques');
    Route::post('/contacts/{livraison}/assigner', [\App\Http\Controllers\Admin\Livraison\ContactTrackingController::class, 'assigner'])
        ->name('contacts.assigner');
    Route::post('/contacts/assigner-lot', [\App\Http\Controllers\Admin\Livraison\ContactTrackingController::class, 'assignerLot'])
        ->name('contacts.assigner-lot');
    Route::post('/contacts/{livraison}/contacter-manuel', [\App\Http\Controllers\Admin\Livraison\ContactTrackingController::class, 'contacterManuel'])
        ->name('contacts.contacter-manuel');

    // Renommé depuis 'tableau-de-bord' (07/09/2026, prompt §6) — voir
    // config/amana-shared.php. {campagne?} optionnel ajouté au même
    // moment : accédé depuis CampagneDetail.vue, on veut atterrir
    // directement sur la campagne choisie plutôt que de forcer un second
    // choix dans le <select> de LiveBoard.vue (le <select> reste malgré
    // tout affiché/utilisable pour changer de campagne ensuite ou pour
    // l'accès direct depuis la sidebar, sans campagne connue).
    Route::get('/suivi-livraison/{campagne?}', [\App\Http\Controllers\Admin\Livraison\LiveBoardController::class, 'index'])
        ->name('suivi-livraison.index');
    Route::post('/campagnes/{campagne}/generer-routes', [\App\Http\Controllers\Admin\Livraison\LiveBoardController::class, 'genererRoutes'])
        ->name('campagnes.generer-routes');
    Route::get('/campagnes/{campagne}/routes', [\App\Http\Controllers\Admin\Livraison\LiveBoardController::class, 'routes'])
        ->name('campagnes.routes');
    Route::get('/campagnes/{campagne}/non-couvertes', [\App\Http\Controllers\Admin\Livraison\LiveBoardController::class, 'nonCouvertes'])
        ->name('campagnes.non-couvertes');
    // Version tableau filtrable/paginée pour BuildRouteFlow.vue (09/09/2026,
    // prompt de cette date §5.1.3).
    Route::get('/campagnes/{campagne}/non-couvertes-tableau', [\App\Http\Controllers\Admin\Livraison\LiveBoardController::class, 'nonCouvertesTable'])
        ->name('campagnes.non-couvertes-tableau');
    // Cartes statistiques Suivi livraison (09/09/2026, prompt §5.2.4).
    Route::get('/campagnes/{campagne}/suivi-livraison-statistiques', [\App\Http\Controllers\Admin\Livraison\LiveBoardController::class, 'statistiques'])
        ->name('campagnes.suivi-livraison-statistiques');
    Route::get('/campagnes/{campagne}/incidents', [\App\Http\Controllers\Admin\Livraison\LiveBoardController::class, 'incidents'])
        ->name('campagnes.incidents');
    Route::post('/incidents/{incident}/resoudre', [\App\Http\Controllers\Admin\Livraison\LiveBoardController::class, 'resoudreIncident'])
        ->name('incidents.resoudre');
    Route::post('/routes/{route}/ajouter-livraison', [\App\Http\Controllers\Admin\Livraison\LiveBoardController::class, 'ajouterLivraison'])
        ->name('routes.ajouter-livraison');
    Route::delete('/routes/{route}/etapes/{etape}', [\App\Http\Controllers\Admin\Livraison\LiveBoardController::class, 'retirerLivraison'])
        ->name('routes.retirer-livraison');
    // Override manuel du statut d'un arrêt par un gestionnaire (09/09/2026,
    // prompt de cette date §5.2.3 : "User needs to be able to manually
    // change these in case driver does not").
    Route::post('/routes/{route}/etapes/{etape}/statut', [\App\Http\Controllers\Admin\Livraison\LiveBoardController::class, 'changerStatutEtape'])
        ->name('routes.etapes.statut');
    Route::post('/routes/{route}/reassigner', [\App\Http\Controllers\Admin\Livraison\LiveBoardController::class, 'reassignerRoute'])
        ->name('routes.reassigner');
    Route::post('/routes/{route}/diviser', [\App\Http\Controllers\Admin\Livraison\LiveBoardController::class, 'diviserRoute'])
        ->name('routes.diviser');
    Route::delete('/routes/{route}', [\App\Http\Controllers\Admin\Livraison\LiveBoardController::class, 'supprimerRoute'])
        ->name('routes.supprimer');
    Route::post('/campagnes/{campagne}/routes-personnalisees', [\App\Http\Controllers\Admin\Livraison\LiveBoardController::class, 'construireRoutePersonnalisee'])
        ->name('routes.personnalisee');

    // ── Pickers de recherche pour les écrans Vue (ajouté le 03/09/2026,
    //    voir App\Http\Controllers\Admin\Livraison\PickersController) —
    //    dans ce groupe role:gestionnaire plutôt que dans le groupe
    //    role:admin de admin.personnes.* : l'assignation de contact et la
    //    réassignation de tournée sont utilisables par un gestionnaire,
    //    pas seulement un admin. ────────────────────────────────────────
    Route::get('/personnes/recherche', [\App\Http\Controllers\Admin\Livraison\PickersController::class, 'personnes'])
        ->name('personnes.recherche');
});

// ── Statistiques campagne : admin/gestionnaire Full, benevole lecture
//    seule (voir matrice §4) — role:benevole cascade déjà depuis
//    gestionnaire/admin (voir Amana\Shared\Http\Middleware\EnsureRole),
//    donc un seul groupe couvre les trois. Le contrôleur distinguera
//    lecture/écriture en interne selon le rôle une fois la logique
//    écrite (Patch 5).
Route::middleware(['auth', 'role:benevole'])->prefix('livraison')->name('livraison.')->group(function () {
    // {campagne?} ajouté le 09/09/2026 (prompt de cette date §1.3) — voir
    // StatistiquesController::index().
    Route::get('/statistiques/{campagne?}', [\App\Http\Controllers\Admin\Livraison\StatistiquesController::class, 'index'])
        ->name('statistiques.index');
    Route::get('/statistiques/{campagne}/donnees', [\App\Http\Controllers\Admin\Livraison\StatistiquesController::class, 'donnees'])
        ->name('statistiques.donnees');
    Route::post('/statistiques/{campagne}/snapshot', [\App\Http\Controllers\Admin\Livraison\StatistiquesController::class, 'snapshot'])
        ->name('statistiques.snapshot');
});

// ── Bénévole (= chauffeur potentiel, benevole + BenevoleProfil — "chauffeur"
//    n'est pas un rôle séparé, voir prompt §4) : sa propre disponibilité,
//    sa propre tournée uniquement (admin/gestionnaire peuvent voir
//    n'importe laquelle via le tableau de bord ci-dessus, pas ici) ──────
Route::middleware(['auth', 'role:benevole'])->prefix('livraison/benevole')->name('livraison.benevole.')->group(function () {
    Route::get('/disponibilite/{campagne}', [\App\Http\Controllers\Livraison\DisponibiliteController::class, 'show'])
        ->name('disponibilite.show');
    Route::post('/disponibilite/{campagne}', [\App\Http\Controllers\Livraison\DisponibiliteController::class, 'update'])
        ->name('disponibilite.update');

    Route::get('/ma-route', [\App\Http\Controllers\Livraison\MaRouteController::class, 'show'])
        ->name('ma-route.show');
    Route::post('/etapes/{etape}/confirmer', [\App\Http\Controllers\Livraison\MaRouteController::class, 'confirmerEtape'])
        ->name('etapes.confirmer');
    Route::get('/etapes/{etape}/scan', [\App\Http\Controllers\Livraison\MaRouteController::class, 'confirmerScan'])
        ->name('etapes.scan');
    Route::post('/etapes/{etape}/ignoree', [\App\Http\Controllers\Livraison\MaRouteController::class, 'signalerIgnoree'])
        ->name('etapes.ignoree');
    Route::post('/routes/{route}/livraison-terminee', [\App\Http\Controllers\Livraison\MaRouteController::class, 'livraisonTerminee'])
        ->name('routes.livraison-terminee');
    Route::post('/routes/{route}/retour-qg', [\App\Http\Controllers\Livraison\MaRouteController::class, 'retourQg'])
        ->name('routes.retour-qg');
});

// ── Équipes latérales (équipe_reception/pesee/packaging/chargement) —
//    rôles propres à ce domaine, voir
//    2026_08_27_000000_register_familles_application.php.
//
//    Câblage revu le 08/09/2026 (prompt de cette date) : le rôle global
//    equipe_* (EnsureLivraisonRole) ne reste QUE sur choisir() — porte
//    d'entrée sans {campagne}, rien de plus fin à vérifier là (voir son
//    docblock et ReceptionController::choisir() et pendants, désormais
//    filtrés sur campagne_equipe_membres). Toutes les routes qui portent
//    une ressource rattachable à une campagne précise (directement ou
//    via CampagnePolicy/CampagneArriveePolicy/DonationPolicy/
//    LivraisonColisPolicy/LivraisonPolicy/RouteLivraisonPolicy) sont
//    passées à `can:` — Laravel résout {campagne}/{arrivee}/{don}/
//    {colis}/{livraison}/{route} par route-model binding implicite
//    (aucun binding custom dans ce fichier) puis appelle la policy dont
//    le nom est deviné depuis la classe du modèle
//    (Gate::guessPolicyName(), pas d'AuthServiceProvider dans cette app).
//
//    Middleware de groupe réduit à 'auth' en conséquence : chaque route
//    porte désormais explicitement son propre contrôle d'accès plutôt
//    qu'un rôle latéral unique hérité pour tout le groupe. ─────────────
// PeseeController et ReceptionController fusionnés en PosteReleveController
// le 10/09/2026 (Section A1 du refactor, voir App\Support\RelevePosteDefinition)
// — chaque groupe garde son préfixe/nom/rôle propre, `type` est passé en
// route default aux 4 actions communes (choisir/show/enregistrer/journal).
// modifier()/supprimer() restent typées par modèle ({arrivee}/{don}) donc
// n'ont pas besoin de ce default — voir le docblock du contrôleur.
Route::middleware('auth')->prefix('livraison/reception')->name('livraison.reception.')->group(function () {
    // choisir() ajouté le 05/09/2026 (prompt §4.1) : equipe_reception
    // n'avait aucune entrée de menu vers cet écran (voir
    // config/amana-shared.php) — ce point d'entrée sans {campagne} liste
    // les campagnes actives et sert de cible au lien de sidebar. Reste
    // sur le rôle global (porte d'entrée grossière) — voir
    // PosteReleveController::choisir() pour le filtrage fin par campagne.
    Route::get('/', [\App\Http\Controllers\Livraison\PosteReleveController::class, 'choisir'])
        ->defaults('type', 'reception')->middleware('livraison_role:equipe_reception')->name('choisir');
    Route::get('/{campagne}', [\App\Http\Controllers\Livraison\PosteReleveController::class, 'show'])
        ->defaults('type', 'reception')->middleware('can:equipeReception,campagne')->name('show');
    Route::post('/{campagne}', [\App\Http\Controllers\Livraison\PosteReleveController::class, 'enregistrer'])
        ->defaults('type', 'reception')->middleware('can:equipeReception,campagne')->name('enregistrer');
    // Journal des saisies (§4.2) : lister/modifier/supprimer chaque ligne
    // — pas de restriction de propriété (n'importe quel equipe_reception
    // peut éditer une ligne saisie par quelqu'un d'autre, voir le prompt).
    Route::get('/{campagne}/journal', [\App\Http\Controllers\Livraison\PosteReleveController::class, 'journal'])
        ->defaults('type', 'reception')->middleware('can:equipeReception,campagne')->name('journal');
    Route::patch('/arrivees/{arrivee}', [\App\Http\Controllers\Livraison\PosteReleveController::class, 'modifierArrivee'])
        ->middleware('can:gerer,arrivee')->name('arrivees.modifier');
    Route::delete('/arrivees/{arrivee}', [\App\Http\Controllers\Livraison\PosteReleveController::class, 'supprimerArrivee'])
        ->middleware('can:gerer,arrivee')->name('arrivees.supprimer');
});

Route::middleware('auth')->prefix('livraison/pesee')->name('livraison.pesee.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Livraison\PosteReleveController::class, 'choisir'])
        ->defaults('type', 'pesee')->middleware('livraison_role:equipe_pesee')->name('choisir');
    Route::get('/{campagne}', [\App\Http\Controllers\Livraison\PosteReleveController::class, 'show'])
        ->defaults('type', 'pesee')->middleware('can:equipePesee,campagne')->name('show');
    Route::post('/{campagne}', [\App\Http\Controllers\Livraison\PosteReleveController::class, 'enregistrer'])
        ->defaults('type', 'pesee')->middleware('can:equipePesee,campagne')->name('enregistrer');
    Route::get('/{campagne}/journal', [\App\Http\Controllers\Livraison\PosteReleveController::class, 'journal'])
        ->defaults('type', 'pesee')->middleware('can:equipePesee,campagne')->name('journal');
    Route::patch('/dons/{don}', [\App\Http\Controllers\Livraison\PosteReleveController::class, 'modifierDon'])
        ->middleware('can:gerer,don')->name('dons.modifier');
    Route::delete('/dons/{don}', [\App\Http\Controllers\Livraison\PosteReleveController::class, 'supprimerDon'])
        ->middleware('can:gerer,don')->name('dons.supprimer');
});

Route::middleware('auth')->prefix('livraison/packaging')->name('livraison.packaging.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Livraison\PackagingController::class, 'choisir'])
        ->middleware('livraison_role:equipe_packaging')->name('choisir');
    Route::get('/{campagne}', [\App\Http\Controllers\Livraison\PackagingController::class, 'index'])
        ->middleware('can:equipePackaging,campagne')->name('index');
    // marquer-pret (famille entière) conservé pour compatibilité mais plus
    // appelé directement par CampagneDetail/packaging.blade.php côté Vue
    // depuis le 05/09/2026 (prompt §5.3) — remplacé par colis/{colis}/statut,
    // finaliserConditionnement() étant désormais déclenché automatiquement
    // quand le dernier colis d'une famille passe à 'pret'.
    Route::post('/{livraison}/pret', [\App\Http\Controllers\Livraison\PackagingController::class, 'marquerPret'])
        ->middleware('can:gerer,livraison')->name('marquer-pret');
    Route::post('/colis/{colis}/statut', [\App\Http\Controllers\Livraison\PackagingController::class, 'marquerColisPret'])
        ->middleware('can:gerer,colis')->name('colis.statut');
    Route::post('/{livraison}/annuler', [\App\Http\Controllers\Livraison\PackagingController::class, 'annulerConditionnement'])
        ->middleware('can:gerer,livraison')->name('annuler');
    Route::get('/{campagne}/feuille-preparation', [\App\Http\Controllers\Livraison\PackagingController::class, 'feuillePreparation'])
        ->middleware('can:equipePackaging,campagne')->name('feuille-preparation');
});

Route::middleware('auth')->prefix('livraison/chargement')->name('livraison.chargement.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Livraison\ChargementController::class, 'choisir'])
        ->middleware('livraison_role:equipe_chargement')->name('choisir');
    Route::get('/{campagne}', [\App\Http\Controllers\Livraison\ChargementController::class, 'index'])
        ->middleware('can:equipeChargement,campagne')->name('index');
    Route::post('/routes/{route}/confirmer', [\App\Http\Controllers\Livraison\ChargementController::class, 'confirmer'])
        ->middleware('can:gerer,route')->name('confirmer');
    Route::post('/routes/{route}/benevole-absent', [\App\Http\Controllers\Livraison\ChargementController::class, 'signalerBenevoleAbsent'])
        ->middleware('can:gerer,route')->name('benevole-absent');
    Route::post('/routes/{route}/capacite', [\App\Http\Controllers\Livraison\ChargementController::class, 'signalerCapacite'])
        ->middleware('can:gerer,route')->name('capacite');
    // Feuille d'étiquettes QR pour TOUTES les familles confirmées de la
    // campagne, une planche unique à découper (07/09/2026, prompt §4.1) —
    // remplace le bouton d'étiquette par famille retiré de Packaging (§3.1,
    // qui n'a plus de raison d'être : ce besoin est couvert ici, en une
    // seule impression à l'échelle de la campagne plutôt que famille par
    // famille).
    Route::get('/{campagne}/etiquettes', [\App\Http\Controllers\Livraison\ChargementController::class, 'etiquettesCampagne'])
        ->middleware('can:equipeChargement,campagne')->name('etiquettes');
});

// ── Formulaire public de confirmation famille (aucune authentification) ──
// Accès scopé strictement par jeton (contact_tokens) — voir
// App\Http\Controllers\Livraison\ContactConfirmationController. Même
// schéma de throttle que les autres formulaires publics de l'app
// ci-dessus (intake/vérification/candidature bénévole). Contrairement à
// ces confirmations "en un clic", ce formulaire a un vrai second temps
// (saisie adresse/membres du foyer/créneaux) : GET affiche, POST traite
// la soumission (Patch 2).
Route::get('/livraison/confirmation/{token}', [\App\Http\Controllers\Livraison\ContactConfirmationController::class, 'show'])
    ->name('livraison.confirmation.show')
    ->middleware('throttle:20,1');
Route::post('/livraison/confirmation/{token}', [\App\Http\Controllers\Livraison\ContactConfirmationController::class, 'store'])
    ->name('livraison.confirmation.store')
    ->middleware('throttle:10,1');
