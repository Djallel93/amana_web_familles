<?php
// database/migrations/2026_08_11_000000_create_intake_demandes_attente_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table intake_demandes_attente.
 *
 * Étape intermédiaire ajoutée le 11/08/2026 devant IntakeController::store() :
 * une soumission du formulaire public n'est plus transformée en dossier
 * Famille immédiatement — elle est d'abord stockée ici, un email de
 * confirmation est envoyé à l'adresse fournie, et ce n'est qu'au clic sur
 * le lien (IntakeConfirmationController::confirmer()) que
 * FamilleUpsertService::upsert() est réellement appelé. Objectif : éviter
 * les dossiers créés depuis une adresse email invalide/mal saisie ou un
 * envoi accidentel.
 *
 * Pas de foreignId vers familles : au moment de la création de cette ligne,
 * on ne sait pas encore s'il s'agira d'une création ou d'une mise à jour
 * (la dédup ne s'exécute qu'à la confirmation, voir
 * IntakeConfirmationController).
 *
 * `donnees` : tous les champs validés par IntakeController::store(), sous
 * forme de tableau associatif JSON — mêmes clés que Famille::$fillable,
 * réutilisées telles quelles par FamilleUpsertService::upsert() à la
 * confirmation, pas de retraitement/renommage.
 *
 * `secteurs_activite` / `organismes_aide` : tableaux d'IDs JSON (relations
 * belongsToMany, pas des colonnes de familles — voir FamilleUpsertService::
 * syncListes()).
 *
 * `documents_meta` : nom d'origine + mime par fichier, indexé par
 * "slot:index" (ex. "identite:0") — les fichiers eux-mêmes vivent sur le
 * disque 'local' sous storage/app/private/intake-attente/{token}/, pas en
 * base (voir IntakeController::stockerFichiersAttente()).
 *
 * Pas de updated_at : une ligne est créée à la soumission, puis soit
 * confirmée (auquel cas elle est supprimée par
 * IntakeConfirmationController::confirmer(), plus besoin de la garder),
 * soit purgée une fois expirée par la commande
 * familles:nettoyer-demandes-attente — jamais d'état intermédiaire à
 * tracer.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('intake_demandes_attente', function (Blueprint $table) {
            $table->id();
            $table->string('token', 100)->unique();
            $table->string('langue', 2);
            $table->json('donnees');
            $table->json('secteurs_activite')->nullable();
            $table->json('organismes_aide')->nullable();
            $table->json('documents_meta')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Utilisé par IntakeController::store() pour retrouver une
            // soumission en attente correspondant à la même famille
            // (email, ou téléphone+nom) avant d'en créer une nouvelle —
            // voir FamilleUpsertService::trouverDoublon(), même logique
            // appliquée ici sur les demandes non confirmées.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_demandes_attente');
    }
};
