<?php
// database/migrations/2026_09_05_000001_create_campagne_poids_moyen_historiques_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table campagne_poids_moyen_historiques.
 *
 * Journal des modifications de poids_moyen_kg/poids_moyen_hotel_kg/
 * poids_moyen_etudiant_kg sur une campagne — voir le prompt du 05/09/2026
 * §5.2. Ces colonnes restent de vrais paramètres ajustables à la main en
 * cours de campagne (voir create_campagnes_table.php), mais
 * Livraison::calculerPoidsKg() ne s'exécutant qu'UNE FOIS à la génération
 * (poids_kg est un instantané, jamais recalculé automatiquement), rien ne
 * permettait jusqu'ici de savoir QUAND/DE COMBIEN un taux a changé en
 * cours de route — nécessaire pour comprendre pourquoi deux lots de
 * familles générés à des moments différents de la même campagne peuvent
 * porter des poids différents.
 *
 * Une ligne par modification effective (voir
 * CampagnesController::mettreAJourPoidsMoyen() : rien n'est écrit si la
 * valeur soumise est identique à l'existante). Pas de lien vers les
 * livraisons concernées : ce journal documente l'évolution du PARAMÈTRE,
 * pas quelles livraisons ont été générées sous quel taux (cette
 * information est de toute façon déjà portée par livraisons.poids_kg
 * lui-même, figé à la génération).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('campagne_poids_moyen_historiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->enum('type', ['normal', 'hotel', 'etudiant'])
                ->comment('À quelle colonne ce changement se rapporte : poids_moyen_kg (normal), poids_moyen_hotel_kg ou poids_moyen_etudiant_kg');
            $table->decimal('ancienne_valeur', 6, 2);
            $table->decimal('nouvelle_valeur', 6, 2);
            $table->timestamp('horodatage')->useCurrent();
            $table->unsignedInteger('logge_par')
                ->comment('ref_personnes.id de la personne ayant modifié le taux — pas de FK, commun est une base séparée');

            $table->index(['id_campagne', 'horodatage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campagne_poids_moyen_historiques');
    }
};
