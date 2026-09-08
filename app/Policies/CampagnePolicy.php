<?php
// app/Policies/CampagnePolicy.php

declare(strict_types=1);

namespace App\Policies;

use Amana\Shared\Models\Personne;
use App\Models\Campagne;
use App\Policies\Concerns\AutoriseEquipeCampagne;

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
 *
 * autoriseEquipe() extrait dans App\Policies\Concerns\AutoriseEquipeCampagne
 * le 08/09/2026 (câblage routes/web.php, prompt du même jour) : les 5
 * nouvelles policies des sous-ressources livraison (CampagneArrivee,
 * Donation, LivraisonColis, Livraison, RouteLivraison — voir chacune
 * pour le chemin de résolution vers sa Campagne ; PAS EtapeRoute, dont
 * les routes {etape} vivent toutes sous role:benevole, jamais sous un
 * groupe equipe_*, voir RouteLivraisonPolicy) ont exactement le même
 * bypass à appliquer une fois leur propre Campagne résolue — un trait
 * partagé plutôt que dupliquer ces 3 lignes dans 5 classes de plus.
 */
final class CampagnePolicy
{
    use AutoriseEquipeCampagne;

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
}
