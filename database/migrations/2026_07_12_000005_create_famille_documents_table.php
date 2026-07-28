<?php
// database/migrations/2026_07_12_000005_create_famille_documents_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table famille_documents.
 *
 * Remplace les colonnes `identite` / `aides_etat` de l'ancien système
 * (URLs Google Drive séparées par virgules) par une table dédiée, un
 * enregistrement par fichier. Stockage disque local IONOS (décision 6.4) —
 * pas de S3, pas de Google Drive.
 *
 * Trois types repris de CONFIG.DOC_TYPES (amana_familles) : identity et
 * resource sont fonctionnellement équivalentes aux anciens identityDoc /
 * resourceDoc du COLUMN_MAP ; aides_etat correspond à aidesEtatDoc.
 * Justificatifs d'identité obligatoires, aides_etat et resource optionnels
 * (règle métier confirmée dans validationService.js).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('famille_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_famille')
                ->constrained('familles')
                ->onDelete('cascade');
            $table->enum('type', ['identity', 'aides_etat', 'resource']);
            $table->string('disk_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 150);
            $table->timestamp('uploaded_at')->useCurrent();

            $table->index(['id_famille', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('famille_documents');
    }
};
