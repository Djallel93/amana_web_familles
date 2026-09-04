<?php
// database/migrations/2026_09_03_000000_create_campagne_journees_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table campagne_journees — voir le prompt du 03/09/2026
 * (domaine livraison, gestion des campagnes multi-jours).
 *
 * Une campagne peut désormais s'étaler sur plusieurs journées de
 * collecte/livraison (ex: collecte alimentaire collectée le jour J,
 * livrée à J+1 ou J+2 ; zakat el-fitr où un jour de collecte
 * supplémentaire peut être décidé en cours de campagne, l'après-midi
 * même, s'il reste un jour de ramadan). Décision du 03/09/2026 : UNE
 * campagne, PLUSIEURS journées — plutôt qu'une nouvelle campagne par
 * jour — pour garder un seul jeu de familles/contacts/stats par
 * opération (voir Campagne::getNombreMenagesAttribute() etc., qui
 * resteraient éclatés entre campagnes sinon).
 *
 * `campagnes.date_livraison` reste en base (voir migration campagnes),
 * réinterprétée comme date de RÉFÉRENCE (première journée, utilisée pour
 * le tri/affichage existant) plutôt que la seule date de l'opération —
 * voir Campagne::premiereJournee()/syncDateReference(). Ne pas la
 * supprimer évite de casser tout le code existant qui la lit encore
 * (tri des listes, exports, etc.) ; les écrans qui doivent raisonner
 * "quel jour" passent par journees() désormais.
 *
 * `label` optionnel : sert à distinguer les journées d'une même
 * opération (ex: "Collecte", "Livraison", ou "Jour ajouté" pour le cas
 * zakat el-fitr ci-dessus) — affiché dans les onglets du tableau de bord
 * livraison, purement informatif, aucune logique n'en dépend.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('campagne_journees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->date('date');
            $table->string('label', 100)->nullable();
            $table->unsignedTinyInteger('ordre')
                ->comment('Ordre d\'affichage/chronologique au sein de la campagne — pas forcément égal au tri par date si une journée est ajoutée après coup avec une date antérieure à une correction ultérieure');
            $table->timestamps();

            $table->unique(['id_campagne', 'date']);
            $table->index(['id_campagne', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campagne_journees');
    }
};
