<?php
// app/Http/Controllers/Admin/Livraison/CampagnesController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Création/gestion des campagnes (admin/gestionnaire — accès "Full" sur
 * toute la ligne "Campagne" et "Sélection familles éligibles" de la
 * matrice de droits, voir le prompt du 30/08/2026 §4/§7).
 *
 * SQUELETTE (Patch 1 — fondations uniquement) : routes et contrôleur en
 * place pour valider que le groupage de rôles compile, mais aucune
 * logique métier (création de campagne, sélection des familles éligibles
 * par criticité/quartier/dernière livraison/organisation, génération des
 * livraisons) — à écrire en Patch 2.
 */
class CampagnesController extends Controller
{
    public function index(): View
    {
        return view('livraison.a-venir', ['titre' => 'Campagnes']);
    }
}
