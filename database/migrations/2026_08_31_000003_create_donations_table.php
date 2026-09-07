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
            // Ajouté le 05/09/2026 (prompt §3) : cette table précède
            // campagne_journees dans l'ordre des migrations (créée le
            // 03/09/2026, APRÈS celle-ci) — pas de vraie contrainte FK
            // possible ici (la table cible n'existe pas encore au moment
            // où cette migration s'exécute), simple colonne + index,
            // comme le fait déjà le reste du domaine livraison pour des
            // raisons similaires (voir logge_par ci-dessous). Une
            // campagne pouvant désormais s'étaler sur plusieurs journées,
            // chaque pesée doit être rattachée à CELLE pour laquelle elle
            // a été saisie (sélecteur en haut de l'écran pesée), pas
            // seulement à la campagne. Nullable : la pesée reste une
            // étape facultative par campagne (certaines campagnes n'en
            // ont pas), et les lignes déjà en base avant cet ajout n'ont
            // pas de journée à leur rattacher rétroactivement.
            $table->unsignedBigInteger('id_campagne_journee')->nullable();
            $table->decimal('poids_kg', 7, 2);
            $table->timestamp('horodatage')->useCurrent();
            $table->unsignedInteger('logge_par')
                ->comment('ref_personnes.id du membre du staff tenant le poste — pas de FK, commun est une base séparée');

            $table->index('id_campagne');
            $table->index(['id_campagne_journee']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
    }
};
