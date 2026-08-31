<?php
// database/migrations/2026_08_31_000007_create_benevole_disponibilites_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table benevole_disponibilites.
 *
 * Confirmation de disponibilité d'un bénévole (= chauffeur potentiel —
 * "chauffeur" n'est pas un rôle séparé, c'est benevole + un
 * BenevoleProfil avec véhicule/permis, voir §4 du prompt du 30/08/2026)
 * pour UNE campagne donnée. `id_personne` référence commun (pas de FK,
 * même convention que le reste de ce patch) ; unique par (id_personne,
 * id_campagne) — une seule ligne de disponibilité par bénévole et par
 * campagne, éditable à tout moment après la confirmation initiale (pas de
 * flux "renvoyer le formulaire").
 *
 * `vehicule_confirme` : le bénévole confirme que son véhicule correspond
 * toujours à BenevoleProfil.id_vehicule_type (commun) — pas de
 * re-saisie ici, juste une confirmation booléenne.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('benevole_disponibilites', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('id_personne')
                ->comment('ref_personnes.id — pas de FK, commun est une base séparée');
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();

            $table->boolean('vehicule_confirme')->default(false);
            $table->boolean('coverage_confirmee')->default(false);
            $table->text('coverage_notes')->nullable();
            $table->enum('statut', ['non_confirme', 'confirme'])->default('non_confirme');

            $table->timestamps();

            $table->unique(['id_personne', 'id_campagne']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benevole_disponibilites');
    }
};
