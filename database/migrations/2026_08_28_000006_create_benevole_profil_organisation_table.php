<?php
// database/migrations/2026_08_28_000006_create_benevole_profil_organisation_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table benevole_profil_organisation.
 *
 * Réponse à la nouvelle question "organisation" du formulaire public de
 * candidature bénévole (BenevoleForm.vue) — décision du 28/08/2026 :
 * question obligatoire, une seule organisation par bénévole (contrairement
 * à personne_organisation, pas de N-N ici — pas de cas d'usage identifié
 * pour qu'un même bénévole se rattache à plusieurs organisations
 * simultanément).
 *
 * benevole_profils vit dans amana_commun (voir Amana\Shared\Models\
 * BenevoleProfil), organisations dans CETTE base — même convention
 * cross-DB que personne_organisation ci-dessus : colonne id_benevole_profil
 * SANS contrainte FK, id_organisation avec contrainte FK réelle.
 *
 * Table séparée plutôt qu'une simple colonne id_organisation sur
 * benevole_profils : ce dernier modèle est partagé (Amana\Shared\Models\
 * BenevoleProfil, consommé aussi par d'autres apps AMANA à terme) — y
 * ajouter une colonne qui n'a de sens que pour amana_web_familles
 * contredirait la décision du 28/08/2026 de garder Organisation local à
 * cette app (voir create_organisations_table).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('benevole_profil_organisation', function (Blueprint $table) {
            $table->unsignedBigInteger('id_benevole_profil')->unique()
                ->comment('ID de benevole_profils (amana_commun) — pas de FK, table partagée cross-DB. unique() : une seule organisation par bénévole.');
            $table->foreignId('id_organisation')->constrained('organisations')->cascadeOnDelete();
            $table->timestamp('rattachee_le')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benevole_profil_organisation');
    }
};
