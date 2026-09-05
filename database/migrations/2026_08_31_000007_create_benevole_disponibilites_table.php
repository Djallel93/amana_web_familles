<?php
// database/migrations/2026_08_31_000007_create_benevole_disponibilites_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table benevole_disponibilites.
 *
 * Confirmation de disponibilité d'un bénévole (= chauffeur potentiel —
 * "chauffeur" n'est pas un rôle séparé, c'est benevole + un
 * BenevoleProfil avec véhicule/permis, voir §4 du prompt du 30/08/2026)
 * pour UNE journée de campagne (`CampagneJournee`) donnée. `id_personne`
 * référence commun (pas de FK, même convention que le reste de ce
 * patch) ; unique par (id_personne, id_campagne_journee) — une seule
 * ligne de disponibilité par bénévole et par journée, éditable à tout
 * moment après la confirmation initiale (pas de flux "renvoyer le
 * formulaire").
 *
 * SCOPING PAR JOURNÉE (05/09/2026, suivi du patch multi-jours du
 * 03/09/2026) : un bénévole peut être disponible le jour de collecte
 * d'une campagne mais pas le jour de livraison (ou l'inverse) — la
 * disponibilité est donc explicitement par `CampagneJournee`, jamais par
 * `Campagne` directement (voir CampagneJournee::disponibilites()). Ceci
 * ne dégrade pas le cas mono-journée : `CampagnesController::store()`
 * crée désormais systématiquement une `CampagneJournee` dès la création
 * de la campagne (voir Campagne::syncDateReference() côté journée), donc
 * `id_campagne_journee` est toujours renseignable, y compris pour une
 * campagne "classique" à une seule journée. Pas de colonne id_campagne
 * dénormalisée ici : la campagne reste accessible via
 * `$disponibilite->journee->campagne` (voir BenevoleDisponibilite).
 *
 * `vehicule_confirme` : le bénévole confirme que son véhicule correspond
 * toujours à BenevoleProfil.id_vehicule_type (commun) — pas de
 * re-saisie ici, juste une confirmation booléenne.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('benevole_disponibilites', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('id_personne')
                ->comment('ref_personnes.id — pas de FK, commun est une base séparée');
            $table->foreignId('id_campagne_journee')->constrained('campagne_journees')->cascadeOnDelete();

            $table->boolean('vehicule_confirme')->default(false);
            $table->boolean('coverage_confirmee')->default(false);
            $table->text('coverage_notes')->nullable();
            $table->enum('statut', ['non_confirme', 'confirme'])->default('non_confirme');

            $table->timestamps();

            $table->unique(['id_personne', 'id_campagne_journee']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benevole_disponibilites');
    }
};
