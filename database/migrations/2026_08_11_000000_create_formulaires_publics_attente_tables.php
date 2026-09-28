<?php
// database/migrations/2026_08_11_000000_create_formulaires_publics_attente_tables.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : "Formulaires publics — attente de confirmation" — squash du
 * 10/09/2026 (Section D du refactor) de 4 migrations d'origine :
 *   - intake_consent_refusals (déplacée ici depuis
 *     2026_07_12_000004_create_familles_table.php, où elle était
 *     initialement bundlée avec familles — regroupée avec son miroir
 *     benevole_consent_refusals plutôt qu'avec familles, les deux étant
 *     de purs journaux d'audit RGPD sans lien réel avec le domaine
 *     familles)
 *   - 2026_08_11_000000_create_intake_demandes_attente_table.php
 *   - 2026_08_24_000000_create_benevole_demandes_attente_table.php
 *   - 2026_08_24_000001_create_benevole_consent_refusals_table.php
 *
 * 2026_08_31_000000_hash_existing_confirmation_tokens.php (migration de
 * DONNÉES rehachant les jetons en clair de famille_verifications/
 * intake_demandes_attente/benevole_demandes_attente) est supprimée par ce
 * squash plutôt que reportée ici : les jetons sont désormais hachés dès
 * la création dans les 3 tables concernées (voir leurs colonnes `token`
 * ci-dessous et dans le squash "Familles"), il n'y a donc plus jamais de
 * jeton en clair à rehacher sur un environnement recréé depuis ces
 * migrations. Sans objet sur une base fraîche — rien à préserver.
 */
return new class extends Migration {
    public function up(): void
    {
        // ── Journal des refus de consentement RGPD (formulaire famille) ──
        // Réplique la section "Refus" du Google Form (goToSectionId
        // 17ed701b) : si la famille refuse dès la première question, aucun
        // dossier Famille n'est créé — seule cette ligne d'audit/conformité
        // l'est (décision du 09/08/2026 : tracer le refus, pas les données
        // personnelles). Pas de FK vers familles : par définition, il n'y a
        // pas de famille à ce stade.
        Schema::create('intake_consent_refusals', function (Blueprint $table) {
            $table->id();
            $table->string('langue', 2);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Trace un refus de consentement RGPD à la première étape du
        // formulaire de candidature bénévole — miroir exact de
        // intake_consent_refusals ci-dessus. Ajouté le 24/08/2026 (absent de
        // la première implémentation, le bouton "Je refuse" manquait sur
        // cette étape).
        Schema::create('benevole_consent_refusals', function (Blueprint $table) {
            $table->id();
            $table->string('langue', 2);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Étape intermédiaire ajoutée le 11/08/2026 devant
        // IntakeController::store() : une soumission du formulaire public
        // n'est plus transformée en dossier Famille immédiatement — elle
        // est d'abord stockée ici, un email de confirmation est envoyé à
        // l'adresse fournie, et ce n'est qu'au clic sur le lien
        // (IntakeConfirmationController::confirmer()) que
        // FamilleUpsertService::upsert() est réellement appelé. Objectif :
        // éviter les dossiers créés depuis une adresse email invalide/mal
        // saisie ou un envoi accidentel.
        //
        // Pas de foreignId vers familles : au moment de la création de
        // cette ligne, on ne sait pas encore s'il s'agira d'une création ou
        // d'une mise à jour (la dédup ne s'exécute qu'à la confirmation).
        //
        // `donnees` : tous les champs validés par IntakeController::store(),
        // sous forme de tableau associatif JSON — mêmes clés que
        // Famille::$fillable, réutilisées telles quelles par
        // FamilleUpsertService::upsert() à la confirmation, pas de
        // retraitement/renommage.
        //
        // `secteurs_activite`/`organismes_aide` : tableaux d'IDs JSON
        // (relations belongsToMany, pas des colonnes de familles — voir
        // FamilleUpsertService::syncListes()).
        //
        // `documents_meta` : nom d'origine + mime par fichier, indexé par
        // "slot:index" (ex. "identite:0") — les fichiers eux-mêmes vivent
        // sur le disque 'local' sous storage/app/private/intake-attente/
        // {token}/, pas en base (voir IntakeController::stockerFichiersAttente()).
        //
        // Pas de updated_at : une ligne est créée à la soumission, puis
        // soit confirmée (supprimée par
        // IntakeConfirmationController::confirmer()), soit purgée une fois
        // expirée par la commande familles:nettoyer-demandes-attente —
        // jamais d'état intermédiaire à tracer.
        //
        // `token` haché dès la création (voir docblock de fichier) — plus
        // de migration de données de rehachage nécessaire.
        Schema::create('intake_demandes_attente', function (Blueprint $table) {
            $table->id();
            $table->string('token', 100)->unique()
                ->comment('Haché au repos (hash("sha256", ...)) — jamais stocké en clair');
            $table->string('langue', 2);
            $table->json('donnees');
            $table->json('secteurs_activite')->nullable();
            $table->json('organismes_aide')->nullable();
            $table->json('documents_meta')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Utilisé par IntakeController::store() pour retrouver une
            // soumission en attente correspondant à la même famille
            // (email, ou téléphone+nom) avant d'en créer une nouvelle —
            // voir FamilleUpsertService::trouverDoublon(), même logique
            // appliquée ici sur les demandes non confirmées.
            $table->index('expires_at');
        });

        // Table d'attente pour la candidature bénévole publique — miroir
        // exact de intake_demandes_attente ci-dessus (même raisonnement
        // détaillé) : soumission stockée 48h, email de confirmation envoyé,
        // la Personne/le BenevoleProfil ne sont créés/liés qu'à la
        // confirmation (voir BenevoleIntakeConfirmationController::confirmer()).
        //
        // Base propre à amana_web_familles (contrairement à benevole_profils,
        // qui vit dans amana_commun) : cette table est un détail
        // d'implémentation du flux de confirmation par email, pas une
        // donnée à partager entre apps.
        //
        // `donnees` : nom/prenom/email/telephone/langue + permis/
        // id_vehicule_type/zone_livraison, mêmes clés que celles validées
        // par BenevoleIntakeController::store().
        // `secteurs` : IDs de secteurs sélectionnés (JSON, hors `donnees` —
        // pas une colonne mais une relation belongsToMany une fois le
        // profil créé). Pas de disponibilités (retiré le 24/08/2026, voir
        // BenevoleProfil).
        Schema::create('benevole_demandes_attente', function (Blueprint $table) {
            $table->id();
            $table->string('token', 100)->unique()
                ->comment('Haché au repos (hash("sha256", ...)) — jamais stocké en clair');
            $table->string('langue', 2);
            $table->json('donnees');
            $table->json('secteurs')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benevole_demandes_attente');
        Schema::dropIfExists('intake_demandes_attente');
        Schema::dropIfExists('benevole_consent_refusals');
        Schema::dropIfExists('intake_consent_refusals');
    }
};
