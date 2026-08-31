<?php
// app/Http/Controllers/Livraison/DisponibiliteController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Page de disponibilité bénévole (véhicule, coverage/secteurs, créneaux)
 * — compte propre, scopée à une campagne, éditable à tout moment après la
 * confirmation initiale (pas de flux "renvoyer le formulaire") — voir le
 * prompt du 30/08/2026 §3.2/§7.
 *
 * SQUELETTE (Patch 1) — écriture/lecture de benevole_disponibilites +
 * benevole_disponibilite_creneaux, et l'email de notification au
 * lancement de campagne, à écrire en Patch 2.
 */
class DisponibiliteController extends Controller
{
    public function show(): View
    {
        return view('livraison.a-venir', ['titre' => 'Ma disponibilité']);
    }
}
