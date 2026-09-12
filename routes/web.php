<?php
// routes/web.php

declare(strict_types=1);

use Amana\Shared\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes web — AMANA Familles
|--------------------------------------------------------------------------
|
| Nommage cohérent avec amana_web_planning : login / logout /
| password.request / password.reset (AuthController), familles.index
| (vue principale, protégée par 'auth'), admin.personnes.* (protégée par
| 'role:admin').
|
| Ce fichier ne garde que l'authentification, commune à tout le reste —
| depuis le 10/09/2026 (Section E2 du refactor), le CRUD dossier famille,
| le domaine livraison, et l'administration vivent chacun dans leur propre
| fichier (voir les require ci-dessous), routes/web.php lui-même n'ayant
| plus vocation à grossir indéfiniment.
|
*/

// ── Authentification (SSO partagé via ref_personnes) ────────────────────
// Limites de débit alignées sur amana_web_planning\routes\web.php.
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])
    ->name('login.submit')
    ->middleware('throttle:10,1');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/mot-de-passe-oublie', [AuthController::class, 'showForgotPassword'])->name('password.request');
Route::post('/mot-de-passe-oublie', [AuthController::class, 'sendResetLink'])
    ->name('password.email')
    ->middleware('throttle:5,1');
Route::get('/reinitialiser-mot-de-passe/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
Route::post('/reinitialiser-mot-de-passe', [AuthController::class, 'resetPassword'])
    ->name('password.update')
    ->middleware('throttle:10,1');

require __DIR__ . '/familles.php';
require __DIR__ . '/livraison.php';
require __DIR__ . '/admin.php';
