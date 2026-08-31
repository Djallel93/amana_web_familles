<?php
// database/migrations/2026_08_31_000006_create_contact_tokens_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table contact_tokens.
 *
 * Jetons à usage unique, expirables, pour le formulaire public de
 * confirmation famille (adresse, membres du foyer, créneaux disponibles).
 * Générés uniquement pour les familles disposant d'un email — sinon,
 * contact téléphonique par le staff (voir livraisons.statut_contact/
 * id_personne_assignee).
 *
 * Même schéma de jeton que VerificationController/IntakeConfirmationController
 * (voir familles_verifications/intake_demandes_attente) : `token` haché au
 * repos, jamais stocké en clair — seul le hash est comparé à la
 * réception. `used_at` empêche toute réutilisation après soumission.
 * La route publique de confirmation ne doit résoudre QUE la livraison
 * scopée à ce jeton, jamais d'autres données famille (voir contrôleur
 * associé, Patch 2).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('contact_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_livraison')->constrained('livraisons')->cascadeOnDelete();
            $table->string('token', 100)->unique()
                ->comment('Haché au repos (hash("sha256", ...)) — jamais stocké en clair');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index('id_livraison');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_tokens');
    }
};
