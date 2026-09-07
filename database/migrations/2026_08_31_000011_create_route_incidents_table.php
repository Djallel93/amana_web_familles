<?php
// database/migrations/2026_08_31_000011_create_route_incidents_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table route_incidents.
 *
 * Mécanisme unifié pour tout événement méritant l'attention de
 * l'admin/gestionnaire OU constituant un jalon suivi — remplace les
 * notifications email et la gestion "skip" non structurée de l'ancien
 * système (voir §3.3 point 8 et §4 du prompt du 30/08/2026).
 *
 * `statut` nullable : sans objet pour type=chargement_termine (un jalon,
 * pas une alerte actionnable) — nullable plutôt qu'une valeur factice
 * ('resolu' par défaut aurait été trompeur, laissant penser qu'il y a eu
 * quelque chose à résoudre).
 *
 * benevole_absent déclenche, au niveau service (Patch 3/4) et non ici,
 * la remise à `non_assignee` de toutes les etapes_route/livraisons non
 * livrées de la tournée concernée.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('route_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_route')->constrained('routes')->cascadeOnDelete();
            // 'packaging_annule' ajouté le 05/09/2026 (prompt §5.3) :
            // levé quand l'équipe packaging annule un conditionnement
            // déjà marqué prêt sur une tournée déjà en 'chargement' —
            // avertit l'équipe chargement/chauffeur (mêmes destinataires
            // que RoutePretePourChargementNotification) que ce colis
            // repart en préparation. Actionnable comme benevole_absent/
            // capacite (statut ouvert/resolu), pas un simple jalon.
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
    }
};
