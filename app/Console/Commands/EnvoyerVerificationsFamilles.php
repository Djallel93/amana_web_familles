<?php
// app/Console/Commands/EnvoyerVerificationsFamilles.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FamilleVerificationService;
use Illuminate\Console\Command;

/**
 * Équivalent CLI du bouton "Envoyer les vérifications" (admin.verifications.envoyer)
 * — permet de planifier l'envoi périodique (voir routes/console.php) plutôt
 * que de dépendre uniquement d'un déclenchement manuel par le staff.
 *
 *   php artisan familles:envoyer-verifications
 */
class EnvoyerVerificationsFamilles extends Command
{
    protected $signature = 'familles:envoyer-verifications';
    protected $description = "Envoie un email de vérification aux familles au dossier validé (décision 6.10)";

    public function handle(FamilleVerificationService $service): int
    {
        $resultats = $service->envoyerParLot();

        $this->info("Envoyés : {$resultats['envoyes']}");
        $this->line("Ignorés (déjà en cours/récemment confirmés) : {$resultats['ignores']}");
        if ($resultats['echecs'] > 0) {
            $this->warn("Échecs : {$resultats['echecs']} — voir les logs.");
        }

        return self::SUCCESS;
    }
}
