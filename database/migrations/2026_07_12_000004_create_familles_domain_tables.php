<?php
// database/migrations/2026_07_12_000004_create_familles_domain_tables.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : domaine Familles — squash du 10/09/2026 (Section D du
 * refactor) de 5 migrations d'origine :
 *   - 2026_07_12_000004_create_familles_table.php (familles,
 *     secteurs_activite, organismes_aide, famille_secteur_activite,
 *     famille_organisme_aide — mais PAS intake_consent_refusals, déplacée
 *     dans le squash "Formulaires publics" avec son miroir
 *     benevole_consent_refusals, voir 2026_08_11_..._formulaires_publics_attente_tables.php)
 *   - 2026_07_17_000001_add_google_resource_name_to_familles.php
 *   - 2026_08_15_000000_add_verrouillage_edition_to_familles.php
 *   - 2026_07_12_000005_create_famille_documents_table.php
 *   - 2026_07_12_000006_create_famille_verifications_table.php
 *   - 2026_07_12_000007_create_famille_imports_table.php
 *   - 2026_08_08_000000_add_rollback_columns_to_famille_imports.php
 *   - 2026_07_12_000008_create_famille_import_rows_table.php
 * (colonnes/tables/rationale d'origine conservés à l'identique ci-dessous,
 * seule la répartition en fichiers change)
 *
 * familles.id_organisation et famille_imports.id_organisation sont
 * VOLONTAIREMENT absentes d'ici — ajoutées par le squash "Organisations"
 * (2026_08_28_000001_create_organisations_domain_tables.php), qui
 * s'exécute après celui-ci et peut donc les poser comme vraies contraintes
 * FK dès la création de la colonne (organisations n'existe pas encore à
 * ce stade-ci).
 *
 * Remplace la feuille "Famille" de amana_familles (Google Apps Script,
 * ~21 colonnes de sortie + champs supplémentaires côté formulaire).
 *
 * Champs `code_postal` / `ville_texte` : valeurs brutes soumises par la
 * famille, distinctes de `id_quartier` qui est la valeur RÉSOLUE par
 * géocodage (webhook Make.com → lat/lng → ST_Contains). Les deux coexistent
 * volontairement : on garde la saisie brute même si la résolution échoue
 * ou est corrigée manuellement ensuite.
 *
 * Aucune donnée migrée dans cette phase (décision 6.8 — les 130 familles
 * existantes seront importées dans une étape ultérieure séparée). Cette
 * migration ne fait que créer le schéma.
 *
 * Réplique la logique de branchement du Google Form historique
 * (formulaire_famille_fr/en/ar.json — même goToSectionId sur les 3 langues) :
 *  - `type_hebergement` remplace le booléen `hosted` : distingue
 *    organisation / proche-connaissance / non, seule "organisation" déclenche
 *    la question "par qui" (hosted_by).
 *  - `type_piece_identite` est un champ NOUVEAU (absent de la v1 "light") :
 *    Nationalité/Titre de séjour/Demande d'asile → justificatif CAF requis ;
 *    Autre → justificatif AME requis à la place (voir famille_documents).
 *  - `type_activite` remplace le booléen `working` : temps plein/partiel/non,
 *    seul "partiel" déclenche work_days, "non" ne déclenche ni l'un ni
 *    l'autre.
 *  - secteur d'activité et aides d'autres organismes passent de champs texte
 *    libres à des listes fermées + option "autre" (tables satellites, pour
 *    pouvoir ajouter des entrées sans changement de code — cf échange du
 *    09/08/2026).
 */
