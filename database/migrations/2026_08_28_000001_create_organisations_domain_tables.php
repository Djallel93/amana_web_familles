<?php
// database/migrations/2026_08_28_000001_create_organisations_domain_tables.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : domaine Organisations — squash du 10/09/2026 (Section D du
 * refactor) de 7 migrations d'origine :
 *   - 2026_08_28_000001_create_organisations_table.php
 *   - 2026_08_28_000003_add_id_organisation_to_familles_table.php
 *   - 2026_08_28_000002_create_famille_organisation_table.php
 *   - 2026_08_28_000004_create_famille_organisation_demandes_table.php
 *   - 2026_08_28_000005_create_personne_organisation_table.php
 *   - 2026_08_28_000006_create_benevole_profil_organisation_table.php
 *   - 2026_08_28_000007_add_id_organisation_to_famille_imports_table.php
 * S'exécute après le squash "Familles" (familles/famille_imports doivent
 * déjà exister pour recevoir leur colonne id_organisation en contrainte
 * FK réelle — organisations n'existait pas encore au moment des
 * migrations d'origine de familles/famille_imports, d'où leurs colonnes
 * id_organisation ajoutées ici plutôt que là-bas).
 *
 * Les migrations de données d'origine (backfill de familles.id_organisation
 * depuis famille_imports pour les lignes déjà en base, peuplement de
 * famille_organisation pour ces mêmes familles) sont OMISES ici — sans
 * objet sur une base fraîche, aucune ligne existante à rattraper.
 */
