<?php
// database/migrations/2026_09_03_000001_create_benevole_retours_qg_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table benevole_retours_qg — voir le prompt du 03/09/2026
 * (écran "Ma tournée" bénévole, boutons "Livraison terminé" / "Retour
 * QG").
 *
 * Une ligne = un bénévole s'est déclaré de retour au QG et disponible
 * pour repartir sur une nouvelle tournée, pour une campagne donnée.
 * Volontairement une table séparée plutôt qu'un simple flag sur
 * ref_personnes (commun — appartiendrait à toutes les apps AMANA pour un
 * concept propre au domaine livraison) ou sur routes (la disponibilité
 * du bénévole survit à la tournée qu'il vient de terminer, ce n'est pas
 * un attribut de CETTE tournée mais de la PERSONNE, le temps qu'une
 * nouvelle tournée lui soit assignée).
 *
 * `recupere_le` : renseigné quand admin/gestionnaire inclut ce bénévole
 * dans un nouveau lot de tournées (RouteGenerationService) — la ligne
 * n'est pas supprimée à ce moment (traçabilité : combien de fois ce
 * bénévole a fait des allers-retours dans la journée), juste marquée
 * consommée. Une personne ne peut avoir qu'UNE ligne "non récupérée" à
 * la fois par campagne (contrainte applicative, voir
 * MaRouteController::retourQg() — pas une contrainte unique partielle en
 * DB, MySQL ne les supporte pas nativement).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('benevole_retours_qg', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->unsignedInteger('id_personne')
                ->comment('ref_personnes.id — pas de FK, commun est une base séparée');
            $table->foreignId('id_route_origine')->nullable()
                ->constrained('routes')->nullOnDelete()
                ->comment('Tournée dont ce retour découle — traçabilité uniquement');
            $table->timestamp('disponible_depuis');
            $table->timestamp('recupere_le')->nullable()
                ->comment('Renseigné quand ce bénévole a été inclus dans un nouveau lot de tournées — voir RouteGenerationService');

            $table->index(['id_campagne', 'id_personne', 'recupere_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benevole_retours_qg');
    }
};
