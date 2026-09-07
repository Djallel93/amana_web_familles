<?php
// app/Policies/CampagnePolicy.php

declare(strict_types=1);

namespace App\Policies;

use Amana\Shared\Models\Personne;
use App\Models\Campagne;

/**
 * Autorisation des actions équipe_* PROPRES à une campagne précise (ex:
 * saisir une pesée sur la campagne_1) — voir
 * create_campagne_equipe_membres_table.php pour le raisonnement complet.
 *
 * Découverte automatique par Laravel (App\Models\Campagne →
 * App\Policies\CampagnePolicy, convention de nommage, pas
 * d'enregistrement explicite nécessaire depuis la suppression
 * d'AuthServiceProvider — voir Illuminate\Auth\Access\Gate::guessPolicyName()).
 *
 * Une méthode par rôle plutôt qu'une seule méthode générique
 * `equipe(Personne, Campagne, string $role)` : les policies Laravel sont
 * appelées par leur nom d'ability (`$this->authorize('equipePesee', ...)`
 * / `can:equipePesee,campagne` en middleware / `@can('equipePesee', ...)`
 * en Blade), un paramètre $role supplémentaire ne serait pas exprimable
 * proprement dans ces trois usages sans détour (closure, route model
 * binding détourné...) — 4 méthodes minces déléguant à autoriseEquipe()
 * reste le plus simple des trois callsites.
 *
 * Bypass admin/gestionnaire identique à
 * App\Http\Middleware\EnsureLivraisonRole (même matrice de droits, voir
 * son docblock) — cette policy est destinée à REMPLACER ce middleware sur
 * les routes qui reçoivent directement {campagne} en paramètre de route,
 * pas à s'y ajouter (voir le prompt du 07/09/2026, câblage routes/web.php
 * à faire dans un patch séparé une fois campagne_equipe_membres peuplée
 * par un écran d'admin — sans ça, retirer EnsureLivraisonRole des routes
 * existantes bloquerait immédiatement toute personne ayant le rôle
 * global equipe_* mais pas encore affectée à une campagne via cette
 * nouvelle table).
 */
final class CampagnePolicy
{
    public function equipeReception(Personne $personne, Campagne $campagne): bool
    {
        return $this->autoriseEquipe($personne, $campagne, 'equipe_reception');
    }

    public function equipePesee(Personne $personne, Campagne $campagne): bool
    {
        return $this->autoriseEquipe($personne, $campagne, 'equipe_pesee');
    }

    public function equipePackaging(Personne $personne, Campagne $campagne): bool
    {
        return $this->autoriseEquipe($personne, $campagne, 'equipe_packaging');
    }

    public function equipeChargement(Personne $personne, Campagne $campagne): bool
    {
        return $this->autoriseEquipe($personne, $campagne, 'equipe_chargement');
    }

    private function autoriseEquipe(Personne $personne, Campagne $campagne, string $role): bool
    {
        return $personne->isAdmin()
            || $personne->isGestionnaire()
            || $campagne->aRole($personne->id, $role);
    }
}
