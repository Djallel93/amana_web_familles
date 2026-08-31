<?php
// database/migrations/2026_08_31_000002_create_campagne_arrivees_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table campagne_arrivees.
 *
 * Journal de comptage des DONATEURS au poste "parking" (pas des familles
 * bénéficiaires — clarifié le 31/08/2026, voir échange sur le sens de
 * cette table). Chaque ligne représente une tape "+1" faite par la
 * personne qui tient le poste au fil des arrivées, au moment où elles se
 * produisent — pas un chiffre absolu ressaisi a posteriori.
 *
 * `nombre_donateur` (et non `nombre_foyers` ou un simple +1 implicite) :
 * pour collecte_alimentaire/don_ponctuel c'est presque toujours 1 (un
 * donateur = un foyer), mais pour zakat_el_fitr une seule personne peut
 * apporter la zakat el-fitr de son propre foyer ET de voisins/proches
 * qu'elle représente — le poste doit donc pouvoir enregistrer combien de
 * donateurs cette arrivée représente, pas juste "encore une voiture".
 * campagnes.nombre_menages = somme de cette colonne pour la campagne (voir
 * create_campagnes_table.php).
 *
 * Aucune identité de donateur n'est enregistrée (décision explicite :
 * "nous n'enregistrons pas qui a donné quoi") — logge_par identifie
 * uniquement le membre du staff qui tient le poste, jamais le donateur.
 * Aucun lien vers familles/livraisons : ce journal est entièrement du
 * côté "entrée" (dons), complètement indépendant du côté "sortie"
 * (livraisons aux familles bénéficiaires).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('campagne_arrivees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->unsignedSmallInteger('nombre_donateur')->default(1)
                ->comment('Nombre de donateurs représentés par cette arrivée — 1 en général, >1 pour zakat_el_fitr (une personne couvrant plusieurs foyers)');
            $table->timestamp('horodatage')->useCurrent();
            // ref_personnes.id est un increments() (unsigned INT), pas un
            // id() Laravel standard — voir même convention documentée dans
            // 2026_08_15_000000_add_verrouillage_edition_to_familles.php.
            // Pas de contrainte FK : commun est une base séparée.
            $table->unsignedInteger('logge_par')
                ->comment('ref_personnes.id du membre du staff tenant le poste — pas de FK, commun est une base séparée');

            $table->index('id_campagne');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campagne_arrivees');
    }
};
