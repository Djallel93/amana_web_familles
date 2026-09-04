<?php
// database/migrations/2026_08_31_000009_create_routes_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table routes.
 *
 * Une tournée = un bénévole, un créneau, un ensemble ordonné d'arrêts
 * (voir etapes_route). Table nommée `routes` au niveau DB, mais modèle
 * Eloquent nommé App\Models\RouteLivraison (et non `Route`) — décision du
 * 31/08/2026, pour ne jamais entrer en collision avec la façade Laravel
 * Illuminate\Support\Facades\Route (utilisée partout dans routes/web.php).
 * Seul le nom de la classe change, la table reste `routes`.
 *
 * `id_benevole`/`id_vehicule_type` : commun, pas de FK (même convention
 * que le reste de ce patch). `creneau` : voir livraison_creneaux pour le
 * raisonnement string/pas-enum. `locked_at`/`locked_by` : même
 * verrouillage d'édition que familles/livraisons (concurrence possible
 * quand deux membres du staff modifient la même tournée).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->foreignId('id_campagne_journee')->nullable()
                ->constrained('campagne_journees')->nullOnDelete()
                ->comment('Journée de campagne pour laquelle cette tournée est générée — voir campagne_journees et livraisons.id_campagne_journee');
            $table->unsignedInteger('id_benevole')
                ->comment('ref_personnes.id — pas de FK, commun est une base séparée');
            $table->unsignedInteger('id_vehicule_type')
                ->comment('ref_vehicules.id — pas de FK, commun est une base séparée');
            $table->string('creneau', 5)
                ->comment('Une des 6 valeurs de App\\Support\\Creneau::TOUS — créneau pour lequel cette tournée a été générée');

            // 'livraisons_terminees' ajouté le 03/09/2026 (voir le prompt de
            // cette date, écran "Ma tournée" bénévole) : état intermédiaire
            // entre "en_cours" et "terminee" — tous les arrêts sont
            // livrés/ignorés mais le bénévole n'a pas encore confirmé son
            // retour au QG (bouton "Livraison terminé", séparé de "Retour
            // QG" — voir App\Http\Controllers\Livraison\MaRouteController).
            // Permet à l'admin/gestionnaire de voir "tournée finie sur le
            // terrain" même si le bénévole ne tape jamais le second bouton.
            $table->enum('statut', ['planifiee', 'chargement', 'en_cours', 'livraisons_terminees', 'terminee'])
                ->default('planifiee');
            $table->decimal('distance_totale_km', 6, 2)->nullable();
            $table->decimal('poids_total_kg', 7, 2)->nullable();
            $table->text('lien_maps')->nullable();

            $table->unsignedInteger('locked_by')->nullable()
                ->comment('ref_personnes.id — même verrouillage que familles.locked_by, pas de FK');
            $table->timestamp('locked_at')->nullable();

            $table->timestamps();

            $table->index(['id_campagne', 'creneau']);
            $table->index('id_benevole');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
