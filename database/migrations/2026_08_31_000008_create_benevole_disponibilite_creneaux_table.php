<?php
// database/migrations/2026_08_31_000008_create_benevole_disponibilite_creneaux_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table benevole_disponibilite_creneaux.
 *
 * Pivot : créneaux pour lesquels un bénévole se déclare disponible sur une
 * campagne donnée. Même liste fixe de 6 valeurs que livraison_creneaux
 * (App\Support\Creneau::TOUS) — voir cette migration pour le raisonnement
 * "string, pas enum MySQL".
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('benevole_disponibilite_creneaux', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_benevole_disponibilite')
                ->constrained('benevole_disponibilites')
                ->cascadeOnDelete();
            $table->string('creneau', 5)
                ->comment('Une des 6 valeurs de App\\Support\\Creneau::TOUS');

            $table->unique(['id_benevole_disponibilite', 'creneau'], 'benevole_dispo_creneaux_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benevole_disponibilite_creneaux');
    }
};
