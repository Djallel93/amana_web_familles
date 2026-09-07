<?php
// database/migrations/2026_09_07_000000_create_campagne_equipe_membres_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table campagne_equipe_membres.
 *
 * Affectation d'une Personne à un rôle équipe_* (equipe_reception/pesee/
 * packaging/chargement, voir 2026_08_31_000000_register_livraison_roles.php)
 * PROPRE À UNE CAMPAGNE — une personne peut être equipe_pesee sur la
 * campagne_1 et equipe_packaging (voire equipe_reception ET
 * equipe_packaging à la fois) sur la campagne_2, ce que ref_personnes_roles
 * (amana_commun) ne peut pas exprimer : ce dernier n'a qu'une notion
 * globale "cette personne tient ce poste", pas "sur quelle campagne".
 *
 * NE modifie PAS ref_personnes_roles ni Amana\Shared\Http\Middleware\
 * EnsureRole/Personne::hasRole() — même raisonnement que
 * 2026_08_31_000000_register_livraison_roles.php pour ne pas y avoir
 * ajouté ces 4 rôles au départ : ref_personnes_roles est une table
 * partagée entre apps AMANA (amana_commun), un scoping par campagne y
 * serait un concept propre à familles/livraison, sans rien à y gagner
 * pour les autres apps qui la consomment. Cette table reste donc locale à
 * amana_web_familles, comme personne_organisation (voir
 * create_personne_organisation_table.php) — même précaution `id_personne`
 * sans FK (commun est une base séparée).
 *
 * L'inscription globale dans ref_personnes_roles (equipe_pesee etc.) n'est
 * pas retirée pour autant : elle continue de servir de porte d'entrée
 * grossière (visibilité de la section Livraison dans la sidebar, accès à
 * App\Http\Controllers\Livraison\*Controller::choisir(), qui liste les
 * campagnes actives sans campagne connue à ce stade — donc rien à vérifier
 * de plus fin). C'est cette table-ci, via App\Policies\CampagnePolicy, qui
 * tranche l'accès aux actions PROPRES à une campagne précise (voir
 * App\Models\Campagne::equipeMembres()).
 *
 * unique(['id_campagne', 'id_personne', 'role']) plutôt que
 * ['id_campagne', 'id_personne'] seul : une personne peut cumuler
 * plusieurs rôles équipe_* sur la même campagne (même esprit que
 * ref_personnes_roles, qui autorise déjà le cumul entre rôles).
 */
return new class extends Migration {
    public function up(): void
    {
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
    }
};
