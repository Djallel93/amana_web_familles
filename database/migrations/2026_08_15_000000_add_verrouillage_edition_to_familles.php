<?php
// database/migrations/2026_08_15_000000_add_verrouillage_edition_to_familles.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verrouillage d'édition d'un dossier (décision du 15/08/2026) — quand un
 * membre du staff ouvre le Dossier Panel, le dossier bascule visiblement à
 * 'En cours' (si ce n'est pas déjà son état) et se verrouille pour les
 * autres utilisateurs, le temps de l'édition ; à l'enregistrement ou à
 * l'annulation, il revient à son état d'avant ouverture — voir
 * FamillesController::show()/update()/deverrouiller().
 *
 * Objectif double : (1) éviter que deux membres du staff éditent le même
 * dossier en même temps sans le savoir (conflit d'écriture silencieux —
 * le dernier "Enregistrer" écrase l'autre) ; (2) comme effet de bord,
 * garantir que le déclencheur de synchronisation Google Contacts (qui se
 * base sur etat_dossier ∈ {Validé, Rejeté, Archivé} à l'enregistrement,
 * voir FamillesController::update()) se redéclenche systématiquement dès
 * qu'un dossier déjà validé/rejeté/archivé est réenregistré, puisqu'il
 * repasse toujours par 'En cours' au passage.
 *
 * locked_by référence ref_personnes.id — même convention que
 * famille_imports.uploaded_by (unsignedInteger, PAS foreignId : voir
 * commentaire détaillé dans 2026_07_12_000007_create_famille_imports_table.php
 * — ref_personnes.id est un increments() unsigned INT, pas un id() Laravel
 * standard) ; pas de contrainte FK, table partagée possédée par
 * amana_web_planning.
 *
 * etat_dossier_avant_verrouillage : snapshot du statut réel juste avant le
 * verrouillage/bascule à 'En cours' — nullable, vide dès qu'aucun
 * verrouillage n'est en cours (voir FamillesController::update() pour la
 * logique de restauration).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('familles', function (Blueprint $table) {
            $table->unsignedInteger('locked_by')->nullable()->after('google_resource_name')
                ->comment('ID de ref_personnes — pas de FK, table partagée possédée par amana_web_planning');
            $table->timestamp('locked_at')->nullable()->after('locked_by');
            $table->string('etat_dossier_avant_verrouillage', 20)->nullable()->after('locked_at')
                ->comment("Valeur réelle d'etat_dossier juste avant le passage automatique à 'En cours' à l'ouverture du Dossier Panel — restaurée à l'enregistrement/l'annulation");

            $table->index('locked_by');
        });
    }

    public function down(): void
    {
        Schema::table('familles', function (Blueprint $table) {
            $table->dropIndex(['locked_by']);
            $table->dropColumn(['locked_by', 'locked_at', 'etat_dossier_avant_verrouillage']);
        });
    }
};
