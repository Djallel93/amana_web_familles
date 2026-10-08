<?php
// routes/livraison.php

declare(strict_types=1);

use App\Http\Controllers\Admin\Livraison\BenevoleDisponibiliteController;
use App\Http\Controllers\Admin\Livraison\CampagnesController;
use App\Http\Controllers\Admin\Livraison\ContactTrackingController;
use App\Http\Controllers\Admin\Livraison\EquipeMembresController;
use App\Http\Controllers\Admin\Livraison\IncidentsController;
use App\Http\Controllers\Admin\Livraison\LiveBoardController;
use App\Http\Controllers\Admin\Livraison\PickersController;
use App\Http\Controllers\Admin\Livraison\StatistiquesController;
use App\Http\Controllers\Livraison\ChargementController;
use App\Http\Controllers\Livraison\ContactConfirmationController;
use App\Http\Controllers\Livraison\DisponibiliteController;
use App\Http\Controllers\Livraison\MaRouteController;
use App\Http\Controllers\Livraison\PackagingController;
use App\Http\Controllers\Livraison\PosteReleveController;
use App\Http\Controllers\Livraison\RetraitHqController;
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
    Route::get('/campagnes', [CampagnesController::class, 'index'])
        ->name('campagnes.index');
    // Page de création (03/10/2026) — AVANT /campagnes/{campagne}, sinon
    // « creer » serait interprété comme un id de campagne.
    Route::get('/campagnes/creer', [CampagnesController::class, 'creer'])
        ->name('campagnes.creer');
    Route::get('/campagnes/{campagne}', [CampagnesController::class, 'show'])
        ->name('campagnes.show');
    // Hub de la campagne (03/10/2026) : paramètres, clôture, incidents.
    Route::get('/campagnes/{campagne}/parametres', [CampagnesController::class, 'parametres'])
        ->name('campagnes.parametres');
    Route::get('/campagnes/{campagne}/cloture', [CampagnesController::class, 'cloture'])
        ->name('campagnes.cloture');
    Route::post('/campagnes/{campagne}/terminer', [CampagnesController::class, 'terminer'])
        ->name('campagnes.terminer');
    Route::post('/campagnes/{campagne}/rouvrir', [CampagnesController::class, 'rouvrir'])
        ->name('campagnes.rouvrir');
    Route::post('/campagnes/{campagne}/incidents/forcer-resolution', [CampagnesController::class, 'forcerResolutionIncidents'])
        ->name('campagnes.incidents.forcer-resolution');
    // Liste complète des incidents de la campagne (section repliable du hub,
    // 06/10/2026 — plus de page dédiée). L'URL /campagnes/{campagne}/incidents
    // existe déjà : c'est l'endpoint JSON des incidents OUVERTS de Suivi livraison.
    Route::get('/campagnes/{campagne}/incidents/liste', [IncidentsController::class, 'liste'])
        ->name('campagnes.incidents-liste');
    // « Démarrer la campagne » (06/10/2026) — voir CampagneDemarrageService.
    Route::post('/campagnes/{campagne}/demarrer', [CampagnesController::class, 'demarrer'])
        ->name('campagnes.demarrer');
    // Sélection des familles éligibles : page dédiée, campagne optionnelle
    // (barre latérale), même patron que suivi-livraison/{campagne?}.
    Route::get('/familles-eligibles/{campagne?}', [CampagnesController::class, 'familles'])
        ->name('familles-eligibles.index');
    // Stats de la ligne dépliée de la liste (03/10/2026).
    Route::get('/campagnes/{campagne}/apercu', [CampagnesController::class, 'apercu'])
        ->name('campagnes.apercu');
    Route::post('/campagnes', [CampagnesController::class, 'store'])
        ->name('campagnes.store');
    Route::post('/campagnes/{campagne}/journees', [CampagnesController::class, 'ajouterJournee'])
        ->name('campagnes.journees.store');
    Route::get('/campagnes/{campagne}/eligibles', [CampagnesController::class, 'eligibles'])
        ->name('campagnes.eligibles');
    Route::get('/campagnes/{campagne}/avancement', [CampagnesController::class, 'avancement'])
        ->name('campagnes.avancement');
    Route::post('/campagnes/{campagne}/generer-livraisons', [CampagnesController::class, 'genererLivraisons'])
        ->name('campagnes.generer-livraisons');
    Route::post('/campagnes/{campagne}/notifier-benevoles', [CampagnesController::class, 'notifierBenevoles'])
        ->name('campagnes.notifier-benevoles');
    // Édition (commentaire, HQ propre à la campagne) — voir le prompt du
    // 05/09/2026 §1.2/§1.3, éditable depuis la page détail elle-même
    // (pas de page d'édition séparée dans cette app).
    Route::patch('/campagnes/{campagne}', [CampagnesController::class, 'update'])
        ->name('campagnes.update');
    // Suppression (cascade DB — voir docblock de destroy()) + aperçu des
    // répercussions pour l'écran de confirmation (prompt du 08/09/2026
    // §2.1/§2.2).
    Route::get('/campagnes/{campagne}/resume-suppression', [CampagnesController::class, 'resumeSuppression'])
        ->name('campagnes.resume-suppression');
    Route::delete('/campagnes/{campagne}', [CampagnesController::class, 'destroy'])
        ->name('campagnes.destroy');
    // Poids moyen : mise à jour + historique (§5.2) et recalcul manuel,
    // volontairement scopé aux seules livraisons pas encore conditionnées
    // (voir CampagnesController::recalculerPoids()).
    Route::post('/campagnes/{campagne}/poids-moyen', [CampagnesController::class, 'mettreAJourPoidsMoyen'])
        ->name('campagnes.poids-moyen');
    Route::post('/campagnes/{campagne}/recalculer-poids', [CampagnesController::class, 'recalculerPoids'])
        ->name('campagnes.recalculer-poids');

    // Suivi des réponses de disponibilité bénévole (05/09/2026, prompt
    // §1.3) — remplace le bouton "Notifier bénévole" isolé par un vrai
    // écran de suivi, sur le modèle de Suivi des contacts.
    Route::get('/campagnes/{campagne}/benevoles', [BenevoleDisponibiliteController::class, 'index'])
        ->name('campagnes.benevoles.index');
    Route::get('/campagnes/{campagne}/benevoles/queue', [BenevoleDisponibiliteController::class, 'queue'])
        ->name('campagnes.benevoles.queue');
    Route::post('/campagnes/{campagne}/benevoles/{idPersonne}', [BenevoleDisponibiliteController::class, 'mettreAJour'])
        ->name('campagnes.benevoles.mettre-a-jour');

    // Affectations équipe_reception/pesee/packaging/chargement PAR
    // CAMPAGNE (08/09/2026, prompt de cette date) — voir
    // create_campagne_equipe_membres_table.php pour le raisonnement
    // complet et App\Policies\CampagnePolicy pour leur consommation en
    // autorisation. Écran dédié comme benevoles ci-dessus, pas un onglet.
    // 16/09/2026 (Section E4) : l'écran est passé à Inertia, la liste des
    // affectations arrive en prop de page — l'ancienne route
    // `campagnes.equipes.liste` (endpoint JSON consommé uniquement par
    // EquipeMembresQueue.vue) est supprimée avec son action.
    Route::get('/campagnes/{campagne}/equipes', [EquipeMembresController::class, 'index'])
        ->name('campagnes.equipes.index');
    Route::post('/campagnes/{campagne}/equipes', [EquipeMembresController::class, 'ajouter'])
        ->name('campagnes.equipes.ajouter');
    Route::delete('/campagnes/{campagne}/equipes/{idPersonne}/{role}', [EquipeMembresController::class, 'retirer'])
        ->name('campagnes.equipes.retirer');

    Route::get('/contacts', [ContactTrackingController::class, 'index'])
        ->name('contacts.index');
    Route::get('/contacts/file', [ContactTrackingController::class, 'queue'])
        ->name('contacts.queue');
    // Cartes statistiques (08/09/2026, prompt de cette date §3.2).
    Route::get('/contacts/statistiques', [ContactTrackingController::class, 'statistiques'])
        ->name('contacts.statistiques');
    Route::post('/contacts/{livraison}/assigner', [ContactTrackingController::class, 'assigner'])
        ->name('contacts.assigner');
    Route::post('/contacts/assigner-lot', [ContactTrackingController::class, 'assignerLot'])
        ->name('contacts.assigner-lot');
    Route::post('/contacts/{livraison}/contacter-manuel', [ContactTrackingController::class, 'contacterManuel'])
        ->name('contacts.contacter-manuel');
    // Exception journalière se_deplace (24/09/2026, prompt de cette date
    // §5) — admin/gestionnaire uniquement, même groupe role:gestionnaire
    // que tout ce fichier (voir son ouverture) : pas de middleware
    // supplémentaire nécessaire ici.
    Route::post('/contacts/{livraison}/se-deplace', [ContactTrackingController::class, 'mettreAJourSeDeplace'])
        ->name('contacts.se-deplace');
    // « Prendre en charge » (06/10/2026) : chauffeur imposé, voir
    // ContactTrackingController::prendreEnCharge().
    Route::post('/contacts/{livraison}/prise-en-charge', [ContactTrackingController::class, 'prendreEnCharge'])
        ->name('contacts.prise-en-charge');

    // Renommé depuis 'tableau-de-bord' (07/09/2026, prompt §6) — voir
    // config/amana-shared.php. {campagne?} optionnel ajouté au même
    // moment : accédé depuis CampagneDetail.vue, on veut atterrir
    // directement sur la campagne choisie plutôt que de forcer un second
    // choix dans le <select> de LiveBoard.vue (le <select> reste malgré
    // tout affiché/utilisable pour changer de campagne ensuite ou pour
    // l'accès direct depuis la sidebar, sans campagne connue).
    Route::get('/suivi-livraison/{campagne?}', [LiveBoardController::class, 'index'])
        ->name('suivi-livraison.index');
    Route::post('/campagnes/{campagne}/generer-routes', [LiveBoardController::class, 'genererRoutes'])
        ->name('campagnes.generer-routes');
    // Étapes de l'assistant « Génération des routes » du hub (06/10/2026).
    Route::get('/campagnes/{campagne}/chauffeurs-disponibles', [LiveBoardController::class, 'chauffeursDisponibles'])
        ->name('campagnes.chauffeurs-disponibles');
    Route::get('/campagnes/{campagne}/apercu-generation', [LiveBoardController::class, 'apercuGeneration'])
        ->name('campagnes.apercu-generation');
    Route::get('/campagnes/{campagne}/routes', [LiveBoardController::class, 'routes'])
        ->name('campagnes.routes');
    Route::get('/campagnes/{campagne}/non-couvertes', [LiveBoardController::class, 'nonCouvertes'])
        ->name('campagnes.non-couvertes');
    // Version tableau filtrable/paginée pour BuildRouteFlow.vue (09/09/2026,
    // prompt de cette date §5.1.3).
    Route::get('/campagnes/{campagne}/non-couvertes-tableau', [LiveBoardController::class, 'nonCouvertesTable'])
        ->name('campagnes.non-couvertes-tableau');
    // Cartes statistiques Suivi livraison (09/09/2026, prompt §5.2.4).
    Route::get('/campagnes/{campagne}/suivi-livraison-statistiques', [LiveBoardController::class, 'statistiques'])
        ->name('campagnes.suivi-livraison-statistiques');
    Route::get('/campagnes/{campagne}/incidents', [LiveBoardController::class, 'incidents'])
        ->name('campagnes.incidents');
    Route::post('/incidents/{incident}/resoudre', [LiveBoardController::class, 'resoudreIncident'])
        ->name('incidents.resoudre');
    // Fermeture sans traitement, statut 'ignore' (03/10/2026).
    Route::post('/incidents/{incident}/ignorer', [LiveBoardController::class, 'ignorerIncident'])
        ->name('incidents.ignorer');
    Route::post('/routes/{route}/ajouter-livraison', [LiveBoardController::class, 'ajouterLivraison'])
        ->name('routes.ajouter-livraison');
    Route::delete('/routes/{route}/etapes/{etape}', [LiveBoardController::class, 'retirerLivraison'])
        ->name('routes.retirer-livraison');
    // Override manuel du statut d'un arrêt par un gestionnaire (09/09/2026,
    // prompt de cette date §5.2.3 : "User needs to be able to manually
    // change these in case driver does not").
    Route::post('/routes/{route}/etapes/{etape}/statut', [LiveBoardController::class, 'changerStatutEtape'])
        ->name('routes.etapes.statut');
    // Vue admin d'une tournée = écran du chauffeur (29/09/2026, prompt §6.2) —
    // voir MaRouteController::voirCommeChauffeur().
    Route::get('/routes/{route}/vue-chauffeur', [MaRouteController::class, 'voirCommeChauffeur'])
        ->name('routes.vue-chauffeur');
    Route::post('/routes/{route}/reassigner', [LiveBoardController::class, 'reassignerRoute'])
        ->name('routes.reassigner');
    Route::post('/routes/{route}/diviser', [LiveBoardController::class, 'diviserRoute'])
        ->name('routes.diviser');
    Route::delete('/routes/{route}', [LiveBoardController::class, 'supprimerRoute'])
        ->name('routes.supprimer');
    Route::post('/campagnes/{campagne}/routes-personnalisees', [LiveBoardController::class, 'construireRoutePersonnalisee'])
        ->name('routes.personnalisee');

    // ── Pickers de recherche pour les écrans Vue (ajouté le 03/09/2026,
    //    voir App\Http\Controllers\Admin\Livraison\PickersController) —
    //    dans ce groupe role:gestionnaire plutôt que dans le groupe
    //    role:admin de admin.personnes.* : l'assignation de contact et la
    //    réassignation de tournée sont utilisables par un gestionnaire,
    //    pas seulement un admin. ────────────────────────────────────────
    Route::get('/personnes/recherche', [PickersController::class, 'personnes'])
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
    Route::get('/statistiques/{campagne?}', [StatistiquesController::class, 'index'])
        ->name('statistiques.index');
    Route::get('/statistiques/{campagne}/donnees', [StatistiquesController::class, 'donnees'])
        ->name('statistiques.donnees');
    Route::post('/statistiques/{campagne}/snapshot', [StatistiquesController::class, 'snapshot'])
        ->name('statistiques.snapshot');
});

