<?php

namespace App\Providers;

use Amana\Shared\Contracts\ActivityStatisticsProvider;
use Amana\Shared\Contracts\NavBadgeProvider;
use Amana\Shared\Contracts\ProfileExtension;
use App\Services\AuditStatistics;
use App\Services\BenevoleProfileExtension;
use App\Services\NavBadges;
use Illuminate\Http\Resources\Json\JsonResource;
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

        // Régression Inertia (section E4 du refactor, 22/09/2026) : l'adaptateur
        // Inertia force `->toResponse($request)->getData(true)` sur toute instance
        // JsonResource/ResourceCollection rencontrée en résolvant les props — y
        // compris une ResourceCollection passée directement en prop
        // (CampagnesController/ContactTrackingController/LiveBoardController/
        // BenevoleDisponibiliteController/EquipeMembresController/
        // StatistiquesController) ET une Resource isolée nichée dans un tableau
        // par ailleurs simple (FamillesController::baseQuery() : la collection
        // du paginator transformée en FamilleListItemResource par item). Dans
        // les deux cas, ça applique l'enveloppe `data` par défaut de Laravel, ce
        // que le front (qui attend un tableau/objet plat) ne gère pas — d'où les
        // pages Livraison qui ne rendent rien et Dossier Familles/Nouvelles dont
        // chaque ligne arrivait en double `{ data: { data: {...} } }`. Aucune
        // route JSON classique (apiPost/apiGet du domaine livraison,
        // store()/update() qui renvoient une Resource nichée dans un tableau
        // simple via response()->json()) ne dépend de cette enveloppe : elle
        // n'était de toute façon jamais appliquée à ce niveau-là (le wrapping ne
        // s'applique que via toResponse(), jamais à une Resource simplement
        // nichée dans un tableau renvoyé par une réponse JSON classique).
        JsonResource::withoutWrapping();
    }
}
