<?php
// database/migrations/2026_08_31_000000_register_livraison_roles.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre les 4 rôles du domaine livraison (equipe_reception,
 * equipe_pesee, equipe_packaging, equipe_chargement) dans ref_roles
 * (amana_commun), rattachés à l'application 'familles' déjà enregistrée
 * par 2026_08_27_000000_register_familles_application.php — même
 * mécanisme, même connexion cross-DB, même idempotence.
 *
 * Ces 4 rôles sont volontairement absents de
 * Amana\Shared\Http\Middleware\EnsureRole (son `match` ne connaît que
 * admin/gestionnaire/membre/benevole/gestionnaire_externe) : ce sont des
 * concepts métier propres à cette app (livraison), pas des rangs de la
 * hiérarchie standard partagée entre apps AMANA. Vérifiés via
 * Personne::hasRole('equipe_reception') directement, dans le middleware
 * local App\Http\Middleware\EnsureLivraisonRole — voir ce fichier pour le
 * contrôle d'accès, et NE PAS ajouter ces codes au `match` de
 * EnsureRole/isXxx() sur Personne (amana_shared), qui reste inchangé par
 * ce patch.
 *
 * Rôles latéraux et cumulables entre eux (une personne peut tenir
 * plusieurs postes équipe_* à la fois sur un même compte — voir
 * ref_personnes_roles, clé composite (id_personne, id_role) sans
 * contrainte d'unicité par personne) : rien à faire ici pour permettre le
 * cumul, déjà supporté par le schéma existant.
 */
return new class extends Migration {
    public function up(): void
    {
        $commun = DB::connection(config('amana-shared.connection', 'commun'));

        $famillesId = $commun->table('ref_applications')->where('code', 'familles')->value('id');

        $roles = [
            ['code' => 'equipe_reception', 'libelle' => 'Équipe réception (comptage ménages)'],
            ['code' => 'equipe_pesee', 'libelle' => 'Équipe pesée (dons)'],
            ['code' => 'equipe_packaging', 'libelle' => 'Équipe packaging (colis)'],
            ['code' => 'equipe_chargement', 'libelle' => 'Équipe chargement (véhicules)'],
        ];

        foreach ($roles as $role) {
            $existe = $commun->table('ref_roles')
                ->where('id_application', $famillesId)
                ->where('code', $role['code'])
                ->exists();

            if (!$existe) {
                $commun->table('ref_roles')->insert([
                    'code' => $role['code'],
                    'libelle' => $role['libelle'],
                    'id_application' => $famillesId,
                ]);
            }
        }
    }

    /**
     * Pas de suppression au rollback — mêmes raisons que
     * register_familles_application.php : ces rôles peuvent déjà être
     * référencés dans ref_personnes_roles au moment d'un
     * `migrate:rollback`.
     */
    public function down(): void
    {
    }
};