// ── Bénévole (= chauffeur potentiel, benevole + BenevoleProfil — "chauffeur"
//    n'est pas un rôle séparé, voir prompt §4) : sa propre disponibilité,
//    sa propre tournée uniquement (admin/gestionnaire peuvent voir
//    n'importe laquelle via le tableau de bord ci-dessus, pas ici) ──────
Route::middleware(['auth', 'role:benevole'])->prefix('livraison/benevole')->name('livraison.benevole.')->group(function () {
    Route::get('/disponibilite/{campagne}', [DisponibiliteController::class, 'show'])
        ->name('disponibilite.show');
    Route::post('/disponibilite/{campagne}', [DisponibiliteController::class, 'update'])
        ->name('disponibilite.update');

    Route::get('/ma-route', [MaRouteController::class, 'show'])
        ->name('ma-route.show');
    Route::post('/etapes/{etape}/confirmer', [MaRouteController::class, 'confirmerEtape'])
        ->name('etapes.confirmer');
    Route::get('/etapes/{etape}/scan', [MaRouteController::class, 'confirmerScan'])
        ->name('etapes.scan');
    Route::post('/etapes/{etape}/ignoree', [MaRouteController::class, 'signalerIgnoree'])
        ->name('etapes.ignoree');
    // 30/09/2026 : démarrage explicite (« Je commence ma tournée »), retour
    // d'un arrêt ignoré à en_cours, et empreinte de polling de l'écran —
    // voir MaRouteController.
    Route::post('/routes/{route}/demarrer', [MaRouteController::class, 'demarrer'])
        ->name('routes.demarrer');
    Route::get('/routes/{route}/etat', [MaRouteController::class, 'etat'])
        ->name('routes.etat');
    Route::post('/etapes/{etape}/remettre-en-cours', [MaRouteController::class, 'remettreEnCours'])
        ->name('etapes.remettre-en-cours');
    Route::post('/routes/{route}/livraison-terminee', [MaRouteController::class, 'livraisonTerminee'])
        ->name('routes.livraison-terminee');
    Route::post('/routes/{route}/retour-qg', [MaRouteController::class, 'retourQg'])
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
    Route::get('/', [PosteReleveController::class, 'choisir'])
        ->defaults('type', 'reception')->middleware('livraison_role:equipe_reception')->name('choisir');
    Route::get('/{campagne}', [PosteReleveController::class, 'show'])
        ->defaults('type', 'reception')->middleware('can:equipeReception,campagne')->name('show');
    Route::post('/{campagne}', [PosteReleveController::class, 'enregistrer'])
        ->defaults('type', 'reception')->middleware('can:equipeReception,campagne')->name('enregistrer');
    // Journal des saisies (§4.2) : lister/modifier/supprimer chaque ligne
    // — pas de restriction de propriété (n'importe quel equipe_reception
    // peut éditer une ligne saisie par quelqu'un d'autre, voir le prompt).
    Route::get('/{campagne}/journal', [PosteReleveController::class, 'journal'])
        ->defaults('type', 'reception')->middleware('can:equipeReception,campagne')->name('journal');
    Route::patch('/arrivees/{arrivee}', [PosteReleveController::class, 'modifierArrivee'])
        ->middleware('can:gerer,arrivee')->name('arrivees.modifier');
    Route::delete('/arrivees/{arrivee}', [PosteReleveController::class, 'supprimerArrivee'])
        ->middleware('can:gerer,arrivee')->name('arrivees.supprimer');
});