return new class extends Migration {
    public function up(): void
    {
        // ── Listes fermées (secteur d'activité, organismes d'aide) ───────
        // Tables plutôt que colonnes JSON : permet d'ajouter/désactiver des
        // entrées depuis l'admin plus tard sans migration ni déploiement.
        Schema::create('secteurs_activite', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('libelle_fr', 150);
            $table->string('libelle_ar', 150);
            $table->string('libelle_en', 150);
            $table->boolean('actif')->default(true);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();
        });

        Schema::create('organismes_aide', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('libelle_fr', 150);
            $table->string('libelle_ar', 150);
            $table->string('libelle_en', 150);
            $table->boolean('actif')->default(true);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();
        });

        Schema::create('familles', function (Blueprint $table) {
            $table->id();

            // ── Identité & contact ───────────────────────────────────────
            $table->string('nom', 150);
            $table->string('prenom', 150);
            $table->string('email', 255)->nullable();
            $table->string('telephone', 30);
            $table->string('telephone_bis', 30)->nullable();

            // ── Éligibilité ───────────────────────────────────────────────
            $table->boolean('zakat_el_fitr')->default(false);
            $table->boolean('sadaqa')->default(false);

            // ── Composition du foyer ─────────────────────────────────────
            $table->unsignedTinyInteger('nombre_adulte')->default(0);
            $table->unsignedTinyInteger('nombre_enfant')->default(0);

            // ── Adresse & résolution géographique ────────────────────────
            $table->text('adresse');
            $table->string('code_postal', 10)->nullable()
                ->comment('Valeur brute soumise par la famille');
            $table->string('ville_texte', 150)->nullable()
                ->comment('Valeur brute soumise par la famille, avant résolution');
            $table->foreignId('id_quartier')->nullable()
                ->comment('Valeur résolue par géocodage (ST_Contains) — pas de contrainte FK : quartiers vit dans amana_commun (amana/shared) depuis le 21/07/2026, hors de portée d\'une FK MySQL cross-DB. Relation Eloquent uniquement, voir App\\Models\\Famille::quartier().');
            // google_resource_name : identifiant "people/c..." renvoyé par
            // People API à la création du contact Google, réutilisé ensuite
            // pour les mises à jour (updateContact) au lieu d'une recherche
            // floue par nom/téléphone. Remplace l'ancienne intégration par
            // webhook Make.com (voir SynchroniserContactGoogle, ex-
            // EnvoyerWebhookContact). Nullable : reste vide tant que le
            // dossier n'a pas encore été validé une première fois (aucun
            // contact Google créé), ou si la synchronisation People API
            // n'est pas encore autorisée (cf. GoogleContactsService).
            $table->string('google_resource_name', 100)->nullable()
                ->comment('resourceName Google People API (ex: people/c1234567890), défini après la 1ère synchronisation');
            $table->boolean('se_deplace')->default(false);
            $table->boolean('est_hotel')->default(false)
                ->comment('Adresse actuelle = un hôtel (hébergement d\'urgence) — ajouté suite à la demande du 09/08/2026, absent du Google Form d\'origine');
            $table->boolean('etudiant')->default(false)
                ->comment('Famille (déclarant) étudiant(e) — ajouté suite à la demande du 13/08/2026, absent du Google Form d\'origine');
            // Coordonnées résolues par ResoudreAdresseFamille (Google Maps
            // Geocoding), en plus de id_quartier — jusqu'ici calculées puis
            // jetées après le calcul point-in-polygon. Persistées depuis le
            // 12/08/2026 pour l'affichage carte du panneau de détail
            // (DetailPanel.vue) : precision decimal(10,7), suffisante au
            // niveau rue (~1cm), cohérente avec l'usage direct par
            // l'API Google Maps JS côté front (pas de calcul géométrique
            // supplémentaire ici, contrairement à quartiers.boundary).
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // ── Verrouillage d'édition (décision du 15/08/2026) ──────────
            // Quand un membre du staff ouvre le Dossier Panel, le dossier
            // bascule visiblement à 'En cours' (si ce n'est pas déjà son
            // état) et se verrouille pour les autres utilisateurs, le temps
            // de l'édition ; à l'enregistrement ou à l'annulation, il
            // revient à son état d'avant ouverture — voir
            // FamillesController::show()/update()/deverrouiller().
            // Objectif double : (1) éviter que deux membres du staff
            // éditent le même dossier en même temps sans le savoir
            // (conflit d'écriture silencieux) ; (2) comme effet de bord,
            // garantir que le déclencheur de synchronisation Google
            // Contacts (basé sur etat_dossier ∈ {Validé, Rejeté, Archivé} à
            // l'enregistrement) se redéclenche systématiquement dès qu'un
            // dossier déjà validé/rejeté/archivé est réenregistré, puisqu'il
            // repasse toujours par 'En cours' au passage.
            // locked_by référence ref_personnes.id — unsignedInteger
            // explicitement, PAS foreignId() : ref_personnes.id est un
            // increments() (unsigned INT), pas un id() Laravel standard
            // (unsigned BIGINT) — sous peine d'incompatibilité de type sur
            // la contrainte FK. Pas de contrainte FK : table partagée
            // possédée par amana_web_planning.
            $table->unsignedInteger('locked_by')->nullable()
                ->comment('ID de ref_personnes — pas de FK, table partagée possédée par amana_web_planning');
            $table->timestamp('locked_at')->nullable();
            $table->string('etat_dossier_avant_verrouillage', 20)->nullable()
                ->comment("Valeur réelle d'etat_dossier juste avant le passage automatique à 'En cours' à l'ouverture du Dossier Panel — restaurée à l'enregistrement/l'annulation");

            // ── Situation & aide ──────────────────────────────────────────
            // circonstances : "décrivez brièvement votre situation actuelle"
            // du formulaire — obligatoire côté famille, mais imposé au
            // niveau validation (IntakeController) plutôt qu'en NOT NULL ici :
            // FamilleImportService (import CSV des 130 familles historiques,
            // décision 6.8) ne renseigne pas ce champ et créerait des
            // familles via Famille::create() sans lui — un NOT NULL ferait
            // échouer l'import tant qu'il n'est pas mis à jour séparément.
            // ressentit/specificites : champs réservés au staff (saisis dans
            // le dossier, pas dans le formulaire public) — voir échange du
            // 09/08/2026.
            $table->text('circonstances')->nullable();
            $table->text('ressentit')->nullable();
            $table->text('specificites')->nullable();
            $table->unsignedTinyInteger('criticite')->default(0)
                ->comment('Échelle 0 à 5');
            $table->string('langue', 2)->default('fr')
                ->comment('fr, ar, en');
            $table->enum('etat_dossier', [
                'Recu', 'En cours', 'En attente', 'Validé', 'Rejeté', 'Archivé',
            ])->default('Recu');
            $table->text('commentaire_dossier')->nullable();
            // Message système (pas une note staff — voir commentaire_dossier
            // ci-dessus) : pourquoi ce dossier a besoin d'une intervention
            // manuelle — échec de géocodage automatique le plus souvent,
            // mais champ volontairement générique pour couvrir d'autres
            // futurs cas. Affiché en rouge dans "Nouvelles demandes" et la
            // liste des dossiers. Effacé automatiquement dès résolution
            // (voir ResoudreAdresseFamille::handle, FamillesController::update)
            // — demande du 09/08/2026.
            $table->text('probleme_traitement')->nullable();

            // ── Champs supplémentaires côté formulaire d'intake ──────────
            $table->enum('type_hebergement', ['organisation', 'proche', 'non'])->nullable()
                ->comment('"Êtes-vous hébergé(e) par une personne ou une organisation ?" — hosted_by requis seulement si organisation');
            $table->string('hosted_by', 255)->nullable();

            $table->enum('type_piece_identite', ['nationalite', 'titre_sejour', 'demande_asile', 'autre'])->nullable()
                ->comment('Détermine quel justificatif est requis ensuite : CAF (3 premières valeurs) ou AME (autre) — voir famille_documents.type');

            $table->enum('type_activite', ['temps_plein', 'temps_partiel', 'non'])->nullable()
                ->comment('"Travaillez-vous actuellement, vous ou votre conjoint(e) ?" — work_days demandé seulement si temps_partiel');
            $table->unsignedTinyInteger('work_days')->nullable();
            $table->string('secteur_activite_autre', 150)->nullable()
                ->comment('Texte libre si "autre" coché dans famille_secteur_activite');

            $table->string('organisme_aide_autre', 150)->nullable()
                ->comment('Texte libre si "autre" coché dans famille_organisme_aide');

            $table->timestamps();

            // ── Index (filtres de la vue principale — section 8.2) ───────
            $table->index('etat_dossier');
            $table->index('id_quartier');
            $table->index('telephone');
            $table->index('zakat_el_fitr');
            $table->index('sadaqa');
            $table->index('criticite');
            $table->index('locked_by');
        });

        Schema::create('famille_secteur_activite', function (Blueprint $table) {
            $table->foreignId('id_famille')->constrained('familles')->onDelete('cascade');
            $table->foreignId('id_secteur_activite')->constrained('secteurs_activite')->onDelete('cascade');
            $table->primary(['id_famille', 'id_secteur_activite']);
        });

        Schema::create('famille_organisme_aide', function (Blueprint $table) {
            $table->foreignId('id_famille')->constrained('familles')->onDelete('cascade');
            $table->foreignId('id_organisme_aide')->constrained('organismes_aide')->onDelete('cascade');
            $table->primary(['id_famille', 'id_organisme_aide']);
        });

        // Remplace les colonnes `identite` / `aides_etat` de l'ancien système
        // (URLs Google Drive séparées par virgules) par une table dédiée, un
        // enregistrement par fichier. Stockage disque local IONOS (décision
        // 6.4) — pas de S3, pas de Google Drive. Types repris de
        // CONFIG.DOC_TYPES (amana_familles) : identity et resource sont
        // fonctionnellement équivalentes aux anciens identityDoc/resourceDoc
        // du COLUMN_MAP. Le générique aides_etat est scindé en deux (caf/ame)
        // pour refléter le branchement du Google Form historique (section
        // "Situation administrative" — type_piece_identite détermine lequel
        // des deux est demandé, jamais les deux à la fois).
        Schema::create('famille_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_famille')
                ->constrained('familles')
                ->onDelete('cascade');
            $table->enum('type', ['identity', 'caf', 'ame', 'resource']);
            $table->string('disk_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 150);
            $table->timestamp('uploaded_at')->useCurrent();

            $table->index(['id_famille', 'type']);
        });

        // Remplace emailVerificationService.js / endpoints confirmfamilyinfo/
        // sendverificationemails de l'ancien système. La famille reçoit un
        // email avec un lien contenant `token` pour relire/confirmer ses
        // informations (décision 6.10 — flux conservé). Jeton haché dès la
        // conception (voir App\Support\TokenHasher — plus besoin de la
        // migration de données 2026_08_31_..._hash_existing_confirmation_tokens,
        // supprimée par ce même squash, voir le fichier "Formulaires
        // publics"). Pas de updated_at : une ligne est créée à l'envoi, puis
        // confirmed_at est simplement rempli à la confirmation.
        Schema::create('famille_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_famille')
                ->constrained('familles')
                ->onDelete('cascade');
            $table->string('token', 100)->unique()
                ->comment('Haché au repos (hash("sha256", ...)) — jamais stocké en clair');
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('id_famille');
        });

        // Décision 6.9 : contrairement à l'ancien système (feuilles Google
        // Sheets dédiées Bulk Import/Bulk Update), un seul pipeline commun
        // alimente à la fois l'ajout manuel via UI et l'upload CSV — voir
        // famille_import_rows. uploaded_by référence ref_personnes.id (voir
        // remarque unsignedInteger/pas-de-FK plus haut sur familles.locked_by).
        // rolled_back_at ajouté le 08/08/2026 (support du rollback d'import
        // et de la synchro Google Contacts depuis l'écran de détail d'un
        // import) : marque l'import comme annulé — idempotence, un import ne
        // peut être annulé qu'une fois.
        Schema::create('famille_imports', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['import', 'update']);
            $table->enum('source', ['manual', 'csv']);
            $table->unsignedInteger('uploaded_by')->nullable()
                ->comment('ID de ref_personnes — pas de FK, table partagée possédée par amana_web_planning');
            $table->string('status', 50)->default('pending');
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('uploaded_by');
            $table->index('status');
        });

        // Une ligne par enregistrement traité (saisie manuelle ou ligne CSV),
        // `payload` conservant les données brutes soumises (avant mapping
        // vers le schéma `familles`) — utile pour rejouer/déboguer une ligne
        // en erreur. Statuts repris de CONFIG.BULK_STATUS (amana_familles),
        // simplifiés à pending/success/error/skipped comme demandé section
        // 6.9 ; 'en_attente_rattachement' ajouté le 28/08/2026 (organisations
        // partenaires) : la ligne matche une famille déjà rattachée à une
        // AUTRE organisation que celle qui importe — voir
        // FamilleUpsertService::upsert(). id_famille/cree/donnees_avant
        // ajoutés le 08/08/2026 (même patch que famille_imports.rolled_back_at
        // ci-dessus) : relient chaque ligne réussie à la famille créée/mise
        // à jour (indispensable pour cibler la synchro Google Contacts et le
        // rollback ligne par ligne), cree distinguant création (true) de
        // mise à jour d'un doublon existant (false, voir
        // FamilleUpsertService::upsert()), donnees_avant conservant un
        // snapshot de la famille avant mise à jour (cree=false uniquement)
        // pour restaurer sans dépendre de audit_logs.
        Schema::create('famille_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_import')
                ->constrained('famille_imports')
                ->onDelete('cascade');
            $table->unsignedInteger('row_number');
            $table->foreignId('id_famille')->nullable()
                ->constrained('familles')->nullOnDelete();
            $table->boolean('cree')->nullable()
                ->comment("true = création, false = mise à jour d'un doublon existant — null si status != success");
            $table->json('donnees_avant')->nullable()
                ->comment('Snapshot Famille avant mise à jour (cree=false) — permet le rollback');
            $table->json('payload');
            $table->enum('status', ['pending', 'success', 'error', 'skipped', 'en_attente_rattachement'])
                ->default('pending');
            $table->text('error_message')->nullable();

            $table->index(['id_import', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('famille_import_rows');
        Schema::dropIfExists('famille_imports');
        Schema::dropIfExists('famille_verifications');
        Schema::dropIfExists('famille_documents');
        Schema::dropIfExists('famille_organisme_aide');
        Schema::dropIfExists('famille_secteur_activite');
        Schema::dropIfExists('familles');
        Schema::dropIfExists('organismes_aide');
        Schema::dropIfExists('secteurs_activite');
    }
};
