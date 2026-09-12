<?php
// database/migrations/2026_08_31_000004_create_livraison_operations_tables.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : domaine Livraison — Opérations — squash du 10/09/2026
 * (Section D du refactor) de 4 migrations d'origine :
 *   - 2026_08_31_000004_create_livraisons_table.php
 *   - 2026_08_31_000200_revise_livraisons_confirmation_fields.php
 *   - 2026_08_31_000005_create_livraison_creneaux_table.php
 *   - 2026_09_05_000002_create_livraison_colis_table.php
 *   - 2026_08_31_000006_create_contact_tokens_table.php
 *
 * `livraisons` ci-dessous porte directement la forme FINALE des champs de
 * confirmation (code_postal_confirme/ville_confirmee/nombre_adulte_confirme/
 * nombre_enfant_confirme) — `membres_foyer_confirmes` (le champ d'origine,
 * remplacé le 31/08/2026 par ces 4 colonnes à granularité fine) n'existe
 * donc jamais dans ce schéma squashé.
 */
return new class extends Migration {
    public function up(): void
    {
        // Une ligne par famille bénéficiaire par campagne — c'est le pivot
        // central du domaine livraison, côté "sortie" (à distinguer de
        // campagne_arrivees/donations, entièrement côté "entrée"/dons).
        // id_famille est une vraie FK locale (familles vit dans cette même
        // base) ; les références vers des personnes (commun) restent de
        // simples colonnes sans contrainte, même convention que partout
        // ailleurs dans ce domaine.
        //
        // `id_benevole_impose` : livraison "imposée" à un bénévole précis
        // (décision métier : certaines familles doivent toujours être
        // livrées par la même personne). Résolue AVANT le clustering
        // (retirée du pool, pré-assignée directement, capacité du véhicule
        // du bénévole réduite en amont) et exemptée de toute vérification
        // créneau.
        //
        // `note_besoins_speciaux` : copiée depuis familles.specificites au
        // moment de la génération des livraisons de la campagne, puis
        // éditable indépendamment par livraison (n'écrit jamais vers
        // familles.specificites) — admin/gestionnaire uniquement en
        // écriture, lecture élargie au bénévole (ses propres arrêts),
        // équipe_packaging et équipe_chargement (lecture seule, contexte).
        // Jamais visible équipe_reception/équipe_pesée (aucune donnée
        // famille pour ces deux rôles).
        //
        // `id_personne_assignee` : qui appelle cette famille pour
        // confirmation téléphonique — doit être un gestionnaire (ou admin,
        // cascade existante), validation faite au niveau applicatif
        // (contrôleur), pas en contrainte DB.
        //
        // `locked_at`/`locked_by` : même verrouillage d'édition que
        // familles.
        //
        // Champs de confirmation code_postal_confirme/ville_confirmee/
        // nombre_adulte_confirme/nombre_enfant_confirme (forme finale,
        // revue le 31/08/2026 — voir docblock de fichier) : l'adresse et
        // la composition du foyer confirmées pendant une campagne
        // (formulaire public OU saisie téléphonique gestionnaire)
        // réécrivent désormais familles.adresse/code_postal/ville_texte/
        // nombre_adulte/nombre_enfant — familles reste la SEULE source de
        // vérité (pas de coordonnées ni de foyer dupliqués au niveau
        // livraison), toute modification d'adresse déclenchant
        // App\Jobs\ResoudreAdresseFamille (voir
        // App\Services\FamilleConfirmationSyncService). Ces colonnes
        // restent un SNAPSHOT par livraison (utile pour la feuille de
        // préparation/impression, l'historique) EN PLUS de la réécriture
        // vers familles — les deux ne s'excluent pas. Même granularité que
        // familles (adresse éclatée en 3 colonnes qui alimentent ENSEMBLE
        // le géocodage ; nombre_adulte/nombre_enfant séparés, nombre_enfant
        // pilotant en plus la règle métier "ajouter jouets/bonbons").
        Schema::create('livraisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_famille')->constrained('familles')->cascadeOnDelete();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->foreignId('id_campagne_journee')->nullable()
                ->constrained('campagne_journees')->nullOnDelete()
                ->comment('Journée précise de la campagne pour laquelle cette livraison est prévue (collecte/livraison à J, J+1... — voir campagne_journees). Nullable : campagnes historiques mono-jour et don_ponctuel n\'ont pas de journée déclarée.');

            $table->enum('statut', ['non_assignee', 'assignee', 'en_cours', 'livree', 'ignoree'])
                ->default('non_assignee');
            // 'en_cours' : certains colis du foyer sont prêts mais pas
            // tous — un vrai statut posé par marquerColisPret() pour
            // simplifier les requêtes et permettre un badge par livraison
            // (pas seulement une carte statistique agrégée).
            $table->enum('statut_conditionnement', ['en_attente', 'en_cours', 'prete'])->default('en_attente');

            $table->unsignedTinyInteger('nombre_personnes')
                ->comment('Snapshot du foyer au moment de la génération de la livraison');
            $table->decimal('poids_kg', 6, 2)
                ->comment('Snapshot du poids calculé au moment de la génération de la livraison');

            $table->unsignedInteger('id_benevole_impose')->nullable()
                ->comment('ref_personnes.id — livraison imposée à ce bénévole précis, résolue avant clustering, exemptée de la vérification créneau. Pas de FK, commun est une base séparée.');

            $table->text('note_besoins_speciaux')->nullable()
                ->comment('Copiée depuis familles.specificites à la génération, puis éditable indépendamment (admin/gestionnaire) — ne réécrit jamais familles.specificites');

            // string plutôt qu'enum : la liste des statuts de contact est
            // un point de départ, volontairement amenée à s'enrichir une
            // fois l'app testée en conditions réelles (cas non prévus au
            // contact). Un enum SQL exigerait une migration à chaque
            // ajout ; la liste valide (et l'effet de chacune sur
            // familles.etat_dossier — mise à jour, exclusion, ou ni l'un
            // ni l'autre) vit dans App\Models\Livraison::STATUTS_CONTACT.
            $table->string('statut_contact', 30)->default('a_contacter');
            $table->unsignedInteger('id_personne_assignee')->nullable()
                ->comment('ref_personnes.id du gestionnaire chargé de contacter cette famille — rôle vérifié côté application, pas de FK');

            $table->string('adresse_confirmee', 500)->nullable();
            $table->string('code_postal_confirme', 10)->nullable();
            $table->string('ville_confirmee', 150)->nullable();
            $table->unsignedTinyInteger('nombre_adulte_confirme')->nullable();
            $table->unsignedTinyInteger('nombre_enfant_confirme')->nullable();

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

        // Pivot : créneaux de 2h (8h-19h) pour lesquels une famille est
        // disponible pour CETTE livraison. `creneau` est une colonne
        // string, PAS un enum MySQL : la liste des 6 valeurs fixes est
        // définie une seule fois en PHP (App\Support\Creneau::TOUS),
        // utilisée à la fois pour la validation applicative et pour
        // peupler les <select> — un enum MySQL dupliquerait cette liste
        // dans le schéma et pourrait diverger silencieusement de la
        // constante PHP.
        //
        // Volontairement pas de champ campagne-configurable : liste fixe,
        // non paramétrable par campagne.
        Schema::create('livraison_creneaux', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_livraison')->constrained('livraisons')->cascadeOnDelete();
            $table->string('creneau', 5)
                ->comment('Une des 6 valeurs de App\\Support\\Creneau::TOUS (08-10 .. 18-19) — pas un enum MySQL, voir docblock de migration');

            $table->unique(['id_livraison', 'creneau']);
        });

        // Une famille est composée de N colis — un par personne
        // (livraisons.nombre_personnes), déjà imprimés séparément ("colis
        // X/N", voir PackagingController::etiquettes()). Cette table donne
        // à l'écran packaging une ligne par colis réel à cocher, plutôt
        // qu'une seule case pour toute la famille.
        //
        // Une ligne par colis est créée en même temps que la Livraison
        // (voir LivraisonGenerationService::genererPour()), `numero` allant
        // de 1 à nombre_personnes. livraisons.statut_conditionnement reste
        // la valeur AGRÉGÉE dérivée de cette table (voir
        // PackagingController::marquerColisPret()/annulerConditionnement()) :
        // 'prete' seulement quand tous les colis de la famille sont
        // 'pret', jamais mise à jour directement pour une famille qui a
        // des colis restants — c'est le mécanisme qui permet de griser la
        // case "famille entière" tant que tout n'est pas prêt.
        //
        // pret_par n'est PAS une contrainte de propriété : n'importe quel
        // membre de equipe_packaging peut cocher/décocher n'importe quel
        // colis (même principe que pour la pesée/réception) — cette
        // colonne trace seulement QUI a fait le dernier changement, à
        // titre informatif.
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

        // Jetons à usage unique, expirables, pour le formulaire public de
        // confirmation famille (adresse, membres du foyer, créneaux
        // disponibles). Générés uniquement pour les familles disposant
        // d'un email — sinon, contact téléphonique par le staff (voir
        // livraisons.statut_contact/id_personne_assignee).
        //
        // `token` haché au repos, jamais stocké en clair — seul le hash
        // est comparé à la réception. `used_at` empêche toute réutilisation
        // après soumission. La route publique de confirmation ne doit
        // résoudre QUE la livraison scopée à ce jeton, jamais d'autres
        // données famille.
        Schema::create('contact_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_livraison')->constrained('livraisons')->cascadeOnDelete();
            $table->string('token', 100)->unique()
                ->comment('Haché au repos (hash("sha256", ...)) — jamais stocké en clair');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index('id_livraison');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_tokens');
        Schema::dropIfExists('livraison_colis');
        Schema::dropIfExists('livraison_creneaux');
        Schema::dropIfExists('livraisons');
    }
};
