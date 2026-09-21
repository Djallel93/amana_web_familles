<?php

namespace App\Providers;

use Amana\Shared\Contracts\ActivityStatisticsProvider;
use Amana\Shared\Contracts\NavBadgeProvider;
use Amana\Shared\Contracts\ProfileExtension;
use App\Services\AuditStatistics;
use App\Services\BenevoleProfileExtension;
use App\Services\NavBadges;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Lie l'implémentation familles au contrat consommé par
        // Amana\Shared\Http\Controllers\ActivityStatsController (partagé).
        $this->app->bind(ActivityStatisticsProvider::class, AuditStatistics::class);

        // Badges numériques de la sidebar (« Nouvelles demandes », « Candidatures
        // bénévoles »), rafraîchis en direct via la route nav-badges.index.
        $this->app->bind(NavBadgeProvider::class, NavBadges::class);

        // Section « Informations bénévole » de la page « Mon profil » (amana/shared) :
        // langue, permis, véhicule, secteurs — seulement pour une personne qui a un
        // profil bénévole (sinon la section n'apparaît pas).
        $this->app->bind(ProfileExtension::class, BenevoleProfileExtension::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rien à amorcer ici : les badges de la sidebar passent par le contrat
        // NavBadgeProvider (lié dans register(), implémenté par App\Services\NavBadges).
    }
}
