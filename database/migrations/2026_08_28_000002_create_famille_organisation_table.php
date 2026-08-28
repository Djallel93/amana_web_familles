<?php
// database/migrations/2026_08_28_000002_create_famille_organisation_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table famille_organisation.
 *
 * Décision du 28/08/2026 : quand deux organisations enregistrent la même
 * famille (rapprochée par FamilleUpsertService::trouverDoublon(), comme
 * avant), c'est UN SEUL dossier partagé — pas une copie par organisation.
 * Cette table pivot porte donc l'ensemble des organisations rattachées à
 * un dossier (une famille peut en avoir plusieurs), pas une seule colonne
 * id_organisation sur familles (qui reste néanmoins présente pour tracer
 * l'organisation D'ORIGINE — voir add_id_organisation_to_familles_table —
 * mais ne pilote plus à elle seule la visibilité).
 *
 * familles ET organisations vivent toutes les deux dans cette base (voir
 * create_organisations_table) : contrainte FK réelle possible des deux
 * côtés, contrairement à id_quartier (commun).
 *
 * Un rattachement ne passe PAS directement par cette table à la création :
 * voir famille_organisation_demandes pour le cas "organisation différente
 * de celle(s) déjà rattachée(s)", qui nécessite une validation admin/
 * gestionnaire avant d'atterrir ici.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('famille_organisation', function (Blueprint $table) {
            $table->foreignId('id_famille')->constrained('familles')->cascadeOnDelete();
            $table->foreignId('id_organisation')->constrained('organisations')->cascadeOnDelete();
            $table->timestamp('rattachee_le')->useCurrent();

            $table->primary(['id_famille', 'id_organisation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('famille_organisation');
    }
};
