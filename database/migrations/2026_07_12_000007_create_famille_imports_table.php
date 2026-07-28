<?php
// database/migrations/2026_07_12_000007_create_famille_imports_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table famille_imports.
 *
 * Décision 6.9 : contrairement à l'ancien système (feuilles Google Sheets
 * dédiées Bulk Import / Bulk Update), un seul pipeline commun alimente à la
 * fois l'ajout manuel via UI et l'upload CSV — voir famille_import_rows.
 *
 * uploaded_by référence ref_personnes.id : IMPORTANT — ref_personnes.id est
 * un increments() (unsigned INT), pas un id() Laravel standard (unsigned
 * BIGINT). Utiliser unsignedInteger() explicitement, PAS foreignId(), sous
 * peine d'incompatibilité de type sur la contrainte FK (cf. le problème déjà
 * rencontré sur sessions.user_id côté amana_web_planning). Pas de contrainte
 * FK déclarée ici : ref_personnes est une table partagée possédée par
 * amana_web_planning, migrée séparément et potentiellement après cette
 * migration dans un environnement vierge — même logique que audit_logs.user_id
 * (pas de FK intentionnellement, la ligne d'import doit survivre même si le
 * compte du membre du staff est supprimé).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('famille_imports', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['import', 'update']);
            $table->enum('source', ['manual', 'csv']);
            $table->unsignedInteger('uploaded_by')->nullable()
                ->comment('ID de ref_personnes — pas de FK, table partagée possédée par amana_web_planning');
            $table->string('status', 50)->default('pending');
            $table->timestamp('created_at')->useCurrent();

            $table->index('uploaded_by');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('famille_imports');
    }
};
