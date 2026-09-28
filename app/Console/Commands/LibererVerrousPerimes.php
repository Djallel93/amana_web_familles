<?php
// app/Console/Commands/LibererVerrousPerimes.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Famille;
use Illuminate\Console\Command;

/**
 * Libère les verrous d'édition de dossiers périmés (plus vieux que
 * Famille::VERROU_TTL_MINUTES) et restaure le statut d'origine des
 * dossiers concernés — voir Famille::libererVerrousPerimes() pour les
 * règles exactes et le raisonnement. Planifiée toutes les 5 minutes dans
 * routes/console.php (cron IONOS confirmé actif, voir ce fichier).
 *
 *   php artisan familles:liberer-verrous-perimes
 */
class LibererVerrousPerimes extends Command
{
    protected $signature = 'familles:liberer-verrous-perimes';
    protected $description = "Libère les verrous d'édition de dossiers périmés et restaure leur statut d'origine";

    public function handle(): int
    {
        $liberes = Famille::libererVerrousPerimes();

        $this->info("Verrous périmés libérés : {$liberes}");

        return self::SUCCESS;
    }
}