Route::middleware('auth')->prefix('livraison/pesee')->name('livraison.pesee.')->group(function () {
    Route::get('/', [PosteReleveController::class, 'choisir'])
        ->defaults('type', 'pesee')->middleware('livraison_role:equipe_pesee')->name('choisir');
    Route::get('/{campagne}', [PosteReleveController::class, 'show'])
        ->defaults('type', 'pesee')->middleware('can:equipePesee,campagne')->name('show');
    Route::post('/{campagne}', [PosteReleveController::class, 'enregistrer'])
        ->defaults('type', 'pesee')->middleware('can:equipePesee,campagne')->name('enregistrer');
    Route::get('/{campagne}/journal', [PosteReleveController::class, 'journal'])
        ->defaults('type', 'pesee')->middleware('can:equipePesee,campagne')->name('journal');
    Route::patch('/dons/{don}', [PosteReleveController::class, 'modifierDon'])
        ->middleware('can:gerer,don')->name('dons.modifier');
    Route::delete('/dons/{don}', [PosteReleveController::class, 'supprimerDon'])
        ->middleware('can:gerer,don')->name('dons.supprimer');
});

Route::middleware('auth')->prefix('livraison/packaging')->name('livraison.packaging.')->group(function () {
    Route::get('/', [PackagingController::class, 'choisir'])
        ->middleware('livraison_role:equipe_packaging')->name('choisir');
    Route::get('/{campagne}', [PackagingController::class, 'index'])
        ->middleware('can:equipePackaging,campagne')->name('index');
    // marquer-pret (famille entière) conservé pour compatibilité mais plus
    // appelé directement par CampagneDetail/packaging.blade.php côté Vue
    // depuis le 05/09/2026 (prompt §5.3) — remplacé par colis/{colis}/statut,
    // finaliserConditionnement() étant désormais déclenché automatiquement
    // quand le dernier colis d'une famille passe à 'pret'.
    Route::post('/{livraison}/pret', [PackagingController::class, 'marquerPret'])
        ->middleware('can:gerer,livraison')->name('marquer-pret');
    Route::post('/colis/{colis}/statut', [PackagingController::class, 'marquerColisPret'])
        ->middleware('can:gerer,colis')->name('colis.statut');
    Route::post('/{livraison}/annuler', [PackagingController::class, 'annulerConditionnement'])
        ->middleware('can:gerer,livraison')->name('annuler');
    // Polling de la file (30/09/2026) — voir PackagingController::liste().
    Route::get('/{campagne}/liste', [PackagingController::class, 'liste'])
        ->middleware('can:equipePackaging,campagne')->name('liste');
    Route::get('/{campagne}/feuille-preparation', [PackagingController::class, 'feuillePreparation'])
        ->middleware('can:equipePackaging,campagne')->name('feuille-preparation');
    // Couverture de la collecte (Scénario 4 du chantier "polling live") —
    // voir PackagingController::poids(). Lecture seule, même autorisation
    // que index().
    Route::get('/{campagne}/poids', [PackagingController::class, 'poids'])
        ->middleware('can:equipePackaging,campagne')->name('poids');
});

