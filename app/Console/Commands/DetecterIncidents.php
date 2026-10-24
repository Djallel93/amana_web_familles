<?php
// app/Console/Commands/DetecterIncidents.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Campagne;
use App\Services\IncidentDetectionService;
use Illuminate\Console\Command;

/**
 * Détection automatique des incidents des campagnes en cours (23/10/2026) — voir
 * IncidentDetectionService et docs/incidents.md. Planifiée toutes les 5 minutes (routes/console.php),
 * lançable à la main :
 *
 *   php artisan livraison:detecter-incidents            # toutes les campagnes en cours
 *   php artisan livraison:detecter-incidents --campagne=12
 */
class DetecterIncidents extends Command
{
    protected $signature = 'livraison:detecter-incidents {--campagne= : Id d\'une campagne précise (quel que soit son statut)}';

    protected $description = 'Détecte les incidents évitables (famille non localisée, tournée en retard, collecte insuffisante…) des campagnes en cours';

    public function handle(IncidentDetectionService $service): int
    {
        $requete = Campagne::query();
        $this->option('campagne') !== null
            ? $requete->whereKey((int) $this->option('campagne'))
            : $requete->where('statut', 'en_cours');

        $crees = $autoResolus = 0;
        foreach ($requete->get() as $campagne) {
            $resultat = $service->detecter($campagne);
            $crees += $resultat['crees'];
            $autoResolus += $resultat['auto_resolus'];
        }

        $this->info("Incidents créés : {$crees} · résolus automatiquement : {$autoResolus}");

        return self::SUCCESS;
    }
}
