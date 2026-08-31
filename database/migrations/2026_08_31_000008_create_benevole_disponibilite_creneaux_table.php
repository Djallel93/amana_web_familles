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
            // Colonne déclarée séparément de la contrainte FK (plutôt que
            // foreignId(...)->constrained(...)) pour pouvoir donner un nom
            // de contrainte explicite et court via la forme documentée
            // $table->foreign($column, $name) : le nom auto-généré par
            // Laravel ("benevole_disponibilite_creneaux_id_benevole_
            // disponibilite_foreign", 65 caractères) dépasse la limite
            // d'identifiant MySQL (64) — découvert en testant la
            // migration (SQLSTATE[42000] 1059). Même précaution que
            // l'index unique juste en-dessous, qui avait déjà un nom
            // explicite pour la même raison.
            $table->foreignId('id_benevole_disponibilite');
            $table->string('creneau', 5)
                ->comment('Une des 6 valeurs de App\\Support\\Creneau::TOUS');

            $table->foreign('id_benevole_disponibilite', 'benevole_dispo_creneaux_id_dispo_fk')
                ->references('id')->on('benevole_disponibilites')
                ->cascadeOnDelete();

            $table->unique(['id_benevole_disponibilite', 'creneau'], 'benevole_dispo_creneaux_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benevole_disponibilite_creneaux');
    }
};
