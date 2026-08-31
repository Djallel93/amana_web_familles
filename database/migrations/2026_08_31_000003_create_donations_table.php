<?php
// database/migrations/2026_08_31_000003_create_donations_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table donations.
 *
 * Journal de pesée au poste "entrée QG", après transport de la nourriture
 * depuis le parking (voir campagne_arrivees pour le comptage des
 * donateurs, poste distinct et non lié à celui-ci). Une ligne = un passage
 * à la pesée, poids total unique — PAS de ventilation par catégorie
 * (riz/farine/bonbons/hygiène...) : le tri physique se fait sans relevé
 * numérique par catégorie, exclu explicitement de ce patch (voir §6 du
 * prompt du 30/08/2026).
 *
 * campagnes.poids_collecte_kg = somme de poids_kg pour la campagne (voir
 * create_campagnes_table.php) — calculé à la volée, pas stocké.
 *
 * Même raisonnement que campagne_arrivees pour logge_par : identifie
 * uniquement le membre du staff qui pèse, jamais le donateur.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->decimal('poids_kg', 7, 2);
            $table->timestamp('horodatage')->useCurrent();
            $table->unsignedInteger('logge_par')
                ->comment('ref_personnes.id du membre du staff tenant le poste — pas de FK, commun est une base séparée');

            $table->index('id_campagne');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
    }
};
