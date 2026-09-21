<?php
// app/Services/NavBadges.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Contracts\NavBadgeProvider;
use App\Models\BenevoleProfil;
use App\Models\Famille;
use Illuminate\Support\Facades\Auth;

/**
 * Implémentation familles du contrat Amana\Shared\Contracts\NavBadgeProvider
 * (liée dans AppServiceProvider::register()) — voir ce contrat pour le pourquoi.
 *
 * Remplace le View::composer que AppServiceProvider::boot() enregistrait pour la
 * même vue : il ne fonctionnait que parce qu'il s'exécutait après celui du
 * paquet et écrasait sa clé (un accident, pas un contrat), et il faisait un
 * Schema::hasTable() à chaque rendu de la sidebar.
 *
 * Rafraîchissement en direct : ces compteurs sont ré-interrogés toutes les
 * 45 s par NavBadgesController (route 'nav-badges.index', routes/familles.php)
 * et mis en cache 10 s, PARTAGÉS entre utilisateurs. Ils sont globaux (aucun ne
 * dépend de l'utilisateur connecté) ; si un compteur devait en dépendre, mettre
 * 'nav_badges_cache_seconds' à 0 dans config/amana-shared.php.
 */
class NavBadges implements NavBadgeProvider
{
    public function counts(): array
    {
        // La sidebar partagée peut aussi être rendue sur les pages de connexion :
        // pas de requête inutile pour un invité.
        if (!Auth::check()) {
            return [];
        }

        return [
            // Badge « Nouvelles demandes » — dossiers etat_dossier = 'Recu' pas encore
            // ouverts par le staff (voir FamillesController::nouvelles()).
            'familles.nouvelles' => Famille::where('etat_dossier', 'Recu')->count(),
            // Badge « Candidatures bénévoles » — candidatures reçues pas encore traitées
            // (BenevoleProfil::pourRevueStaff(), utilisé aussi par
            // BenevoleCandidaturesController).
            'admin.benevoles.index' => BenevoleProfil::pourRevueStaff()->count(),
        ];
    }
}
