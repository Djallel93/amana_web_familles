<?php
// database/migrations/2026_08_31_000200_revise_livraisons_confirmation_fields.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Révise les colonnes de confirmation de livraisons pour correspondre
 * exactement à la granularité de familles — migration ADDITIVE distincte
 * de 2026_08_31_000004_create_livraisons_table.php (déjà livrée dans le
 * Patch 1), plutôt qu'une modification de cette migration existante.
 *
 * Décision du 31/08/2026 : l'adresse et la composition du foyer confirmées
 * pendant une campagne (formulaire public OU saisie téléphonique
 * gestionnaire) réécrivent désormais familles.adresse/code_postal/
 * ville_texte/nombre_adulte/nombre_enfant — familles reste la SEULE
 * source de vérité pour l'adresse et la composition du foyer (pas de
 * coordonnées ni de foyer dupliqués au niveau livraison). Toute
 * modification d'adresse déclenche App\Jobs\ResoudreAdresseFamille (même
 * mécanisme que FamillesController/IntakeConfirmationController) — voir
 * App\Services\FamilleConfirmationSyncService.
 *
 * Ce choix a révélé deux inadéquations de granularité entre le schéma du
 * Patch 1 et celui de familles :
 *
 *  1. `adresse_confirmee` était un seul champ libre, mais familles éclate
 *     l'adresse en 3 colonnes (adresse/code_postal/ville_texte) qui
 *     alimentent ENSEMBLE le géocodage — ne réécrire que `adresse` en
 *     laissant un code postal/ville périmés aurait pu fournir une adresse
 *     incohérente au géocodeur. Ajout de code_postal_confirme/
 *     ville_confirmee en miroir exact des colonnes familles.
 *
 *  2. `membres_foyer_confirmes` était un total unique, mais familles
 *     distingue nombre_adulte/nombre_enfant séparément — nombre_enfant
 *     pilote en plus la règle métier "ajouter jouets/bonbons" (§1 du
 *     prompt du 30/08/2026). Un total seul ne peut pas être reventilé
 *     sans deviner la répartition. Remplacé par
 *     nombre_adulte_confirme/nombre_enfant_confirme, même granularité que
 *     familles.
 *
 * Ces colonnes restent un SNAPSHOT par livraison (utile pour la feuille
 * de préparation/impression, l'historique) EN PLUS de la réécriture vers
 * familles — les deux ne s'excluent pas.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('livraisons', function (Blueprint $table) {
            $table->dropColumn('membres_foyer_confirmes');

            $table->string('code_postal_confirme', 10)->nullable()->after('adresse_confirmee');
            $table->string('ville_confirmee', 150)->nullable()->after('code_postal_confirme');
            $table->unsignedTinyInteger('nombre_adulte_confirme')->nullable()->after('ville_confirmee');
            $table->unsignedTinyInteger('nombre_enfant_confirme')->nullable()->after('nombre_adulte_confirme');
        });
    }

    public function down(): void
    {
        Schema::table('livraisons', function (Blueprint $table) {
            $table->dropColumn(['code_postal_confirme', 'ville_confirmee', 'nombre_adulte_confirme', 'nombre_enfant_confirme']);
            $table->unsignedTinyInteger('membres_foyer_confirmes')->nullable();
        });
    }
};
