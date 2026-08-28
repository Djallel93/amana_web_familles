<?php
// database/migrations/2026_08_28_000007_add_id_organisation_to_famille_imports_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : ajoute famille_imports.id_organisation.
 *
 * Décision du 28/08/2026 : un gestionnaire_externe peut importer/ajouter
 * manuellement des familles pour son(ses) organisation(s) (voir
 * Admin\ImportsController, désormais partagé avec le rôle
 * gestionnaire_externe) — chaque ligne créée par ce pipeline est forcée
 * sur l'organisation de l'auteur (voir FamilleImportService), et cette
 * colonne permet à Admin\ImportsController::index() de scoper la liste des
 * imports visibles à l'organisation de l'utilisateur, comme
 * FamillesController le fait déjà pour les dossiers eux-mêmes.
 *
 * Nullable : les imports internes (staff AMANA, avant cette fonctionnalité
 * ou simplement sans organisation partenaire précisée) restent valides
 * sans valeur ici — traités comme "organisation principale" par
 * convention côté application, pas besoin de backfill en base (contrairement
 * à familles.id_organisation, qui pilote directement la visibilité des
 * dossiers eux-mêmes).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('famille_imports', function (Blueprint $table) {
            $table->foreignId('id_organisation')->nullable()->after('uploaded_by')
                ->constrained('organisations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('famille_imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_organisation');
        });
    }
};
