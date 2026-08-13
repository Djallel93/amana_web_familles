<?php
// database/migrations/2026_07_12_000004_create_familles_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table familles + tables satellites du formulaire d'intake.
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
            $table->boolean('se_deplace')->default(false);
            $table->boolean('est_hotel')->default(false)
                ->comment('Adresse actuelle = un hôtel (hébergement d\'urgence) — ajouté suite à la demande du 09/08/2026, absent du Google Form d\'origine');
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

        // ── Journal des refus de consentement RGPD ────────────────────────
        // Réplique la section "Refus" du Google Form (goToSectionId 17ed701b) :
        // si la famille refuse dès la première question, aucun dossier
        // Famille n'est créé — seule cette ligne d'audit/conformité l'est
        // (décision du 09/08/2026 : tracer le refus, pas les données
        // personnelles). Pas de FK vers familles : par définition, il n'y a
        // pas de famille à ce stade.
        Schema::create('intake_consent_refusals', function (Blueprint $table) {
            $table->id();
            $table->string('langue', 2);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_consent_refusals');
        Schema::dropIfExists('famille_organisme_aide');
        Schema::dropIfExists('famille_secteur_activite');
        Schema::dropIfExists('familles');
        Schema::dropIfExists('organismes_aide');
        Schema::dropIfExists('secteurs_activite');
    }
};
