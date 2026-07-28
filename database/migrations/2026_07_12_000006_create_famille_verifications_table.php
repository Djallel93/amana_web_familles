<?php
// database/migrations/2026_07_12_000006_create_famille_verifications_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table famille_verifications.
 *
 * Remplace emailVerificationService.js / endpoints confirmfamilyinfo /
 * sendverificationemails de l'ancien système. La famille reçoit un email
 * avec un lien contenant `token` pour relire/confirmer ses informations
 * (décision 6.10 — flux conservé).
 *
 * Pas de updated_at : une ligne est créée à l'envoi, puis confirmed_at est
 * simplement rempli à la confirmation — pas d'autre état intermédiaire.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('famille_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_famille')
                ->constrained('familles')
                ->onDelete('cascade');
            $table->string('token', 100)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('id_famille');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('famille_verifications');
    }
};
