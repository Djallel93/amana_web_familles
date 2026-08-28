<?php
// database/seeders/DatabaseSeeder.php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Personne;
use App\Services\RoleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder principal.
 *
 * L'application 'familles' et ses rôles ('admin', 'gestionnaire', 'membre',
 * 'benevole') sont désormais enregistrés automatiquement par une migration
 * de cette app (2026_08_27_000000_register_familles_application.php,
 * ciblant amana_commun) — corrigé le 27/08/2026, ce n'était auparavant
 * qu'un seeder à lancer manuellement (FamillesApplicationSeeder, supprimé).
 * `php artisan migrate` suffit désormais, à condition d'avoir d'abord
 * lancé `php artisan amana:migrate-shared` (crée ref_applications/
 * ref_roles, tables et suivi de migration distincts de ceux de familles).
 *
 * Ce seeder attribue le rôle 'admin' de l'application 'familles' au compte
 * admin@amana.fr, pour que le premier déploiement donne immédiatement
 * accès à cette app — même mécanisme que amana_web_planning\DatabaseSeeder
 * pour son propre rôle 'admin'.
 *
 * Le compte admin@amana.fr lui-même (ref_personnes) est normalement déjà
 * créé par le seeder de amana_web_planning, puisque les comptes staff sont
 * partagés entre les deux apps. Ce seeder le crée quand même si absent
 * (déploiement de Familles avant Planning, environnement de test isolé,
 * etc.), avec le même mot de passe par défaut que Planning.
 *
 * À compléter au fil des étapes suivantes avec des seeders propres à
 * Familles (ex : jeu de données de test pour villes/secteurs/quartiers
 * en environnement de développement uniquement).
 */
class DatabaseSeeder extends Seeder
{
    public function run(RoleService $roleService): void
    {
        // ── Compte administrateur (partagé avec Planning) ───────────────────
        $admin = Personne::firstOrCreate(
            ['email' => 'admin@amana.fr'],
            [
                'nom' => 'Admin',
                'prenom' => 'AMANA',
                'password' => Hash::make('changeme123!'),
                'statut' => 'Validé',
            ]
        );

        if ($admin->wasRecentlyCreated) {
            // email_verified_at n'est volontairement pas mass-assignable (voir
            // Personne::$fillable) — affecté explicitement ici.
            $admin->email_verified_at = now();
            $admin->save();

            $this->command->warn('⚠️  Compte admin@amana.fr créé par Familles (absent de ref_personnes) — mot de passe : changeme123!');
        } else {
            $this->command->info('✅ Compte admin@amana.fr existant réutilisé');
        }

        // ── Attribution du rôle admin sur l'application familles ────────────
        $famillesApp = $roleService->famillesApp();

        if (!$famillesApp) {
            // Ne devrait plus arriver en temps normal depuis le 27/08/2026 :
            // la migration 2026_08_27_000000_register_familles_application.php
            // enregistre 'familles' automatiquement pendant `php artisan
            // migrate`, avant que les seeders ne s'exécutent. Ce garde-fou
            // reste utile si `db:seed` est lancé isolément sur une base
            // partiellement migrée, ou si `amana:migrate-shared` n'a jamais
            // été exécuté (ref_applications/ref_roles inexistantes).
            $this->command->error('❌ Application "familles" introuvable dans ref_applications — vérifiez que '
                . '`php artisan amana:migrate-shared` PUIS `php artisan migrate` ont bien été exécutés '
                . '(cette dernière contient la migration qui enregistre familles automatiquement).');
            return;
        }

        $roleService->syncRoleFamilles($admin, 'admin');
        $this->command->info('✅ Rôle admin (familles) attribué à admin@amana.fr');

        // ── Listes fermées du formulaire d'intake ────────────────────────
        $this->call([
            SecteurActiviteSeeder::class,
            OrganismeAideSeeder::class,
        ]);
    }
}