Route::middleware('auth')->prefix('livraison/chargement')->name('livraison.chargement.')->group(function () {
    Route::get('/', [ChargementController::class, 'choisir'])
        ->middleware('livraison_role:equipe_chargement')->name('choisir');
    Route::get('/{campagne}', [ChargementController::class, 'index'])
        ->middleware('can:equipeChargement,campagne')->name('index');
    // Polling de l'écran chargement (Scénario 1 du chantier "polling live")
    // — voir ChargementController::liste(). Lecture seule, même
    // autorisation que index().
    Route::get('/{campagne}/liste', [ChargementController::class, 'liste'])
        ->middleware('can:equipeChargement,campagne')->name('liste');
    Route::post('/routes/{route}/confirmer', [ChargementController::class, 'confirmer'])
        ->middleware('can:gerer,route')->name('confirmer');
    // Annulation d'un chargement confirmé par erreur (06/10/2026) — ouvre un
    // incident, voir ChargementController::annulerChargement().
    Route::post('/routes/{route}/annuler-chargement', [ChargementController::class, 'annulerChargement'])
        ->middleware('can:gerer,route')->name('annuler-chargement');
    Route::post('/routes/{route}/benevole-absent', [ChargementController::class, 'signalerBenevoleAbsent'])
        ->middleware('can:gerer,route')->name('benevole-absent');
    Route::post('/routes/{route}/capacite', [ChargementController::class, 'signalerCapacite'])
        ->middleware('can:gerer,route')->name('capacite');
    // Feuille d'étiquettes QR pour TOUTES les familles confirmées de la
    // campagne, une planche unique à découper (07/09/2026, prompt §4.1) —
    // remplace le bouton d'étiquette par famille retiré de Packaging (§3.1,
    // qui n'a plus de raison d'être : ce besoin est couvert ici, en une
    // seule impression à l'échelle de la campagne plutôt que famille par
    // famille).
    Route::get('/{campagne}/etiquettes', [ChargementController::class, 'etiquettesCampagne'])
        ->middleware('can:equipeChargement,campagne')->name('etiquettes');
});

