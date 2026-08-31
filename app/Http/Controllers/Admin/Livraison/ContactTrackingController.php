<?php
// app/Http/Controllers/Admin/Livraison/ContactTrackingController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Tableau de suivi des contacts téléphoniques (file d'appels, filtre par
 * gestionnaire assigné, statut_contact, vue "assigné à moi") — voir le
 * prompt du 30/08/2026 §7. Assignation de contact ("Assigner un contact
 * famille") réservée gestionnaire/admin, avec validation que la personne
 * assignée détient bien le rôle gestionnaire (§2, id_personne_assignee).
 *
 * SQUELETTE (Patch 1) — logique complète (file d'appels, validation du
 * rôle de l'assigné, exclusion des livraisons statut_contact != confirme
 * du pool de clustering) à écrire en Patch 2.
 */
class ContactTrackingController extends Controller
{
    public function index(): View
    {
        return view('livraison.a-venir', ['titre' => 'Suivi des contacts']);
    }
}
