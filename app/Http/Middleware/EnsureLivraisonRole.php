<?php
// app/Http/Middleware/EnsureLivraisonRole.php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\CampagneEquipeMembre;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrôle d'accès pour les 4 rôles latéraux du domaine livraison
 * (equipe_reception, equipe_pesee, equipe_packaging, equipe_chargement) —
 * voir 2026_08_31_000000_register_livraison_roles.php.
 *
 * Middleware LOCAL à amana_web_familles, volontairement distinct de
 * Amana\Shared\Http\Middleware\EnsureRole (amana/shared) : ces 4 rôles
 * sont des concepts métier propres à cette app, ils n'ont rien à gagner à
 * être connus du paquet partagé — voir le prompt du 30/08/2026 §4
 * ("Do not add isEquipeReception()/etc... and do not add new cases to
 * amana_shared's EnsureRole middleware match statement"). Utilise
 * Personne::hasRole() directement, déjà correctement scopée par
 * config('amana-shared.app_code').
 *
 * Un admin ou un gestionnaire passe toujours (accès "Full" sur toute la
 * matrice des droits du prompt §4) — pas besoin de leur attribuer un rôle
 * équipe_* en plus. Un gestionnaire qui rejoint une équipe physiquement
 * garde son accès gestionnaire complet, les rôles sont additifs (voir §4).
 *
 * Bypass étendu le 08/09/2026 (prompt de cette date) : en plus du rôle
 * global (Personne::hasRole()), une personne affectée à CE rôle sur au
 * moins une campagne via campagne_equipe_membres passe aussi désormais
 * — voir CampagneEquipeMembre::estAffecteQuelquePart(). Nécessaire
 * depuis que le picker de l'écran d'admin n'est pas restreint aux
 * détenteurs du rôle global (décision du même jour) : sans ce second
 * bypass, quelqu'un affecté uniquement via la nouvelle table (jamais eu
 * la case equipe_* cochée sur son profil) se ferait bloquer par ce
 * middleware avant même d'atteindre choisir(), qui pourtant listerait
 * correctement sa campagne (voir
 * Http\Controllers\Livraison\Concerns\FiltreCampagnesEquipe).
 *
 * Usage dans routes/web.php :
 *   Route::middleware('livraison_role:equipe_reception')
 *   Route::middleware('livraison_role:equipe_pesee')
 *   Route::middleware('livraison_role:equipe_packaging')
 *   Route::middleware('livraison_role:equipe_chargement')
 */
class EnsureLivraisonRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        /** @var \Amana\Shared\Models\Personne $personne */
        $personne = Auth::user();

        $autorise = $personne->isAdmin()
            || $personne->isGestionnaire()
            || $personne->hasRole($role)
            || CampagneEquipeMembre::estAffecteQuelquePart($personne->id, $role);

        if (!$autorise) {
            return redirect()->route(config('amana-shared.home_route'))
                ->with('error', 'Vous n\'avez pas les permissions nécessaires pour accéder à cette page.');
        }

        return $next($request);
    }
}
