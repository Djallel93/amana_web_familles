<?php
// database/migrations/2026_08_28_000004_create_famille_organisation_demandes_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table famille_organisation_demandes.
 *
 * Décision du 28/08/2026 : quand une organisation B soumet/importe une
 * famille qui matche déjà un dossier rattaché à une organisation A (même
 * dédup que FamilleUpsertService::trouverDoublon()), le dossier n'est PAS
 * modifié et B n'est PAS rattachée automatiquement — une ligne est créée
 * ici et un admin/gestionnaire (staff interne, jamais un
 * gestionnaire_externe, même de l'organisation A) doit valider avant que
 * B obtienne l'accès (voir FamilleOrganisationDemandeService).
 *
 * `source` distingue le canal d'origine de la demande — utile pour
 * l'écran de revue (voir Admin\RattachementsController), même esprit que
 * famille_imports.source.
 *
 * `donnees_soumises` : snapshot JSON de ce que B a soumis/importé (pas
 * appliqué au dossier tant que non validé) — permet au staff de comparer
 * à l'écran de revue avant de décider, et sert de base si la demande est
 * validée alors que certains champs ont besoin d'être fusionnés
 * manuellement (hors scope du merge automatique de
 * FamilleUpsertService::upsert()).
 *
 * Contrairement à intake_demandes_attente (purgée après usage), une ligne
 * ici reste en base après traitement (statut validée/rejetée) — c'est un
 * historique de décision, pas juste un jeton temporaire.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('famille_organisation_demandes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_famille')->constrained('familles')->cascadeOnDelete();
            $table->foreignId('id_organisation')->constrained('organisations')->cascadeOnDelete();
            $table->enum('source', ['intake', 'import', 'manuel']);
            // ID de ref_personnes (commun) — pas de FK, même raisonnement
            // que famille_imports.uploaded_by. Nullable : une soumission du
            // formulaire public d'intake n'a pas de compte.
            $table->unsignedInteger('submitted_by')->nullable()
                ->comment('ID de ref_personnes — pas de FK, table partagée. Nullable : soumission publique (intake).');
            $table->json('donnees_soumises')->nullable();
            $table->enum('statut', ['en_attente', 'validee', 'rejetee'])->default('en_attente');
            // ID de ref_personnes (commun) — staff ayant tranché.
            $table->unsignedInteger('traite_par')->nullable()
                ->comment('ID de ref_personnes — pas de FK, table partagée.');
            $table->timestamp('traite_le')->nullable();
            $table->timestamps();

            // Une seule demande EN ATTENTE à la fois par (famille, organisation)
            // — une resoumission avant traitement met à jour la ligne
            // existante plutôt que d'en empiler une seconde (même esprit que
            // IntakeAttenteService::trouverAttenteExistante()), voir
            // FamilleOrganisationDemandeService::creerOuMettreAJour().
            $table->unique(['id_famille', 'id_organisation', 'statut'], 'uq_famille_org_demande_statut');
            $table->index('statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('famille_organisation_demandes');
    }
};
