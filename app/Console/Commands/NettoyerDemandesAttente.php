<?php
// app/Console/Commands/NettoyerDemandesAttente.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IntakeDemandeAttente;
use App\Services\IntakeAttenteService;
use Illuminate\Console\Command;

/**
 * Purge les soumissions du formulaire public d'intake restées non
 * confirmées au-delà de leur délai de validité (48h — voir
 * IntakeAttenteService::DUREE_VALIDITE_HEURES) : supprime à la fois la
 * ligne intake_demandes_attente et les fichiers temporaires associés sur le
 * disque 'local' (storage/app/private/intake-attente/{token}/), pour éviter
 * une fuite d'espace disque silencieuse (ajout du 11/08/2026).
 *
 * Une ligne confirmée est déjà supprimée immédiatement par
 * IntakeAttenteService::confirmer() — cette commande ne trouve donc jamais
 * que des demandes abandonnées (email jamais ouvert/cliqué, ou faux numéro
 * saisi).
 *
 *   php artisan familles:nettoyer-demandes-attente
 */
class NettoyerDemandesAttente extends Command
{
    protected $signature = 'familles:nettoyer-demandes-attente';
    protected $description = "Supprime les demandes d'intake non confirmées expirées (48h) et leurs fichiers temporaires";

    public function handle(IntakeAttenteService $service): int
    {
        $expirees = IntakeDemandeAttente::where('expires_at', '<', now())->get();

        foreach ($expirees as $demande) {
            $service->supprimerDemande($demande);
        }

        $this->info("Demandes expirées supprimées : {$expirees->count()}");

        return self::SUCCESS;
    }
}
