<?php
// database/migrations/2026_08_28_000005_create_personne_organisation_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table personne_organisation.
 *
 * Rattache un compte ref_personnes (rôle gestionnaire_externe côté
 * familles) à une ou plusieurs organisations partenaires — décision du
 * 28/08/2026 : un gestionnaire externe peut appartenir à plusieurs
 * organisations à la fois.
 *
 * id_personne référence ref_personnes (amana_commun), organisations vit
 * dans CETTE base (voir create_organisations_table) — table à cheval sur
 * les deux bases, même convention que famille_imports.uploaded_by pour la
 * colonne côté commun : unsignedInteger explicite (ref_personnes.id est un
 * increments(), pas un id() standard), PAS de contrainte FK côté
 * id_personne. id_organisation, lui, est une contrainte FK réelle
 * (même base).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('personne_organisation', function (Blueprint $table) {
            $table->unsignedInteger('id_personne')
                ->comment('ID de ref_personnes (amana_commun) — pas de FK, table partagée cross-DB.');
            $table->foreignId('id_organisation')->constrained('organisations')->cascadeOnDelete();
            $table->date('date_attribution')->default(now()->toDateString());

            $table->primary(['id_personne', 'id_organisation']);
            $table->index('id_personne');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personne_organisation');
    }
};
