<?php

namespace App\Providers;

use Amana\Shared\Contracts\ActivityStatisticsProvider;
use App\Models\Famille;
use App\Services\AuditStatistics;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Badge "Nouvelles demandes" dans la sidebar — nombre de dossiers
        // etat_dossier = 'Recu' pas encore ouverts par le staff (voir
        // FamillesController::nouvelles(), config/amana-shared.php 'nav').
        // Schema::hasTable() : évite une erreur avant la première migration
        // (ex. pendant `composer install` en CI). Auth::check() : la
        // sidebar partagée est aussi rendue sur les pages de connexion.
        $this->app['view']->composer('amana-shared::layouts.partials.sidebar', function (View $view) {
            if (!Auth::check() || !Schema::hasTable('familles')) {
                return;
            }

            $view->with('navBadges', [
                'familles.nouvelles' => Famille::where('etat_dossier', 'Recu')->count(),
            ]);
        });
    }
}
