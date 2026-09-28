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
 * share() : 'auth'/'flash' depuis la création de ce middleware (12/09/2026),
 * plus 'old' depuis le 16/09/2026 (Section E4, chunk settings) — premier
 * et seul consommateur à ce jour : resources/js/pages/Settings/Index.vue,
 * dont les sept formulaires classiques (POST natif, pas de router.post())
 * s'appuient sur le repli automatique withInput() de Laravel après une
 * ValidationException pour réafficher la saisie précédente en cas
 * d'échec — mécanisme distinct du partage 'errors' déjà inclus
 * automatiquement par la classe Middleware parente (...parent::share()),
 * qui ne couvre PAS old(). 'flash' reste sans consommateur (voir
 * ci-dessous) — 'old' ne doit pas non plus être considéré comme un
 * précédent pour ajouter des clés spéculatives : ajouté ici précisément
 * parce qu'un consommateur réel existe dans ce même chunk.
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
            // Ajouté le 16/09/2026 (Section E4, chunk settings) — voir le
            // docblock de classe. getOldInput() sans clé retourne
            // l'intégralité du tableau flashé par withInput() (imbriqué :
            // ['settings' => [...], 'vehicules' => [...], 'code' => ...,
            // 'nom' => ..., 'adresse' => ...] selon quel formulaire a
            // échoué), jamais null — un tableau vide hors contexte
            // d'échec de validation.
            'old' => fn () => $request->session()->getOldInput(),
        ];
    }
}
