<?php
// app/Http/Controllers/Livraison/ContactConfirmationController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Formulaire public de confirmation famille — aucune authentification,
 * accès scopé par jeton (contact_tokens), voir le prompt du 30/08/2026
 * §2/§3.1/§7. Le jeton doit résoudre STRICTEMENT la livraison associée,
 * jamais d'autre donnée famille.
 *
 * SQUELETTE (Patch 1) — résolution/validation du jeton (hash, expiration,
 * usage unique) et traitement du formulaire (adresse, membres du foyer,
 * creneaux_disponibles) à écrire en Patch 2. Même schéma de throttle que
 * les autres routes publiques de l'app (voir routes/web.php).
 */
class ContactConfirmationController extends Controller
{
    public function show(string $token): View
    {
        return view('livraison.a-venir', ['titre' => 'Confirmation de disponibilité']);
    }
}
