<?php
// app/Console/Commands/PromouvoirTourneesPretes.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RouteLivraison;
use App\Services\RouteChargementService;
use Illuminate\Console\Command;

/**
 * Rattrapage (01/10/2026) des tournées restées 'planifiee' alors que tous
 * leurs colis étaient déjà prêts au moment de leur génération : avant
 * l'extraction de RouteChargementService, rien ne les basculait jamais en
 * 'chargement' et elles n'apparaissaient donc pas sur l'écran chargement.
 * Les nouvelles tournées sont désormais promues à la création — cette
 * commande ne sert qu'une fois, pour les données existantes.
 *
 *   php artisan livraison:promouvoir-tournees-pretes
 */
class PromouvoirTourneesPretes extends Command
{
    protected $signature = 'livraison:promouvoir-tournees-pretes';

    protected $description = "Bascule en 'chargement' les tournées 'planifiee' dont tous les colis sont déjà prêts";

    public function handle(RouteChargementService $service): int
    {
        $promues = 0;

        RouteLivraison::where('statut', 'planifiee')->each(function (RouteLivraison $route) use ($service, &$promues) {
            if ($service->promouvoirSiPrete($route)) {
                $promues++;
            }
        });

        $this->info("Tournées basculées en chargement : {$promues}");

        return self::SUCCESS;
    }
}
