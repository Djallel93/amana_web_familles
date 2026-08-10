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
 * Types repris de CONFIG.DOC_TYPES (amana_familles) : identity et resource
 * sont fonctionnellement équivalentes aux anciens identityDoc / resourceDoc
 * du COLUMN_MAP. Le générique aides_etat est scindé en deux (caf / ame) pour
 * refléter le branchement du Google Form historique (section "Situation
 * administrative" — type_piece_identite détermine lequel des deux est
 * demandé, jamais les deux à la fois) — voir
 * 2026_07_12_000004_create_familles_table.php.
 *
 * Justificatifs d'identité obligatoires (max 5), caf/ame obligatoire selon
 * la branche empruntée (max 5), resource optionnel (max 10) — règles
 * reprises de validationService.js + des limites maxFiles du Google Form.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('famille_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_famille')
                ->constrained('familles')
                ->onDelete('cascade');
            $table->enum('type', ['identity', 'caf', 'ame', 'resource']);
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