return new class extends Migration {
    public function up(): void
    {
        // Ajouté le 28/08/2026 (décision : voir échange du 28/08/2026 sur
        // le multi-organisation) — d'autres associations partenaires
        // (au-delà de AMANA elle-même) peuvent désormais enregistrer des
        // familles dans cette app, dans un dossier COMMUN plutôt que des
        // bases séparées.
        //
        // Contrairement à OrganismeAide (liste fermée pour la question
        // intake "percevez-vous une aide d'un autre organisme ?" —
        // attribut de la famille), une Organisation ici est un TIERS DE
        // CONFIANCE avec des comptes gestionnaire_externe réels et un
        // accès (scopé) aux dossiers qu'elle a enregistrés — voir
        // famille_organisation et personne_organisation.
        //
        // Reste volontairement local à cette app (pas dans amana_shared/
        // amana_commun) — décision du 28/08/2026 : contrairement à
        // ref_applications/ref_roles, la notion d'organisation partenaire
        // n'a de sens que pour amana_web_familles pour l'instant. Les
        // tables qui la référencent depuis une autre base (amana_commun)
        // — personne_organisation, benevole_profil_organisation — le font
        // par simple colonne, SANS contrainte FK cross-DB, même
        // convention que Famille::id_quartier.
        //
        // `est_principale` : exactement UNE ligne (AMANA elle-même) porte
        // ce flag — voir Organisation::principale(). Sert à pré-remplir
        // id_organisation des dossiers créés en interne (staff AMANA, pas
        // de gestionnaire_externe impliqué), et à protéger cette ligne
        // contre la désactivation/suppression depuis l'écran Paramètres
        // (voir Admin\OrganisationsController).
        //
        // `nom` unique (empêcher la création de la même organisation deux
        // fois, même sous un `code` différent) — la collation par défaut
        // de la connexion (utf8mb4_unicode_ci) rend cette contrainte déjà
        // insensible à la casse, pas besoin d'une colonne normalisée
        // dédiée comme pour hotel_addresses.adresse_normalisee (qui doit
        // en plus ignorer accents/ponctuation, ce qu'un index unique seul
        // ne fait pas).
        Schema::create('organisations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('nom', 150)->unique();
            $table->boolean('actif')->default(true);
            $table->boolean('est_principale')->default(false)
                ->comment('AMANA elle-même — exactement une ligne à true, voir Organisation::principale()');
            $table->timestamps();
        });

        // Amorçage de la ligne AMANA — nécessaire dès cette migration.
        DB::table('organisations')->insert([
            'code' => 'amana',
            'nom' => 'AMANA',
            'actif' => true,
            'est_principale' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // id_organisation : trace l'organisation D'ORIGINE d'un dossier
        // (celle qui l'a créé, via intake ou import) — ne pilote plus à
        // elle seule la visibilité depuis l'introduction de
        // famille_organisation (voir plus bas), qui porte l'ensemble des
        // organisations rattachées. Contrainte FK réelle possible
        // (familles et organisations vivent toutes les deux dans cette
        // base) — nullable : une famille créée directement par le staff
        // interne (pas via le canal d'une organisation partenaire) n'a pas
        // d'origine à tracer.
        //
        // Pas de backfill des lignes existantes (DB fraîche, rien à
        // rattraper — voir docblock de fichier) : la migration d'origine
        // rétro-rattachait les familles déjà en base à AMANA et peuplait
        // famille_organisation en conséquence, superflu ici.
        Schema::table('familles', function (Blueprint $table) {
            $table->foreignId('id_organisation')->nullable()->after('id')
                ->constrained('organisations')->nullOnDelete()
                ->comment("Organisation D'ORIGINE du dossier — voir famille_organisation pour l'ensemble des organisations rattachées");
        });

        // Décision du 28/08/2026 : quand deux organisations enregistrent la
        // même famille (rapprochée par FamilleUpsertService::trouverDoublon(),
        // comme avant), c'est UN SEUL dossier partagé — pas une copie par
        // organisation. Cette table pivot porte donc l'ensemble des
        // organisations rattachées à un dossier (une famille peut en avoir
        // plusieurs), pas une seule colonne id_organisation sur familles
        // (qui reste néanmoins présente pour tracer l'organisation
        // D'ORIGINE, voir ci-dessus, mais ne pilote plus à elle seule la
        // visibilité).
        //
        // Un rattachement ne passe PAS directement par cette table à la
        // création : voir famille_organisation_demandes pour le cas
        // "organisation différente de celle(s) déjà rattachée(s)", qui
        // nécessite une validation admin/gestionnaire avant d'atterrir ici.
        Schema::create('famille_organisation', function (Blueprint $table) {
            $table->foreignId('id_famille')->constrained('familles')->cascadeOnDelete();
            $table->foreignId('id_organisation')->constrained('organisations')->cascadeOnDelete();
            $table->timestamp('rattachee_le')->useCurrent();

            $table->primary(['id_famille', 'id_organisation']);
        });

        // Décision du 28/08/2026 : quand une organisation B soumet/importe
        // une famille qui matche déjà un dossier rattaché à une
        // organisation A (même dédup que FamilleUpsertService::trouverDoublon()),
        // le dossier n'est PAS modifié et B n'est PAS rattachée
        // automatiquement — une ligne est créée ici et un admin/gestionnaire
        // (staff interne, jamais un gestionnaire_externe, même de
        // l'organisation A) doit valider avant que B obtienne l'accès (voir
        // FamilleOrganisationDemandeService).
        //
        // `source` distingue le canal d'origine de la demande — utile pour
        // l'écran de revue (voir Admin\RattachementsController), même
        // esprit que famille_imports.source.
        //
        // `donnees_soumises` : snapshot JSON de ce que B a soumis/importé
        // (pas appliqué au dossier tant que non validé) — permet au staff
        // de comparer à l'écran de revue avant de décider, et sert de base
        // si la demande est validée alors que certains champs ont besoin
        // d'être fusionnés manuellement (hors scope du merge automatique de
        // FamilleUpsertService::upsert()).
        //
        // Contrairement à intake_demandes_attente (purgée après usage), une
        // ligne ici reste en base après traitement (statut validée/
        // rejetée) — c'est un historique de décision, pas juste un jeton
        // temporaire.
        Schema::create('famille_organisation_demandes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_famille')->constrained('familles')->cascadeOnDelete();
            $table->foreignId('id_organisation')->constrained('organisations')->cascadeOnDelete();
            $table->enum('source', ['intake', 'import', 'manuel']);
            $table->unsignedInteger('submitted_by')->nullable()
                ->comment('ID de ref_personnes — pas de FK, table partagée. Nullable : soumission publique (intake).');
            $table->json('donnees_soumises')->nullable();
            $table->enum('statut', ['en_attente', 'validee', 'rejetee'])->default('en_attente');
            $table->unsignedInteger('traite_par')->nullable()
                ->comment('ID de ref_personnes — pas de FK, table partagée.');
            $table->timestamp('traite_le')->nullable();
            $table->timestamps();

            // Une seule demande EN ATTENTE à la fois par (famille,
            // organisation) — une resoumission avant traitement met à jour
            // la ligne existante plutôt que d'en empiler une seconde (même
            // esprit que IntakeAttenteService::trouverAttenteExistante()),
            // voir FamilleOrganisationDemandeService::creerOuMettreAJour().
            $table->unique(['id_famille', 'id_organisation', 'statut'], 'uq_famille_org_demande_statut');
            $table->index('statut');
        });

        // Rattache un compte ref_personnes (rôle gestionnaire_externe côté
        // familles) à une ou plusieurs organisations partenaires —
        // décision du 28/08/2026 : un gestionnaire externe peut appartenir
        // à plusieurs organisations à la fois.
        //
        // id_personne référence ref_personnes (amana_commun), organisations
        // vit dans CETTE base — table à cheval sur les deux bases, même
        // convention que famille_imports.uploaded_by pour la colonne côté
        // commun : unsignedInteger explicite (ref_personnes.id est un
        // increments(), pas un id() standard), PAS de contrainte FK côté
        // id_personne. id_organisation, lui, est une contrainte FK réelle
        // (même base).
        Schema::create('personne_organisation', function (Blueprint $table) {
            $table->unsignedInteger('id_personne')
                ->comment('ID de ref_personnes (amana_commun) — pas de FK, table partagée cross-DB.');
            $table->foreignId('id_organisation')->constrained('organisations')->cascadeOnDelete();
            $table->date('date_attribution')->default(now()->toDateString());

            $table->primary(['id_personne', 'id_organisation']);
            $table->index('id_personne');
        });

        // Réponse à la nouvelle question "organisation" du formulaire
        // public de candidature bénévole (BenevoleForm.vue) — décision du
        // 28/08/2026 : question obligatoire, une seule organisation par
        // bénévole (contrairement à personne_organisation, pas de N-N ici —
        // pas de cas d'usage identifié pour qu'un même bénévole se
        // rattache à plusieurs organisations simultanément).
        //
        // benevole_profils vit dans amana_commun (voir
        // Amana\Shared\Models\BenevoleProfil), organisations dans CETTE
        // base — même convention cross-DB que personne_organisation
        // ci-dessus : colonne id_benevole_profil SANS contrainte FK,
        // id_organisation avec contrainte FK réelle.
        //
        // Table séparée plutôt qu'une simple colonne id_organisation sur
        // benevole_profils : ce dernier modèle est partagé (Amana\Shared\
        // Models\BenevoleProfil, consommé aussi par d'autres apps AMANA à
        // terme) — y ajouter une colonne qui n'a de sens que pour
        // amana_web_familles contredirait la décision du 28/08/2026 de
        // garder Organisation local à cette app.
        Schema::create('benevole_profil_organisation', function (Blueprint $table) {
            $table->unsignedBigInteger('id_benevole_profil')->unique()
                ->comment('ID de benevole_profils (amana_commun) — pas de FK, table partagée cross-DB. unique() : une seule organisation par bénévole.');
            $table->foreignId('id_organisation')->constrained('organisations')->cascadeOnDelete();
            $table->timestamp('rattachee_le')->useCurrent();
        });

        // Trace l'organisation D'ORIGINE d'un import/ajout manuel — même
        // raisonnement que familles.id_organisation ci-dessus, contrainte
        // FK réelle possible (même base). Nullable : import fait par le
        // staff interne, sans organisation partenaire associée — traité
        // comme "organisation principale" par convention côté
        // application, pas de backfill nécessaire ici (contrairement à
        // familles.id_organisation, qui pilote directement la visibilité
        // des dossiers eux-mêmes).
        Schema::table('famille_imports', function (Blueprint $table) {
            $table->foreignId('id_organisation')->nullable()->after('uploaded_by')
                ->constrained('organisations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('famille_imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_organisation');
        });
        Schema::dropIfExists('benevole_profil_organisation');
        Schema::dropIfExists('personne_organisation');
        Schema::dropIfExists('famille_organisation_demandes');
        Schema::dropIfExists('famille_organisation');
        Schema::table('familles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_organisation');
        });
        Schema::dropIfExists('organisations');
    }
};
