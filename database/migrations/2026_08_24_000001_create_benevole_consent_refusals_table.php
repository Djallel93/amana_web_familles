<?php
// database/migrations/2026_08_24_000001_create_benevole_consent_refusals_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trace un refus de consentement RGPD à la première étape du formulaire
 * de candidature bénévole — miroir exact de intake_consent_refusals (voir
 * create_familles_table). Ajouté le 24/08/2026 (absent de la première
 * implémentation, le bouton "Je refuse" manquait sur cette étape).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('benevole_consent_refusals', function (Blueprint $table) {
            $table->id();
            $table->string('langue', 2);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benevole_consent_refusals');
    }
};
