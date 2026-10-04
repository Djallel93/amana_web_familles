<?php
// app/Http/Middleware/EnsurePersonneActive.php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PersonneDesactivee;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coupe l'accès d'une personne désactivée pour Familles (03/10/2026, voir
 * App\Models\PersonneDesactivee). Ajouté au groupe 'web' entier
 * (bootstrap/app.php) plutôt qu'aux routes protégées une à une : une
 * session déjà ouverte est donc fermée dès sa PROCHAINE requête, et une
 * connexion réussie (le contrôleur de connexion vit dans amana/shared et
 * ne connaît pas ce flag) est défaite immédiatement par la redirection qui
 * suit, avec le message ci-dessous sur l'écran de connexion.
 *
 * Une requête par appel, volontairement non mise en cache : un cache
 * retarderait la coupure d'accès.
 */
class EnsurePersonneActive
{
    public const MESSAGE = 'Votre compte a été désactivé pour AMANA Familles. Contactez un administrateur.';

    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = Auth::user();

        if ($utilisateur && PersonneDesactivee::estDesactivee((int) $utilisateur->getAuthIdentifier())) {
            Auth::logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => self::MESSAGE], 403);
            }

            return redirect()->route('login')->with('error', self::MESSAGE);
        }

        return $next($request);
    }
}
