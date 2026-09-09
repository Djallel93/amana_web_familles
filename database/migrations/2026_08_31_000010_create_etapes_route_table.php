<?php
// database/migrations/2026_08_31_000010_create_etapes_route_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table etapes_route.
 *
 * Arrêts ordonnés d'une tournée (sortie de l'optimisation TSP, Patch 3).
 * `id_livraison` nullable : null = arrêt "retour QG" (pas de famille
 * associée) — ordre porté par `ordre`, statut propre à l'arrêt
 * (indépendant du statut de la livraison elle-même, pour permettre par
 * exemple un arrêt "ignoré" sur la tournée sans que ça soit nécessairement
 * la même sémantique que livraisons.statut).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('etapes_route', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_route')->constrained('routes')->cascadeOnDelete();
            $table->foreignId('id_livraison')->nullable()
                ->constrained('livraisons')
                ->nullOnDelete()
                ->comment('Null = arrêt retour QG, pas de famille associée');
            $table->unsignedSmallInteger('ordre');
            // 'en_cours' ajouté le 09/09/2026 (prompt de cette date §5.2.3 :
            // "en cours, delivered, skiped, etc") — jamais posé par le
            // parcours bénévole (MaRouteController ne connaît toujours que
            // en_attente/livree/ignoree), seulement disponible via l'override
            // manuel gestionnaire ajouté ce même jour (voir
            // LiveBoardController::changerStatutEtape()) pour signaler
            // "le chauffeur est en train de livrer cette famille" quand le
            // suivi terrain n'est pas à jour.
            $table->enum('statut', ['en_attente', 'en_cours', 'livree', 'ignoree'])->default('en_attente');

            $table->index(['id_route', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etapes_route');
    }
};
