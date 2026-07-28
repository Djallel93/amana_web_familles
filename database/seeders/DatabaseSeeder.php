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
 * Contrairement à amana_web_planning, cette app NE seed PAS l'application
 * 'familles' ni ses rôles ('admin', 'gestionnaire', 'membre', 'benevole') :
 * ce sont des tables partagées (ref_applications, ref_roles) possédées par
 * amana_web_planning, déjà peuplées par la migration
 * 2026_07_12_000001_register_familles_application.php (voir README.md).
 *
 * En revanche, ce seeder attribue le rôle 'admin' de l'application 'familles'
 * au compte admin@amana.fr, pour que le premier déploiement donne
 * immédiatement accès à cette app — même mécanisme que
 * amana_web_planning\DatabaseSeeder pour son propre rôle 'admin'.
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
            $this->command->error('❌ Application "familles" introuvable dans ref_applications — la migration '
                . '2026_07_12_000001_register_familles_application.php (côté amana_web_planning) doit être '
                . 'exécutée avant ce seeder.');
            return;
        }

        $roleService->syncRoleFamilles($admin, 'admin');
        $this->command->info('✅ Rôle admin (familles) attribué à admin@amana.fr');
    }
}
