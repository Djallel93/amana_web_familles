<?php
// database/migrations/2026_08_31_000005_create_livraison_creneaux_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table livraison_creneaux.
 *
 * Pivot : créneaux de 2h (8h-19h) pour lesquels une famille est disponible
 * pour CETTE livraison. `creneau` est une colonne string, PAS un enum
 * MySQL : la liste des 6 valeurs fixes est définie une seule fois en PHP
 * (App\Support\Creneau::TOUS), utilisée à la fois pour la validation
 * applicative et pour peupler les <select> — un enum MySQL dupliquerait
 * cette liste dans le schéma et pourrait diverger silencieusement de la
 * constante PHP (ex : ajout d'une valeur d'un côté, oubli de l'autre).
 *
 * Volontairement pas de champ campagne-configurable : liste fixe, non
 * paramétrable par campagne (voir §2 du prompt du 30/08/2026).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('livraison_creneaux', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_livraison')->constrained('livraisons')->cascadeOnDelete();
            $table->string('creneau', 5)
                ->comment('Une des 6 valeurs de App\\Support\\Creneau::TOUS (08-10 .. 18-19) — pas un enum MySQL, voir docblock de migration');

            $table->unique(['id_livraison', 'creneau']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livraison_creneaux');
    }
};
