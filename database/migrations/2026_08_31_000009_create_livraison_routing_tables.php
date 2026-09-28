<?php
// database/migrations/2026_08_31_000009_create_livraison_routing_tables.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : domaine Livraison — Routing — squash du 10/09/2026 (Section
 * D du refactor) de 3 migrations d'origine :
 *   - 2026_08_31_000009_create_routes_table.php
 *   - 2026_08_31_000400_make_routes_creneau_nullable.php
 *   - 2026_08_31_000010_create_etapes_route_table.php
 *   - 2026_08_31_000011_create_route_incidents_table.php
 *
 * `routes.creneau` ci-dessous est directement `nullable()` dès la
 * création — la migration d'origine la posait NOT NULL, rendue nullable
 * ensuite par une requête SQL brute (DB::statement, doctrine/dbal absent
 * de ce projet) une fois découvert qu'une tournée composée uniquement de
 * livraisons imposées (id_benevole_impose) n'a pas de créneau unique qui
 * lui corresponde.
 */
return new class extends Migration {
    public function up(): void
    {
        // Une tournée = un bénévole, un créneau, un ensemble ordonné
        // d'arrêts (voir etapes_route). Table nommée `routes` au niveau
        // DB, mais modèle Eloquent nommé App\Models\RouteLivraison (et non
        // `Route`) — pour ne jamais entrer en collision avec la façade
        // Laravel Illuminate\Support\Facades\Route (utilisée partout dans
        // routes/web.php). Seul le nom de la classe change, la table reste
        // `routes`.
        //
        // `id_benevole`/`id_vehicule_type` : commun, pas de FK (même
        // convention que le reste de ce domaine). `creneau` : voir
        // livraison_creneaux pour le raisonnement string/pas-enum —
        // nullable dès la création (voir docblock de fichier) : une
        // tournée composée uniquement de livraisons imposées reste sans
        // créneau (null) plutôt que de lui en inventer un arbitrairement.
        // `locked_at`/`locked_by` : même verrouillage d'édition que
        // familles/livraisons.
        //
        // `statut` — forme finale de l'enum, construite au fil de l'eau :
        //  - 'livraisons_terminees' : état intermédiaire entre "en_cours"
        //    et "terminee" — tous les arrêts sont livrés/ignorés mais le
        //    bénévole n'a pas encore confirmé son retour au QG (bouton
        //    "Livraison terminé", séparé de "Retour QG" — voir
        //    App\Http\Controllers\Livraison\MaRouteController). Permet à
        //    l'admin/gestionnaire de voir "tournée finie sur le terrain"
        //    même si le bénévole ne tape jamais le second bouton.
        //  - 'packaging_annule' : l'équipe packaging peut annuler un
        //    conditionnement déjà marqué "prêt" pour reprendre les colis
        //    (erreur de manipulation) — si la tournée avait déjà basculé
        //    sur 'chargement' (équipe chargement/chauffeur déjà notifiés),
        //    elle redescend ici plutôt que de rester en 'chargement'
        //    comme si de rien n'était. Voir
        //    PackagingController::annulerConditionnement() et le type
        //    d'incident du même nom (route_incidents).
        //  - 'charge' : état intermédiaire entre 'chargement' (à charger)
        //    et 'en_cours' (tournée effectivement démarrée par le
        //    bénévole/chauffeur) — sépare "chargement terminé"
        //    (equipe_chargement, voir ChargementController::confirmer())
        //    de "tournée démarrée" (bénévole, voir MaRouteController) :
        //    auparavant confirmer() basculait DIRECTEMENT sur 'en_cours',
        //    ce qui faisait dire à l'écran "Ma Route" du bénévole que sa
        //    tournée était démarrée dès la confirmation de chargement,
        //    alors qu'il n'avait souvent pas encore quitté le QG.
        //  - 'annulee' : RouteMutationService::supprimer() faisait
        //    jusqu'ici un hard delete (ligne + étapes supprimées) —
        //    remplacé par un passage à ce statut pour garder la tournée
        //    visible en historique sur Suivi livraison au lieu de la faire
        //    disparaître silencieusement. Reste soumis à la même règle que
        //    le delete d'origine : seule une tournée 'planifiee' peut être
        //    annulée.
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->foreignId('id_campagne_journee')->nullable()
                ->constrained('campagne_journees')->nullOnDelete()
                ->comment('Journée de campagne pour laquelle cette tournée est générée — voir campagne_journees et livraisons.id_campagne_journee');
            $table->unsignedInteger('id_benevole')
                ->comment('ref_personnes.id — pas de FK, commun est une base séparée');
            $table->unsignedInteger('id_vehicule_type')
                ->comment('ref_vehicules.id — pas de FK, commun est une base séparée');
            $table->string('creneau', 5)->nullable()
                ->comment('Une des 6 valeurs de App\\Support\\Creneau::TOUS — créneau pour lequel cette tournée a été générée. Nullable : tournée composée uniquement de livraisons imposées (id_benevole_impose), exemptées de toute correspondance de créneau.');

            $table->enum('statut', ['planifiee', 'chargement', 'charge', 'en_cours', 'livraisons_terminees', 'terminee', 'packaging_annule', 'annulee'])
                ->default('planifiee');
            $table->decimal('distance_totale_km', 6, 2)->nullable();
            $table->decimal('poids_total_kg', 7, 2)->nullable();
            $table->text('lien_maps')->nullable();

            $table->unsignedInteger('locked_by')->nullable()
                ->comment('ref_personnes.id — même verrouillage que familles.locked_by, pas de FK');
            $table->timestamp('locked_at')->nullable();

            $table->timestamps();

            $table->index(['id_campagne', 'creneau']);
            $table->index('id_benevole');
        });

        // Arrêts ordonnés d'une tournée (sortie de l'optimisation TSP).
        // `id_livraison` nullable : null = arrêt "retour QG" (pas de
        // famille associée) — ordre porté par `ordre`, statut propre à
        // l'arrêt (indépendant du statut de la livraison elle-même, pour
        // permettre par exemple un arrêt "ignoré" sur la tournée sans que
        // ça soit nécessairement la même sémantique que livraisons.statut).
        //
        // 'en_cours' (statut) : jamais posé par le parcours bénévole
        // (MaRouteController ne connaît toujours que
        // en_attente/livree/ignoree), seulement disponible via l'override
        // manuel gestionnaire (voir LiveBoardController::changerStatutEtape())
        // pour signaler "le chauffeur est en train de livrer cette
        // famille" quand le suivi terrain n'est pas à jour.
        Schema::create('etapes_route', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_route')->constrained('routes')->cascadeOnDelete();
            $table->foreignId('id_livraison')->nullable()
                ->constrained('livraisons')
                ->nullOnDelete()
                ->comment('Null = arrêt retour QG, pas de famille associée');
            $table->unsignedSmallInteger('ordre');
            $table->enum('statut', ['en_attente', 'en_cours', 'livree', 'ignoree'])->default('en_attente');

            $table->index(['id_route', 'ordre']);
        });

        // Mécanisme unifié pour tout événement méritant l'attention de
        // l'admin/gestionnaire OU constituant un jalon suivi — remplace
        // les notifications email et la gestion "skip" non structurée de
        // l'ancien système.
        //
        // `statut` nullable : sans objet pour type=chargement_termine (un
        // jalon, pas une alerte actionnable) — nullable plutôt qu'une
        // valeur factice ('resolu' par défaut aurait été trompeur,
        // laissant penser qu'il y a eu quelque chose à résoudre).
        //
        // benevole_absent déclenche, au niveau service et non ici, la
        // remise à `non_assignee` de toutes les etapes_route/livraisons
        // non livrées de la tournée concernée.
        //
        // 'packaging_annule' (type) : levé quand l'équipe packaging annule
        // un conditionnement déjà marqué prêt sur une tournée déjà en
        // 'chargement' — avertit l'équipe chargement/chauffeur (mêmes
        // destinataires que RoutePretePourChargementNotification) que ce
        // colis repart en préparation. Actionnable comme
        // benevole_absent/capacite (statut ouvert/resolu), pas un simple
        // jalon.
        Schema::create('route_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_route')->constrained('routes')->cascadeOnDelete();
            $table->enum('type', ['benevole_absent', 'capacite', 'chargement_termine', 'livraison_ignoree', 'packaging_annule']);
            $table->foreignId('id_livraison')->nullable()
                ->constrained('livraisons')
                ->nullOnDelete()
                ->comment('Renseigné pour type = livraison_ignoree ou packaging_annule (identifie la famille concernée)');
            $table->unsignedInteger('signale_par')
                ->comment('ref_personnes.id — pas de FK, commun est une base séparée');
            $table->enum('statut', ['ouvert', 'resolu'])->nullable()
                ->comment('Sans objet (null) pour type = chargement_termine, jalon et non alerte actionnable');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['id_route', 'type']);
            $table->index('statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_incidents');
        Schema::dropIfExists('etapes_route');
        Schema::dropIfExists('routes');
    }
};
