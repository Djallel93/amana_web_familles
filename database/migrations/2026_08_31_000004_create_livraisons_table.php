<?php
// database/migrations/2026_08_31_000004_create_livraisons_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table livraisons.
 *
 * Une ligne par famille bénéficiaire par campagne — c'est le pivot central
 * du domaine livraison, côté "sortie" (à distinguer de campagne_arrivees/
 * donations, entièrement côté "entrée"/dons, voir ces migrations). id_famille
 * est une vraie FK locale (familles vit dans cette même base) ; les
 * références vers des personnes (commun) restent de simples colonnes sans
 * contrainte, même convention que partout ailleurs dans ce patch.
 *
 * `id_benevole_impose` : livraison "imposée" à un bénévole précis
 * (décision métier : certaines familles doivent toujours être livrées par
 * la même personne). Résolue AVANT le clustering (retirée du pool,
 * pré-assignée directement, capacité du véhicule du bénévole réduite en
 * amont) et exemptée de toute vérification créneau — logique portée par
 * le service de clustering (Patch 3), pas par ce schéma.
 *
 * `note_besoins_speciaux` : copiée depuis familles.specificites au moment
 * de la génération des livraisons de la campagne, puis éditable
 * indépendamment par livraison (n'écrit jamais vers familles.specificites)
 * — admin/gestionnaire uniquement en écriture, lecture élargie au
 * bénévole (ses propres arrêts), équipe_packaging et équipe_chargement
 * (lecture seule, contexte).Jamais visible équipe_reception/équipe_pesée
 * (aucune donnée famille pour ces deux rôles, voir §4 du prompt).
 *
 * `id_personne_assignee` : qui appelle cette famille pour confirmation
 * téléphonique — doit être un gestionnaire (ou admin, cascade existante),
 * validation faite au niveau applicatif (contrôleur), pas en contrainte
 * DB — impossible de vérifier un rôle `commun` depuis une contrainte MySQL
 * locale.
 *
 * `locked_at`/`locked_by` : même verrouillage d'édition que familles (voir
 * 2026_08_15_000000_add_verrouillage_edition_to_familles.php) — même
 * risque de concurrence quand deux membres du staff éditent la même
 * livraison/route en même temps.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('livraisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_famille')->constrained('familles')->cascadeOnDelete();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->foreignId('id_campagne_journee')->nullable()
                ->constrained('campagne_journees')->nullOnDelete()
                ->comment('Journée précise de la campagne pour laquelle cette livraison est prévue (collecte/livraison à J, J+1... — voir campagne_journees). Nullable : campagnes historiques mono-jour et don_ponctuel n\'ont pas de journée déclarée.');

            $table->enum('statut', ['non_assignee', 'assignee', 'en_cours', 'livree', 'ignoree'])
                ->default('non_assignee');
            $table->enum('statut_conditionnement', ['en_attente', 'prete'])->default('en_attente');

            $table->unsignedTinyInteger('nombre_personnes')
                ->comment('Snapshot du foyer au moment de la génération de la livraison');
            $table->decimal('poids_kg', 6, 2)
                ->comment('Snapshot du poids calculé au moment de la génération de la livraison');

            $table->unsignedInteger('id_benevole_impose')->nullable()
                ->comment('ref_personnes.id — livraison imposée à ce bénévole précis, résolue avant clustering, exemptée de la vérification créneau. Pas de FK, commun est une base séparée.');

            $table->text('note_besoins_speciaux')->nullable()
                ->comment('Copiée depuis familles.specificites à la génération, puis éditable indépendamment (admin/gestionnaire) — ne réécrit jamais familles.specificites');

            // string plutôt qu'enum depuis le 03/09/2026 (voir le prompt de
            // cette date) : la liste des statuts de contact est un point de
            // départ, volontairement amenée à s'enrichir une fois l'app
            // testée en conditions réelles (cas non prévus au contact). Un
            // enum SQL exigerait une migration à chaque ajout ; la liste
            // valide (et l'effet de chacune sur familles.etat_dossier — mise
            // à jour, exclusion, ou ni l'un ni l'autre) vit maintenant dans
            // App\Models\Livraison::STATUTS_CONTACT (voir ce fichier).
            $table->string('statut_contact', 30)->default('a_contacter');
            $table->unsignedInteger('id_personne_assignee')->nullable()
                ->comment('ref_personnes.id du gestionnaire chargé de contacter cette famille — rôle vérifié côté application, pas de FK');

            $table->string('adresse_confirmee', 500)->nullable();
            $table->unsignedTinyInteger('membres_foyer_confirmes')->nullable();

            $table->unsignedInteger('locked_by')->nullable()
                ->comment('ref_personnes.id — même verrouillage que familles.locked_by, pas de FK');
            $table->timestamp('locked_at')->nullable();

            $table->timestamps();

            $table->index(['id_campagne', 'statut']);
            $table->index(['id_campagne', 'statut_contact']);
            $table->index('id_campagne_journee');
            $table->index('id_famille');
            $table->index('locked_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livraisons');
    }
};
