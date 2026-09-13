<?php
// app/Http/Middleware/HandleInertiaRequests.php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Middleware standard Inertia — Section E4 du refactor (12/09/2026),
 * ajouté au groupe 'web' (voir bootstrap/app.php) pour toute l'app, mais
 * n'a d'effet que sur les routes dont le contrôleur renvoie
 * Inertia::render(...) ; les routes qui renvoient encore une View Blade
 * classique (l'immense majorité de l'app tant que la migration
 * incrémentale n'est pas terminée, voir le prompt de cette section)
 * traversent ce middleware sans rien y voir de différent.
 *
 * $rootView reste 'app' (défaut du package) — voir la nouvelle
 * resources/views/app.blade.php, qui réutilise les mêmes partials
 * vendor (amana-shared::layouts.partials.head/sidebar/flash) que
 * layouts/app.blade.php pour un shell visuellement identique. Ce dernier
 * n'est PAS remplacé : toutes les vues non encore migrées continuent de
 * l'@extends comme aujourd'hui.
 *
 * share() : uniquement 'auth'/'flash' pour l'instant — le strict minimum
 * qu'une page Inertia a besoin de connaître indépendamment de ses props
 * explicites. 'flash' est partagé ici pour cohérence avec le pattern
 * Inertia standard, mais n'a pas encore de consommateur côté Vue dans ce
 * chunk : aucune redirection avec session flash ne cible actuellement
 * familles.index/familles.nouvelles (vérifié par recherche dans app/) —
 * à traiter quand une page migrée en dépendra réellement, plutôt que de
 * construire ici un mécanisme d'affichage flash côté Vue sans
 * consommateur pour le justifier.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'nom' => $request->user()->nom,
                    'prenom' => $request->user()->prenom,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],
        ];
    }
}
