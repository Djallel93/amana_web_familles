<?php
// database/migrations/2026_07_12_000004_create_familles_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table familles.
 *
 * Remplace la feuille "Famille" de amana_familles (Google Apps Script,
 * ~21 colonnes de sortie + champs supplémentaires côté formulaire).
 *
 * Champs `code_postal` / `ville_texte` : valeurs brutes soumises par la
 * famille, distinctes de `id_quartier` qui est la valeur RÉSOLUE par
 * géocodage (webhook Make.com → lat/lng → ST_Contains). Les deux coexistent
 * volontairement : on garde la saisie brute même si la résolution échoue
 * ou est corrigée manuellement ensuite.
 *
 * Aucune donnée migrée dans cette phase (décision 6.8 — les 130 familles
 * existantes seront importées dans une étape ultérieure séparée). Cette
 * migration ne fait que créer le schéma.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('familles', function (Blueprint $table) {
            $table->id();

            // ── Identité & contact ───────────────────────────────────────
            $table->string('nom', 150);
            $table->string('prenom', 150);
            $table->string('email', 255)->nullable();
            $table->string('telephone', 30);
            $table->string('telephone_bis', 30)->nullable();

            // ── Éligibilité ───────────────────────────────────────────────
            $table->boolean('zakat_el_fitr')->default(false);
            $table->boolean('sadaqa')->default(false);

            // ── Composition du foyer ─────────────────────────────────────
            $table->unsignedTinyInteger('nombre_adulte')->default(0);
            $table->unsignedTinyInteger('nombre_enfant')->default(0);

            // ── Adresse & résolution géographique ────────────────────────
            $table->text('adresse');
            $table->string('code_postal', 10)->nullable()
                ->comment('Valeur brute soumise par la famille');
            $table->string('ville_texte', 150)->nullable()
                ->comment('Valeur brute soumise par la famille, avant résolution');
            $table->foreignId('id_quartier')->nullable()
                ->comment('Valeur résolue par géocodage (ST_Contains) — pas de contrainte FK : quartiers vit dans amana_commun (amana/shared) depuis le 21/07/2026, hors de portée d\'une FK MySQL cross-DB. Relation Eloquent uniquement, voir App\\Models\\Famille::quartier().');
            $table->boolean('se_deplace')->default(false);

            // ── Situation & aide ──────────────────────────────────────────
            $table->text('circonstances')->nullable();
            $table->text('ressentit')->nullable();
            $table->text('specificites')->nullable();
            $table->unsignedTinyInteger('criticite')->default(0)
                ->comment('Échelle 0 à 5');
            $table->string('langue', 2)->default('fr')
                ->comment('fr, ar, en');
            $table->enum('etat_dossier', [
                'Recu', 'En cours', 'En attente', 'Validé', 'Rejeté', 'Archivé',
            ])->default('Recu');
            $table->text('commentaire_dossier')->nullable();

            // ── Champs supplémentaires côté formulaire d'intake ──────────
            $table->boolean('hosted')->nullable();
            $table->string('hosted_by', 255)->nullable();
            $table->boolean('working')->nullable();
            $table->unsignedTinyInteger('work_days')->nullable();
            $table->string('work_sector', 150)->nullable();
            $table->boolean('other_aid')->nullable();

            $table->timestamps();

            // ── Index (filtres de la vue principale — section 8.2) ───────
            $table->index('etat_dossier');
            $table->index('id_quartier');
            $table->index('telephone');
            $table->index('zakat_el_fitr');
            $table->index('sadaqa');
            $table->index('criticite');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('familles');
    }
};
