<?php
// database/migrations/2026_08_24_000000_create_benevole_demandes_attente_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table d'attente pour la candidature bénévole publique — miroir exact de
 * intake_demandes_attente (voir cette migration pour le raisonnement
 * détaillé) : soumission stockée 48h, email de confirmation envoyé, la
 * Personne/le BenevoleProfil ne sont créés/liés qu'à la confirmation
 * (voir BenevoleIntakeConfirmationController::confirmer()).
 *
 * Base propre à amana_web_familles (contrairement à benevole_profils, qui
 * vit dans amana_commun) : cette table est un détail d'implémentation du
 * flux de confirmation par email, pas une donnée à partager entre apps.
 *
 * `donnees` : nom/prenom/email/telephone/langue + permis/id_vehicule_type/
 * zone_livraison, mêmes clés que celles validées par
 * BenevoleIntakeController::store().
 * `secteurs` : IDs de secteurs sélectionnés (JSON, hors `donnees` — pas une
 * colonne mais une relation belongsToMany une fois le profil créé).
 * Pas de disponibilités (retiré le 24/08/2026, voir BenevoleProfil).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('benevole_demandes_attente', function (Blueprint $table) {
            $table->id();
            $table->string('token', 100)->unique();
            $table->string('langue', 2);
            $table->json('donnees');
            $table->json('secteurs')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benevole_demandes_attente');
    }
};
