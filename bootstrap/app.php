<?php
// bootstrap/app.php
// Point d'entrée de l'application Laravel 13 — AMANA Familles

declare(strict_types=1);

use Amana\Shared\Http\Middleware\EnsureAuthenticated;
use Amana\Shared\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // ── Middlewares d'authentification (amana/shared) ──────────────────
        //
        // 'auth' : vérifie que l'utilisateur est connecté (SSO partagé via
        //          ref_personnes — même session/table que amana_web_planning).
        //
        // 'role' : vérifie qu'un utilisateur connecté possède le rôle requis
        //          dans l'application 'familles' (config('amana-shared.app_code')),
        //          scoping totalement indépendant des rôles Planning de la
        //          même personne). Usage :
        //            Route::middleware('role:admin')
        //            Route::middleware('role:gestionnaire')
        //          Un admin a automatiquement accès aux routes gestionnaire.
        //
        $middleware->alias([
            'auth' => EnsureAuthenticated::class,
            'role' => EnsureRole::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Pas de configuration particulière des exceptions pour l'instant
    })->create();
