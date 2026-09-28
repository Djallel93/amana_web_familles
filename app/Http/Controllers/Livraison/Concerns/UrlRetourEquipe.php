<?php
// app/Http/Controllers/Livraison/Concerns/UrlRetourEquipe.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison\Concerns;

use App\Models\Campagne;

/**
 * Lien "Retour à la campagne" des écrans de poste équipe (pesée/réception
 * — voir PosteReleveController::show() —, ChargementController,
 * PackagingController) — extrait en trait le 10/09/2026 (Section B du
 * refactor), les 3 implémentations étant identiques.
 *
 * Un compte gestionnaire/admin (le plus courant en pratique, voir
 * EnsureLivraisonRole) a accès à livraison.campagnes.show — y renvoyer
 * directement plutôt qu'à choisir() (05/09/2026, correction : "I expect
 * to go back to /livraison/campagnes/{id}"). Un compte equipe_* PUR n'a
 * lui accès qu'à choisir() — voir la matrice de droits, ces comptes ne
 * peuvent pas voir la fiche campagne elle-même.
 */
trait UrlRetourEquipe
{
    private function urlRetourEquipe(Campagne $campagne, string $routeChoisir): string
    {
        return (auth()->user()->isAdmin() || auth()->user()->isGestionnaire())
            ? route('livraison.campagnes.show', $campagne)
            : route($routeChoisir);
    }
}
