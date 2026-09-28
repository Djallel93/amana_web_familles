<?php
// database/migrations/2026_09_07_000000_create_livraison_equipe_tables.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : domaine Livraison — Équipe — squash du 10/09/2026 (Section D
 * du refactor) de 4 migrations d'origine :
 *   - 2026_08_31_000007_create_benevole_disponibilites_table.php
 *   - 2026_08_31_000008_create_benevole_disponibilite_creneaux_table.php
 *   - 2026_09_03_000001_create_benevole_retours_qg_table.php
 *   - 2026_09_07_000000_create_campagne_equipe_membres_table.php
 *
 * Dernier groupe de la squash livraison : benevole_retours_qg référence
 * routes (id_route_origine), donc ce fichier s'exécute après le squash
 * "Routing" (2026_08_31_000009_...).
 */
return new class extends Migration {
    public function up(): void
    {
        // Confirmation de disponibilité d'un bénévole (= chauffeur
        // potentiel — "chauffeur" n'est pas un rôle séparé, c'est
        // bénévole + un BenevoleProfil avec véhicule/permis) pour UNE
        // journée de campagne (CampagneJournee) donnée. `id_personne`
        // référence commun (pas de FK, même convention que le reste de ce
        // domaine) ; unique par (id_personne, id_campagne_journee) — une
        // seule ligne de disponibilité par bénévole et par journée,
        // éditable à tout moment après la confirmation initiale (pas de
        // flux "renvoyer le formulaire").
        //
        // SCOPING PAR JOURNÉE : un bénévole peut être disponible le jour
        // de collecte d'une campagne mais pas le jour de livraison (ou
        // l'inverse) — la disponibilité est donc explicitement par
        // CampagneJournee, jamais par Campagne directement (voir
        // CampagneJournee::disponibilites()). Ceci ne dégrade pas le cas
        // mono-journée : CampagnesController::store() crée
        // systématiquement une CampagneJournee dès la création de la
        // campagne, donc id_campagne_journee est toujours renseignable, y
        // compris pour une campagne "classique" à une seule journée. Pas
        // de colonne id_campagne dénormalisée ici : la campagne reste
        // accessible via $disponibilite->journee->campagne.
        //
        // `vehicule_confirme` : le bénévole confirme que son véhicule
        // correspond toujours à BenevoleProfil.id_vehicule_type (commun) —
        // pas de re-saisie ici, juste une confirmation booléenne.
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

        // Pivot : créneaux pour lesquels un bénévole se déclare disponible
        // sur une campagne donnée. Même liste fixe de 6 valeurs que
        // livraison_creneaux (App\Support\Creneau::TOUS).
        Schema::create('benevole_disponibilite_creneaux', function (Blueprint $table) {
            $table->id();
            // Colonne déclarée séparément de la contrainte FK (plutôt que
            // foreignId(...)->constrained(...)) pour pouvoir donner un nom
            // de contrainte explicite et court via la forme documentée
            // $table->foreign($column, $name) : le nom auto-généré par
            // Laravel ("benevole_disponibilite_creneaux_id_benevole_
            // disponibilite_foreign", 65 caractères) dépasse la limite
            // d'identifiant MySQL (64). Même précaution que l'index unique
            // juste en-dessous, qui a lui aussi un nom explicite pour la
            // même raison.
            $table->foreignId('id_benevole_disponibilite');
            $table->string('creneau', 5)
                ->comment('Une des 6 valeurs de App\\Support\\Creneau::TOUS');

            $table->foreign('id_benevole_disponibilite', 'benevole_dispo_creneaux_id_dispo_fk')
                ->references('id')->on('benevole_disponibilites')
                ->cascadeOnDelete();

            $table->unique(['id_benevole_disponibilite', 'creneau'], 'benevole_dispo_creneaux_unique');
        });

        // Une ligne = un bénévole s'est déclaré de retour au QG et
        // disponible pour repartir sur une nouvelle tournée, pour une
        // campagne donnée. Volontairement une table séparée plutôt qu'un
        // simple flag sur ref_personnes (commun — appartiendrait à toutes
        // les apps AMANA pour un concept propre au domaine livraison) ou
        // sur routes (la disponibilité du bénévole survit à la tournée
        // qu'il vient de terminer, ce n'est pas un attribut de CETTE
        // tournée mais de la PERSONNE, le temps qu'une nouvelle tournée
        // lui soit assignée).
        //
        // `recupere_le` : renseigné quand admin/gestionnaire inclut ce
        // bénévole dans un nouveau lot de tournées (RouteGenerationService)
        // — la ligne n'est pas supprimée à ce moment (traçabilité :
        // combien de fois ce bénévole a fait des allers-retours dans la
        // journée), juste marquée consommée. Une personne ne peut avoir
        // qu'UNE ligne "non récupérée" à la fois par campagne (contrainte
        // applicative, voir MaRouteController::retourQg() — pas une
        // contrainte unique partielle en DB, MySQL ne les supporte pas
        // nativement).
        Schema::create('benevole_retours_qg', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->unsignedInteger('id_personne')
                ->comment('ref_personnes.id — pas de FK, commun est une base séparée');
            $table->foreignId('id_route_origine')->nullable()
                ->constrained('routes')->nullOnDelete()
                ->comment('Tournée dont ce retour découle — traçabilité uniquement');
            $table->timestamp('disponible_depuis');
            $table->timestamp('recupere_le')->nullable()
                ->comment('Renseigné quand ce bénévole a été inclus dans un nouveau lot de tournées — voir RouteGenerationService');

            $table->index(['id_campagne', 'id_personne', 'recupere_le']);
        });

        // Affectation d'une Personne à un rôle équipe_* (equipe_reception/
        // pesee/packaging/chargement) PROPRE À UNE CAMPAGNE — une personne
        // peut être equipe_pesee sur la campagne_1 et equipe_packaging
        // (voire equipe_reception ET equipe_packaging à la fois) sur la
        // campagne_2, ce que ref_personnes_roles (amana_commun) ne peut
        // pas exprimer : ce dernier n'a qu'une notion globale "cette
        // personne tient ce poste", pas "sur quelle campagne".
        //
        // NE modifie PAS ref_personnes_roles ni Amana\Shared\Http\Middleware\
        // EnsureRole/Personne::hasRole() — même raisonnement que
        // l'enregistrement des rôles équipe_* eux-mêmes (voir
        // register_familles_application.php) pour ne pas y avoir ajouté ces
        // 4 rôles au départ : ref_personnes_roles est une table partagée
        // entre apps AMANA (amana_commun), un scoping par campagne y
        // serait un concept propre à familles/livraison, sans rien à y
        // gagner pour les autres apps qui la consomment. Cette table reste
        // donc locale à amana_web_familles, comme personne_organisation —
        // même précaution id_personne sans FK (commun est une base
        // séparée).
        //
        // L'inscription globale dans ref_personnes_roles (equipe_pesee
        // etc.) n'est pas retirée pour autant : elle continue de servir de
        // porte d'entrée grossière (visibilité de la section Livraison
        // dans la sidebar, accès à
        // App\Http\Controllers\Livraison\*Controller::choisir(), qui liste
        // les campagnes actives sans campagne connue à ce stade — donc
        // rien à vérifier de plus fin). C'est cette table-ci, via
        // App\Policies\CampagnePolicy, qui tranche l'accès aux actions
        // PROPRES à une campagne précise (voir App\Models\Campagne::
        // equipeMembres()).
        //
        // unique(['id_campagne', 'id_personne', 'role']) plutôt que
        // ['id_campagne', 'id_personne'] seul : une personne peut cumuler
        // plusieurs rôles équipe_* sur la même campagne (même esprit que
        // ref_personnes_roles, qui autorise déjà le cumul entre rôles).
        Schema::create('campagne_equipe_membres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->unsignedInteger('id_personne')
                ->comment('ref_personnes.id — pas de FK, commun est une base séparée');
            $table->enum('role', ['equipe_reception', 'equipe_pesee', 'equipe_packaging', 'equipe_chargement']);
            $table->timestamps();

            $table->unique(['id_campagne', 'id_personne', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campagne_equipe_membres');
        Schema::dropIfExists('benevole_retours_qg');
        Schema::dropIfExists('benevole_disponibilite_creneaux');
        Schema::dropIfExists('benevole_disponibilites');
    }
};
