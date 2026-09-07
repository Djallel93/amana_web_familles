<?php
// database/migrations/2026_09_05_000002_create_livraison_colis_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table livraison_colis.
 *
 * Voir le prompt du 05/09/2026 §5.3 : l'écran packaging ne suivait
 * jusqu'ici qu'un seul booléen par FAMILLE (livraisons.statut_conditionnement),
 * alors que le reste de l'app (étiquettes, voir
 * PackagingController::etiquettes()/resources/views/livraison/etiquettes.blade.php)
 * traite chaque famille comme composée de N colis — un par personne
 * (livraisons.nombre_personnes), déjà imprimés séparément ("colis X/N").
 * Cette table donne enfin à l'écran packaging une ligne par colis réel à
 * cocher, plutôt qu'une seule case pour toute la famille.
 *
 * Une ligne par colis est créée en même temps que la Livraison (voir
 * LivraisonGenerationService::genererPour()), `numero` allant de 1 à
 * nombre_personnes. livraisons.statut_conditionnement reste la valeur
 * AGRÉGÉE dérivée de cette table (voir PackagingController::marquerColisPret()/
 * annulerConditionnement()) : 'prete' seulement quand tous les colis de
 * la famille sont 'pret', jamais mise à jour directement pour une famille
 * qui a des colis restants — c'est le mécanisme qui permet de griser la
 * case "famille entière" tant que tout n'est pas prêt (voir le prompt).
 *
 * pret_par n'est PAS une contrainte de propriété : n'importe quel membre
 * de equipe_packaging peut cocher/décocher n'importe quel colis (décision
 * du 05/09/2026, même principe que pour la pesée/réception) — cette
 * colonne trace seulement QUI a fait le dernier changement, à titre
 * informatif.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('livraison_colis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_livraison')->constrained('livraisons')->cascadeOnDelete();
            $table->unsignedTinyInteger('numero')
                ->comment('1 à nombre_personnes — même numérotation que "Colis X/N" sur les étiquettes imprimées');
            $table->enum('statut', ['a_preparer', 'pret'])->default('a_preparer');
            $table->timestamp('pret_le')->nullable();
            $table->unsignedInteger('pret_par')->nullable()
                ->comment('ref_personnes.id de la dernière personne à avoir changé le statut de CE colis — pas de FK, commun est une base séparée, pas une contrainte de propriété (voir docblock de migration)');

            $table->unique(['id_livraison', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livraison_colis');
    }
};
