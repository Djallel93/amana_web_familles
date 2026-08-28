<?php
// database/migrations/2026_08_28_000001_create_organisations_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table organisations.
 *
 * Ajouté le 28/08/2026 (décision : voir échange du 28/08/2026 sur le
 * multi-organisation) — d'autres associations partenaires (au-delà de
 * AMANA elle-même) peuvent désormais enregistrer des familles dans cette
 * app, dans un dossier COMMUN plutôt que des bases séparées.
 *
 * Contrairement à OrganismeAide (liste fermée pour la question intake
 * "percevez-vous une aide d'un autre organisme ?" — attribut de la
 * famille), une Organisation ici est un TIERS DE CONFIANCE avec des
 * comptes gestionnaire_externe réels et un accès (scopé) aux dossiers
 * qu'elle a enregistrés — voir famille_organisation et
 * personne_organisation.
 *
 * Reste volontairement local à cette app (pas dans amana_shared/
 * amana_commun) — décision du 28/08/2026 : contrairement à
 * ref_applications/ref_roles, la notion d'organisation partenaire n'a de
 * sens que pour amana_web_familles pour l'instant. Les tables qui la
 * référencent depuis une autre base (amana_commun) — personne_organisation,
 * benevole_profil_organisation — le font par simple colonne, SANS
 * contrainte FK cross-DB, même convention que Famille::id_quartier (voir
 * migration create_familles_table).
 *
 * `est_principale` : exactement UNE ligne (AMANA elle-même) porte ce flag
 * — voir Organisation::principale(). Sert à :
 *   - pré-remplir id_organisation des dossiers créés en interne (staff
 *     AMANA, pas de gestionnaire_externe impliqué) ;
 *   - protéger cette ligne contre la désactivation/suppression depuis
 *     l'écran Paramètres (voir Admin\OrganisationsController).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('nom', 150);
            $table->boolean('actif')->default(true);
            $table->boolean('est_principale')->default(false)
                ->comment('AMANA elle-même — exactement une ligne à true, voir Organisation::principale()');
            $table->timestamps();
        });

        // Amorçage de la ligne AMANA — nécessaire dès cette migration (pas
        // un seeder à part) car la migration suivante
        // (add_id_organisation_to_familles_table) doit pouvoir y rattacher
        // rétroactivement tous les dossiers déjà existants.
        DB::table('organisations')->insert([
            'code' => 'amana',
            'nom' => 'AMANA',
            'actif' => true,
            'est_principale' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('organisations');
    }
};
