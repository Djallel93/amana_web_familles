<?php
// app/Policies/RouteLivraisonPolicy.php

declare(strict_types=1);

namespace App\Policies;

use Amana\Shared\Models\Personne;
use App\Models\RouteLivraison;
use App\Policies\Concerns\AutoriseEquipeCampagne;

/**
 * Autorisation equipe_chargement sur une RouteLivraison précise (routes
 * POST livraison/chargement/routes/{route}/confirmer, /benevole-absent
 * et /capacite, voir routes/web.php) — RouteLivraison::campagne() est une
 * relation directe (id_campagne).
 *
 * NE couvre PAS les routes DELETE .../routes/{route}/etapes/{etape}
 * (Admin\Livraison\LiveBoardController::retirerLivraison, groupe
 * role:gestionnaire) ni POST livraison/benevole/routes/{route}/... et
 * etapes/{etape}/... (App\Http\Controllers\Livraison\MaRouteController,
 * groupe role:benevole — la tournée du bénévole lui-même, un contrôle
 * d'accès entièrement différent, hors périmètre de ce patch) : aucune de
 * ces routes n'est dans un groupe equipe_chargement, contrairement à ce
 * que supposait le prompt du 07/09/2026 pour etapes/{etape} — vérifié en
 * relisant routes/web.php avant d'écrire cette policy plutôt que de créer
 * un EtapeRoutePolicy qui n'aurait servi à rien.
 */
final class RouteLivraisonPolicy
{
    use AutoriseEquipeCampagne;

    public function gerer(Personne $personne, RouteLivraison $route): bool
    {
        return $this->autoriseEquipe($personne, $route->campagne, 'equipe_chargement');
    }
}