// ── Retrait QG — familles se_deplace (24/09/2026, prompt de cette date §2) ──
// Même équipe que ci-dessus (equipe_chargement, PAS un nouveau rôle — voir
// le docblock de RetraitHqController) : groupe séparé uniquement parce que
// le préfixe d'URL/les noms de route sont distincts de livraison/chargement,
// pas parce que l'autorisation diffère.
Route::middleware('auth')->prefix('livraison/retrait-hq')->name('livraison.retrait-hq.')->group(function () {
    Route::get('/', [RetraitHqController::class, 'choisir'])
        ->middleware('livraison_role:equipe_chargement')->name('choisir');
    Route::get('/{campagne}', [RetraitHqController::class, 'index'])
        ->middleware('can:equipeChargement,campagne')->name('index');
    Route::get('/{campagne}/liste', [RetraitHqController::class, 'liste'])
        ->middleware('can:equipeChargement,campagne')->name('liste');
    Route::post('/livraisons/{livraison}/livre', [RetraitHqController::class, 'marquerLivre'])
        ->middleware('can:gererRetraitHq,livraison')->name('livre');
    Route::post('/livraisons/{livraison}/non-livre', [RetraitHqController::class, 'marquerNonLivre'])
        ->middleware('can:gererRetraitHq,livraison')->name('non-livre');
    // Cible du QR code envoyé par RetraitHqNotification — voir
    // RetraitHqController::scan(). GET (pas POST) : ouvert directement
    // par l'appareil qui scanne, pas d'appel fetch() derrière.
    Route::get('/livraisons/{livraison}/scan', [RetraitHqController::class, 'scan'])
        ->middleware('can:gererRetraitHq,livraison')->name('scan');
});

// ── Formulaire public de confirmation famille (aucune authentification) ──
// Accès scopé strictement par jeton (contact_tokens) — voir
// App\Http\Controllers\Livraison\ContactConfirmationController. Même
// schéma de throttle que les autres formulaires publics de l'app
// ci-dessus (intake/vérification/candidature bénévole). Contrairement à
// ces confirmations "en un clic", ce formulaire a un vrai second temps
// (saisie adresse/membres du foyer/créneaux) : GET affiche, POST traite
// la soumission (Patch 2).
Route::get('/livraison/confirmation/{token}', [ContactConfirmationController::class, 'show'])
    ->name('livraison.confirmation.show')
    ->middleware('throttle:20,1');
Route::post('/livraison/confirmation/{token}', [ContactConfirmationController::class, 'store'])
    ->name('livraison.confirmation.store')
    ->middleware('throttle:10,1');
