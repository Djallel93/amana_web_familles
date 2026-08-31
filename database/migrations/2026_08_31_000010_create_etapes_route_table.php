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
            $table->enum('statut', ['en_attente', 'livree', 'ignoree'])->default('en_attente');

            $table->index(['id_route', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etapes_route');
    }
};
