<?php
// database/migrations/2026_07_12_000008_create_famille_import_rows_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table famille_import_rows.
 *
 * Une ligne par enregistrement traité (saisie manuelle ou ligne CSV),
 * `payload` conservant les données brutes soumises (avant mapping vers le
 * schéma `familles`) — utile pour rejouer/déboguer une ligne en erreur.
 *
 * Statuts repris de CONFIG.BULK_STATUS (amana_familles) : pending, en cours
 * → ici on simplifie à pending/success/error/skipped comme demandé section
 * 6.9 ("comme l'ancien BULK_STATUS").
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('famille_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_import')
                ->constrained('famille_imports')
                ->onDelete('cascade');
            $table->unsignedInteger('row_number');
            $table->json('payload');
            $table->enum('status', ['pending', 'success', 'error', 'skipped'])
                ->default('pending');
            $table->text('error_message')->nullable();

            $table->index(['id_import', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('famille_import_rows');
    }
};
