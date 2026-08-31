<?php
// database/migrations/2026_08_31_000012_create_campagne_stats_snapshots_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table campagne_stats_snapshots.
 *
 * NOTE : cette table n'est pas nommée explicitement dans le prompt du
 * 30/08/2026, mais en découle directement (§3.5 : "snapshotted/stored as
 * their own record ... not just computed live and lost"). Forme proposée
 * ici (un blob JSON `donnees` horodaté) plutôt que des colonnes figées, le
 * temps que Patch 5 (stats) précise le contenu exact du tableau de bord —
 * des colonnes dédiées pourront être extraites plus tard si un besoin de
 * requêtage SQL direct sur une métrique précise apparaît. À confirmer/
 * ajuster au moment du Patch 5 si la forme ne convient pas.
 *
 * Une campagne peut avoir plusieurs snapshots dans le temps (conclusion +
 * captures périodiques en cours de campagne, voir §3.5) — pas de
 * contrainte d'unicité par campagne.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('campagne_stats_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->timestamp('snapshot_at')->useCurrent();
            $table->json('donnees')
                ->comment('Métriques calculées au moment du snapshot — forme provisoire, voir docblock de migration');
            $table->timestamps();

            $table->index('id_campagne');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campagne_stats_snapshots');
    }
};
