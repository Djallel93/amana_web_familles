<?php
// database/migrations/2026_08_28_000003_add_id_organisation_to_familles_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : ajoute familles.id_organisation.
 *
 * Organisation qui a ENREGISTRÉ le dossier à l'origine — sert de valeur
 * par défaut (pré-remplissage du formulaire/import) et de source pour le
 * label Google Contacts "principal", mais NE PILOTE PAS la visibilité à
 * elle seule : un dossier peut être rattaché à plusieurs organisations
 * (voir famille_organisation), id_organisation reste la première d'entre
 * elles.
 *
 * Contrairement à id_quartier (commun, colonne sans FK), organisations vit
 * dans cette même base — contrainte FK réelle possible.
 *
 * Backfill : tous les dossiers déjà existants au moment de cette migration
 * n'ont été enregistrés que par AMANA elle-même (aucune autre organisation
 * n'existait avant ce jour) — on les rattache donc rétroactivement à la
 * ligne AMANA, à la fois sur la nouvelle colonne et dans le pivot
 * famille_organisation, pour que le comportement de visibilité (voir
 * FamillesController) et les labels Google Contacts restent cohérents dès
 * le déploiement de cette migration.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('familles', function (Blueprint $table) {
            $table->foreignId('id_organisation')->nullable()->after('id')
                ->constrained('organisations')->nullOnDelete();
        });

        $idAmana = DB::table('organisations')->where('est_principale', true)->value('id');

        if ($idAmana) {
            DB::table('familles')->update(['id_organisation' => $idAmana]);

            $maintenant = now();
            $lignesPivot = DB::table('familles')->pluck('id')->map(fn($idFamille) => [
                'id_famille' => $idFamille,
                'id_organisation' => $idAmana,
                'rattachee_le' => $maintenant,
            ])->all();

            foreach (array_chunk($lignesPivot, 500) as $chunk) {
                DB::table('famille_organisation')->insertOrIgnore($chunk);
            }
        }
    }

    public function down(): void
    {
        Schema::table('familles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_organisation');
        });
    }
};
