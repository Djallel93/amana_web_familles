<?php
// database/seeders/FamillesApplicationSeeder.php
//
// Anciennement une migration (2026_07_12_000001_register_familles_application.php)
// vivant dans amana_web_planning car ref_applications/ref_roles étaient dans
// la même base que le reste. Ces tables vivent maintenant dans amana_commun
// (voir amana/shared) — ce n'est donc plus une migration mais un seeder,
// exécuté manuellement une fois par app, jamais par le cycle migrate normal.
//
// À exécuter après `php artisan amana:migrate-shared` :
//
//   php artisan db:seed --class=Database\\Seeders\\FamillesApplicationSeeder
//
// Idempotent — peut être relancé sans risque (vérifie l'existence avant
// insertion). Le backfill audit_logs → planning de la migration d'origine
// n'a plus lieu d'être : décision « fresh start » du 21/07/2026, amana_commun
// repart d'une base vide, il n'y a donc aucune ligne historique à corriger.

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FamillesApplicationSeeder extends Seeder
{
    public function run(): void
    {
        $commun = DB::connection(config('amana-shared.connection', 'commun'));

        $dejaPresent = $commun->table('ref_applications')->where('code', 'familles')->exists();

        if (!$dejaPresent) {
            $commun->table('ref_applications')->insert([
                'code' => 'familles',
                'libelle' => 'AMANA Familles',
                'actif' => true,
            ]);
        }

        $famillesId = $commun->table('ref_applications')->where('code', 'familles')->value('id');

        $roles = [
            ['code' => 'admin', 'libelle' => 'Administrateur'],
            ['code' => 'gestionnaire', 'libelle' => 'Gestionnaire'],
            ['code' => 'membre', 'libelle' => 'Membre'],
            ['code' => 'benevole', 'libelle' => 'Bénévole'],
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

        $this->command?->info("Application 'familles' + rôles enregistrés dans amana_commun.");
    }
}
