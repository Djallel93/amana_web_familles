<?php
// database/seeders/FamilleCsvSeeder.php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\FamilleImportService;
use App\Support\FamilleCsvParser;
use Illuminate\Database\Seeder;

/**
 * Charge le CSV réel des familles (données personnelles — nom/adresse/
 * téléphone) depuis un fichier local gitignoré, jamais commité.
 *
 * Volontairement PAS appelé par DatabaseSeeder::run() (même raison que
 * FamilleSeeder : ne doit jamais tourner par accident sur un poste qui
 * n'a pas le fichier, ni en CI). À lancer explicitement :
 *
 *   php artisan db:seed --class=FamilleCsvSeeder
 *
 * Chemin du fichier surchargeable via FAMILLES_IMPORT_CSV_PATH dans .env,
 * sinon database/seeders/data/familles.csv par défaut.
 */
class FamilleCsvSeeder extends Seeder
{
    public function run(FamilleImportService $importService): void
    {
        $path = env('FAMILLES_IMPORT_CSV_PATH', database_path('seeders/data/familles.csv'));

        if (!is_file($path)) {
            $this->command->warn("⏭️  Fichier CSV introuvable ({$path}) — seeder ignoré.");
            return;
        }

        $lignes = FamilleCsvParser::parse($path);

        if (empty($lignes)) {
            $this->command->warn('⚠️  CSV vide ou illisible.');
            return;
        }

        $succes = 0;
        $erreurs = 0;

        foreach ($lignes as $i => $payload) {
            $resultat = $importService->traiterLigne($payload);

            if ($resultat['status'] === 'success') {
                $succes++;
            } elseif ($resultat['status'] === 'error') {
                $erreurs++;
                $this->command->error('Ligne ' . ($i + 1) . " : {$resultat['error_message']}");
            }
        }

        $this->command->info("✅ Import CSV terminé : {$succes} réussi(s), {$erreurs} en erreur.");
    }
}
